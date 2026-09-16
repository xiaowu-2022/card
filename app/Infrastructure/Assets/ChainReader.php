<?php

namespace App\Infrastructure\Assets;

use App\Domain\Assets\ChainConnection;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;

/** Read-only mainnet proofs. No signing, sending, private keys or accounting. */
final readonly class ChainReader
{
    public function __construct(private ChainRpc $rpc) {}

    private function rpc(ChainConnection $c, string $method, array $params = []): mixed
    {
        return $this->rpc->call($c, $method, $params);
    }

    private function integer(string $hex): BigInteger
    {
        if (! preg_match('/^0x[0-9a-f]+$/i', $hex)) {
            throw new \UnexpectedValueException('Invalid quantity');
        }

        return BigInteger::fromBase(substr($hex, 2), 16);
    }

    private function height(string $hex): int
    {
        return $this->integer($hex)->toInt();
    }

    private function amount(string $hex, int $scale): string
    {
        return (string) $this->integer($hex)->toBigDecimal()->withPointMovedLeft($scale)->toScale($scale);
    }

    public function finalHeight(ChainConnection $c): int
    {
        if ($c->network === 'ETHEREUM') {
            if ($this->rpc($c, 'eth_chainId') !== '0x1') {
                throw new DomainException('CHAIN_WRONG_NETWORK', 'The configured network does not match.', 503);
            }

            return $this->height($this->rpc($c, 'eth_getBlockByNumber', ['finalized', false])['number']);
        }
        $info = $this->rpc($c, 'getblockchaininfo');
        if ($info['chain'] !== 'main' || ($info['initialblockdownload'] ?? true)) {
            throw new DomainException('CHAIN_WRONG_NETWORK', 'The configured network does not match.', 503);
        }

        return max(-1, (int) $info['blocks'] - max(6, $c->confirmations) + 1);
    }

    public function hash(ChainConnection $c, int $height): string
    {
        return $c->network === 'ETHEREUM' ? $this->rpc($c, 'eth_getBlockByNumber', ['0x'.dechex($height), false])['hash'] : $this->rpc($c, 'getblockhash', [$height]);
    }

    /** @return array{hash:string,transfers:array} Each transfer has a stable position within its transaction. */
    public function block(ChainConnection $c, int $height): array
    {
        if ($c->network === 'BITCOIN') {
            return $this->bitcoin($c, $height);
        }
        $block = $this->rpc($c, 'eth_getBlockByNumber', ['0x'.dechex($height), true]);
        $time = CarbonImmutable::createFromTimestampUTC($this->height($block['timestamp']));
        $transfers = [];
        // Require complete call traces before advancing: silently ignoring internal ETH is unsafe.
        $traces = $this->rpc($c, 'debug_traceBlockByNumber', ['0x'.dechex($height), ['tracer' => 'callTracer']]);
        if (count($traces) !== count($block['transactions'])) {
            throw new DomainException('CHAIN_UNAVAILABLE', 'Complete network evidence is unavailable.', 503);
        }
        foreach ($block['transactions'] as $index => $tx) {
            $receipt = $this->rpc($c, 'eth_getTransactionReceipt', [$tx['hash']]);
            if (($receipt['blockHash'] ?? null) !== $block['hash'] || ($receipt['transactionHash'] ?? null) !== $tx['hash']) {
                throw new DomainException('CHAIN_REORG', 'Network confirmation changed.', 409);
            }
            if (($receipt['status'] ?? null) !== '0x1') {
                continue;
            }
            foreach ($receipt['logs'] as $log) {
                if (($log['removed'] ?? false) || count($log['topics']) !== 3 || strtolower($log['topics'][0]) !== '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef') {
                    continue;
                }
                if (! preg_match('/^0x[0-9a-f]{64}$/i', $log['topics'][2]) || ! preg_match('/^0x[0-9a-f]{64}$/i', $log['data'])) {
                    continue;
                }
                $transfers[] = ['event_id' => strtolower($tx['hash']).':log:'.$this->height($log['logIndex']), 'tx_hash' => strtolower($tx['hash']), 'contract' => strtolower($log['address']), 'address' => '0x'.strtolower(substr($log['topics'][2], -40)), 'amount' => $this->amount($log['data'], 6), 'occurred_at' => $time];
            }
            if (($traces[$index]['txHash'] ?? $tx['hash']) !== $tx['hash'] || ! isset($traces[$index]['result'])) {
                throw new DomainException('CHAIN_UNAVAILABLE', 'Complete network evidence is unavailable.', 503);
            }
            $this->calls($traces[$index]['result'], '0', strtolower($tx['hash']), $time, $transfers);
        }
        if ($this->hash($c, $height) !== $block['hash']) {
            throw new DomainException('CHAIN_REORG', 'Network confirmation changed.', 409);
        }

        return ['hash' => $block['hash'], 'transfers' => $transfers];
    }

    private function calls(array $call, string $path, string $tx, CarbonImmutable $time, array &$transfers): void
    {
        if (isset($call['error'])) {
            return;
        }
        if (in_array(strtoupper($call['type'] ?? ''), ['CALL', 'CREATE', 'CREATE2', 'SELFDESTRUCT'], true) && isset($call['to'],$call['value']) && strtolower($call['from'] ?? '') !== strtolower($call['to']) && $this->integer($call['value'])->isPositive()) {
            $transfers[] = ['event_id' => $tx.':trace:'.$path, 'tx_hash' => $tx, 'contract' => null, 'address' => strtolower($call['to']), 'amount' => $this->amount($call['value'], 18), 'occurred_at' => $time];
        }
        foreach ($call['calls'] ?? [] as $index => $child) {
            $this->calls($child, $path.'.'.$index, $tx, $time, $transfers);
        }
    }

    private function bitcoin(ChainConnection $c, int $height): array
    {
        $hash = $this->hash($c, $height);
        $block = $this->rpc($c, 'getblock', [$hash, 2]);
        $transfers = [];
        if ($block['hash'] !== $hash || (int) $block['height'] !== $height) {
            throw new DomainException('CHAIN_REORG', 'Network confirmation changed.', 409);
        }
        foreach ($block['tx'] as $tx) {
            // Mining rewards require maturity beyond the ordinary six-confirmation payment policy.
            if (isset($tx['vin'][0]['coinbase'])) {
                continue;
            }
            foreach ($tx['vout'] as $output) {
                $address = $output['scriptPubKey']['address'] ?? null;
                if (! $address || ! BigDecimal::of($output['value'])->isPositive()) {
                    continue;
                }
                $transfers[] = ['event_id' => $tx['txid'].':vout:'.$output['n'], 'tx_hash' => $tx['txid'], 'contract' => null, 'address' => $address, 'amount' => (string) BigDecimal::of($output['value'])->toScale(8), 'occurred_at' => CarbonImmutable::createFromTimestampUTC((int) $block['time'])];
            }
        }
        if ($this->hash($c, $height) !== $hash) {
            throw new DomainException('CHAIN_REORG', 'Network confirmation changed.', 409);
        }

        return ['hash' => $hash, 'transfers' => $transfers];
    }

    public function transaction(ChainConnection $c, string $txHash): array
    {
        $final = $this->finalHeight($c);
        if ($c->network === 'ETHEREUM') {
            $receipt = $this->rpc($c, 'eth_getTransactionReceipt', [$txHash]);
            if (! $receipt || ($receipt['status'] ?? null) !== '0x1') {
                return [];
            }
            $height = $this->height($receipt['blockNumber']);
        } else {
            $tx = $this->rpc($c, 'getrawtransaction', [$txHash, true]);
            if (! isset($tx['blockhash'])) {
                return [];
            }
            $header = $this->rpc($c, 'getblockheader', [$tx['blockhash']]);
            $height = (int) $header['height'];
        }
        if ($height > $final) {
            return [];
        }
        $block = $this->block($c, $height);

        return array_map(fn ($p) => $p + ['block_height' => $height, 'block_hash' => $block['hash']], array_values(array_filter($block['transfers'], fn ($transfer) => $transfer['tx_hash'] === strtolower($txHash))));
    }
}
