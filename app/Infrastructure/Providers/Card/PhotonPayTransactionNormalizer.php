<?php

namespace App\Infrastructure\Providers\Card;

use App\Domain\CardProvider\DTOs\ProviderCardTransactionDTO;
use App\Domain\CardProvider\DTOs\ProviderTransactionPageDTO;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

final class PhotonPayTransactionNormalizer
{
    /** @return array<string, mixed> */
    public function decode(string $body): array
    {
        if (strlen($body) > 2097152 || ! json_validate($body)) {
            throw new ProviderUnknownResultException('Provider transaction response is invalid.');
        }
        // Validate syntax first, then quote numeric tokens OUTSIDE strings before decoding.
        // Provider JSON decimals must never pass through a PHP float, even for display.
        $quoted = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"(*SKIP)(*F)|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/s',
            static fn (array $match): string => '"'.$match[0].'"', $body);
        $data = json_decode($quoted ?? '', true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new ProviderUnknownResultException('Provider transaction response is invalid.');
        }

        return $data;
    }

    /** @param array<string, mixed> $response */
    public function page(array $response, string $cardId, int $page, int $size, ?string $verifiedCardCurrency = null): ProviderTransactionPageDTO
    {
        $rows = $response['data'] ?? null;
        $total = $this->integer($response['total'] ?? null);
        if ($this->integer($response['pageIndex'] ?? null) !== $page || $this->integer($response['pageSize'] ?? null) !== $size
            || ! is_array($rows) || ! array_is_list($rows) || count($rows) > $size
            || count($rows) !== min($size, max(0, $total - ($page - 1) * $size))) {
            throw new ProviderUnknownResultException('Provider transaction pagination is inconsistent.');
        }
        $items = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['cardId'] ?? null) !== $cardId || ($row['cardType'] ?? null) !== 'recharge'
                || ($row['cardCurrency'] ?? $verifiedCardCurrency) !== 'USD' || ($row['cardFormFactor'] ?? null) !== 'virtual_card') {
                throw new ProviderUnknownResultException('Provider transaction ownership is inconsistent.');
            }
            $id = $this->text($row['transactionId'] ?? null);
            if (isset($seen[$id])) {
                throw new ProviderUnknownResultException('Provider returned duplicate transactions.');
            }
            $seen[$id] = true;
            $currency = $this->text($row['transactionCurrency'] ?? null);
            if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new ProviderUnknownResultException('Provider transaction currency is invalid.');
            }
            // The documented timestamp has no offset. Preserve provider wall time;
            // never invent UTC or convert it through the browser's local timezone.
            $when = $row['txnDate'] ?? $row['createdAt'] ?? null;
            $date = is_string($when) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $when) : false;
            if (! $date || $date->format('Y-m-d\TH:i:s') !== $when) {
                throw new ProviderUnknownResultException('Provider transaction time is invalid.');
            }
            $merchant = is_string($row['merchantNameLocation'] ?? null) ? trim($row['merchantNameLocation']) : '';
            $merchant = mb_substr(preg_replace('/[\p{C}]/u', '', $merchant) ?? '', 0, 120);
            if (preg_match('/\d{12,19}/', $merchant)) {
                $merchant = ''; // Never surface card-like sensitive numbers as merchant copy.
            }
            $items[] = new ProviderCardTransactionDTO($id, $this->amount($row['transactionAmount'] ?? null), $currency,
                match ($row['transactionType'] ?? null) {
                    'auth' => 'purchase', 'verification' => 'verification',
                    'void' => 'reversal', 'refund' => 'refund',
                    'recharge', 'fund_in' => 'transfer_in',
                    'recharge_return', 'discard_recharge_return' => 'transfer_out',
                    'service_fee' => 'fee', 'atm_withdrawals' => 'cash_withdrawal',
                    'atm_inquiry' => 'balance_inquiry',
                    'corrective_auth', 'corrective_refund', 'corrective_refund_void', 'refund_reversal' => 'adjustment',
                    default => 'other',
                },
                match ($row['status'] ?? null) {
                    'succeed' => 'completed', 'authorized' => 'authorized',
                    'failed' => 'declined', 'void' => 'reversed',
                    'pending', 'processing' => 'pending', default => 'confirming',
                }, $when, $merchant === '' ? null : $merchant);
        }

        return new ProviderTransactionPageDTO($items, $page, $page * $size < $total);
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '' || strlen($value) > 180) {
            throw new ProviderUnknownResultException('Provider transaction field is invalid.');
        }

        return trim($value);
    }

    private function integer(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/^(0|[1-9]\d{0,8})$/', $value)) {
            throw new ProviderUnknownResultException('Provider transaction pagination is invalid.');
        }

        return (int) $value;
    }

    private function amount(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^-?(?:0|[1-9]\d{0,15})(?:\.\d{1,16})?(?:[eE][+-]?\d{1,2})?$/', $value)) {
            throw new ProviderUnknownResultException('Provider transaction amount is invalid.');
        }
        try {
            $amount = BigDecimal::of($value)->toScale(8, RoundingMode::Unnecessary);
            if (strlen(ltrim((string) $amount->getIntegralPart(), '-')) > 12) {
                throw new \UnexpectedValueException;
            }

            return (string) $amount;
        } catch (\Throwable) {
            throw new ProviderUnknownResultException('Provider transaction amount is invalid.');
        }
    }
}
