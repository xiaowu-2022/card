<?php

namespace App\Infrastructure\Providers\Blockchain;

use App\Application\Assets\TronDepositConfiguration;
use App\Domain\Payment\Contracts\Trc20ChainReader;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Domain\Withdrawal\DTOs\BlockchainTransferVerification;
use App\Domain\Withdrawal\DTOs\IncomingBlockchainTransfer;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Read-only mainnet USDT. Never signs, posts Ledger or falls back to Mock. */
final class TronGridBlockchainGateway implements BlockchainGatewayInterface, Trc20ChainReader
{
    public const TOKEN = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    private const TOKEN_HEX = 'a614f803b6fd780986a42c78ec9c7f77e6ded13c';

    private const TRANSFER = 'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    private const BASE = 'https://api.trongrid.io';

    public function available(): bool
    {
        try {
            return $this->addressHex(app(TronDepositConfiguration::class)->address()) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public function verifyUsdtTrc20Transfer(string $txHash, string $expectedAddress, string $expectedAmount): BlockchainTransferVerification
    {
        // This approval adds incoming verification only; do not silently enable real withdrawals.
        throw new DomainException('BLOCKCHAIN_VERIFICATION_UNAVAILABLE', 'Blockchain verification is currently unavailable.', 503);
    }

    public function listIncomingUsdtTrc20Transfers(string $destination): array
    {
        // Real scans must use the persisted, explicitly activated scan window.
        throw new DomainException('TRC20_SCAN_WINDOW_REQUIRED', 'A bounded scan window is required.', 503);
    }

    public function confirmedThrough(): DateTimeImmutable
    {
        $head = $this->request('walletsolidity/getnowblock');
        $height = $head['block_header']['raw_data']['number'] ?? null;
        if (! is_int($height) || $height < 1) {
            $this->unavailable();
        }
        $number = $height - max(1, (int) config('payment.trc20_required_confirmations')) + 1;
        if ($number < 1) {
            $this->unavailable();
        }
        $block = $this->request('walletsolidity/getblockbynum', ['num' => $number]);
        if (($block['block_header']['raw_data']['number'] ?? null) !== $number) {
            $this->unavailable();
        }

        return $this->timestamp($block['block_header']['raw_data']['timestamp'] ?? null);
    }

    public function between(string $destination, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($this->addressHex($destination) === null || $from > $to) {
            $this->unavailable();
        }
        $params = ['only_confirmed' => 'true', 'only_to' => 'true', 'limit' => 200,
            'order_by' => 'block_timestamp,asc', 'contract_address' => self::TOKEN,
            'min_timestamp' => $from->format('Uv'), 'max_timestamp' => $to->format('Uv')];
        $hashes = [];
        $fingerprints = [];
        for ($page = 0; $page < 10; $page++) {
            $response = $this->request('v1/accounts/'.$destination.'/transactions/trc20', $params, true);
            if (($response['success'] ?? null) !== true || ! is_array($response['data'] ?? null)) {
                $this->unavailable();
            }
            foreach ($response['data'] as $row) {
                $hash = $row['transaction_id'] ?? null;
                if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/i', $hash)) {
                    $this->unavailable();
                }
                $hashes[strtolower($hash)] = true;
            }
            $fingerprint = $response['meta']['fingerprint'] ?? null;
            if (! $fingerprint) {
                break;
            }
            if (! is_string($fingerprint) || strlen($fingerprint) > 2048 || isset($fingerprints[$fingerprint]) || $page === 9) {
                $this->unavailable();
            }
            $fingerprints[$fingerprint] = true;
            // Never follow an upstream next URL (SSRF); use only its opaque pagination token.
            $params['fingerprint'] = $fingerprint;
        }
        $transfers = [];
        foreach (array_keys($hashes) as $hash) {
            foreach ($this->lookup($hash, $destination) as $transfer) {
                if ($transfer->occurredAt >= $from && $transfer->occurredAt <= $to) {
                    $transfers[] = $transfer;
                }
            }
        }

        return $transfers;
    }

    public function lookup(string $txHash, string $destination): array
    {
        $recipient = $this->addressHex($destination);
        if ($recipient === null || ! preg_match('/^[a-f0-9]{64}$/i', $txHash)) {
            $this->unavailable();
        }
        $txHash = strtolower($txHash);
        $receipt = $this->request('walletsolidity/gettransactioninfobyid', ['value' => $txHash]);
        if ($receipt === []) {
            $this->unavailable();
        } // Not yet solidified is uncertain, never failed.
        if (($receipt['id'] ?? null) !== $txHash) {
            $this->unavailable();
        }
        if (($receipt['receipt']['result'] ?? null) !== 'SUCCESS') {
            $this->unavailable();
        }
        $block = $receipt['blockNumber'] ?? null;
        $time = $this->timestamp($receipt['blockTimeStamp'] ?? null);
        $head = $this->request('walletsolidity/getnowblock');
        $height = $head['block_header']['raw_data']['number'] ?? null;
        if (! is_int($block) || ! is_int($height) || $block < 1 || $height < $block || ! is_array($receipt['log'] ?? null)) {
            $this->unavailable();
        }
        $transfers = [];
        foreach ($receipt['log'] as $index => $log) {
            if (! is_int($index) || ! is_array($log)) {
                $this->unavailable();
            }
            if (strtolower((string) ($log['address'] ?? '')) !== self::TOKEN_HEX) {
                continue;
            }
            $topics = $log['topics'] ?? [];
            if (! is_array($topics)) {
                $this->unavailable();
            }
            if (strtolower((string) ($topics[0] ?? '')) !== self::TRANSFER) {
                continue;
            }
            if (count($topics) !== 3 || ! preg_match('/^0{24}[a-f0-9]{40}$/i', (string) $topics[1])
                || ! preg_match('/^0{24}[a-f0-9]{40}$/i', (string) $topics[2])
                || ! preg_match('/^[a-f0-9]{64}$/i', (string) ($log['data'] ?? ''))) {
                $this->unavailable();
            }
            if (strtolower(substr($topics[2], 24)) !== $recipient) {
                continue;
            }
            $units = BigInteger::fromBase($log['data'], 16);
            $amount = (string) BigDecimal::of((string) $units)->dividedBy('1000000', 8);
            $transfers[] = new IncomingBlockchainTransfer('TRON', $txHash, $index, self::TOKEN,
                $destination, $amount, $height - $block + 1, $time);
        }

        return $transfers;
    }

    /** Base58Check decode, including network byte and double-SHA256 checksum. */
    private function addressHex(string $address): ?string
    {
        if (! preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
            return null;
        }
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $number = BigInteger::zero();
        foreach (str_split($address) as $char) {
            $number = $number->multipliedBy(58)->plus(strpos($alphabet, $char));
        }
        $hex = str_pad($number->toBase(16), 50, '0', STR_PAD_LEFT);
        if (strlen($hex) !== 50 || ! str_starts_with($hex, '41')) {
            return null;
        }
        $payload = hex2bin(substr($hex, 0, 42));
        if (! hash_equals(substr(hash('sha256', hash('sha256', $payload, true)), 0, 8), substr($hex, 42))) {
            return null;
        }

        return substr($hex, 2, 40);
    }

    private function timestamp(mixed $value): DateTimeImmutable
    {
        if (! is_int($value) || $value < 1) {
            $this->unavailable();
        }

        return (new DateTimeImmutable('@'.intdiv($value, 1000)))->modify('+'.($value % 1000).' milliseconds');
    }

    private function request(string $path, array $data = [], bool $get = false): array
    {
        if (! $this->available()) {
            $this->unavailable();
        }
        $started = hrtime(true);
        $phase = 'transport';
        $status = null;
        try {
            // Public read-only access: never decrypt or forward stored credentials.
            $client = Http::acceptJson()->connectTimeout(3)->timeout(10)->withoutRedirecting();
            $response = $get ? $client->get(self::BASE.'/'.$path, $data) : $client->post(self::BASE.'/'.$path, $data);
            $status = $response->status();
            $phase = 'http_status';
            if (! $response->successful()) {
                $this->unavailable();
            }
            $phase = 'response_size';
            if (strlen($response->body()) > 2097152) {
                $this->unavailable();
            }
            $phase = 'response_json';
            $json = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($json)) {
                $this->unavailable();
            }
            $phase = 'upstream_error';
            if (isset($json['Error']) || isset($json['error'])) {
                $this->unavailable();
            }

            return $json;
        } catch (Throwable $error) {
            // Never include request headers, upstream bodies or credentials in exceptions/logs.
            $endpoint = match ($path) {
                'walletsolidity/getnowblock' => 'solid_head',
                'walletsolidity/getblockbynum' => 'solid_block',
                'walletsolidity/gettransactioninfobyid' => 'transaction_receipt',
                default => str_starts_with($path, 'v1/accounts/') ? 'account_transfers' : 'unknown',
            };
            $errno = null;
            if ($phase === 'transport') {
                for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                    if (($cause instanceof ConnectException || $cause instanceof ConnectionException)
                        && preg_match('/\bcURL error ([0-9]{1,3}):/', $cause->getMessage(), $match) === 1) {
                        $value = (int) $match[1];
                        $errno = $value > 0 && $value <= 100 ? $value : null;
                        break;
                    }
                }
            }
            try {
                Log::warning('TRC20 public reader request failed', [
                    'endpoint' => $endpoint, 'phase' => $phase, 'http_status' => $status,
                    'elapsed_ms' => (int) ((hrtime(true) - $started) / 1000000),
                    'transport_errno' => $errno,
                ]);
            } catch (Throwable) {
                // A logging failure must not replace the safe error or permit settlement.
            }
            $this->unavailable();
        }
    }

    private function unavailable(): never
    {
        throw new DomainException('BLOCKCHAIN_MONITOR_UNAVAILABLE', 'Blockchain verification is temporarily unavailable. No payment was confirmed.', 503);
    }
}
