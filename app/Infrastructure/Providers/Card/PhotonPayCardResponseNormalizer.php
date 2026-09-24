<?php

namespace App\Infrastructure\Providers\Card;

use App\Domain\CardProvider\DTOs\ProviderCardDTO;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PhotonPayCardResponseNormalizer
{
    /** @param array<string,mixed> $data */
    public function normalize(array $data, bool $isTest = false, string $expectedFormFactor = 'virtual_card'): ?ProviderCardDTO
    {
        $cardId = $this->string($data['cardId'] ?? null);
        $currency = strtoupper($this->string($data['cardCurrency'] ?? null) ?? '');
        $cardType = strtolower($this->string($data['cardType'] ?? null) ?? '');
        $formFactor = strtolower($this->string($data['cardFormFactor'] ?? null) ?? '');
        $rawPan = $this->string($data['cardNo'] ?? null);
        $providerMask = $this->string($data['maskCardNo'] ?? null);
        $last4 = $this->last4($providerMask ?: $rawPan);
        if (($expectedFormFactor === 'physical_card' && $this->string($data['cardStatus'] ?? null) === null) || $cardId === null || $last4 === null || $currency !== 'USD' || $cardType !== 'recharge'
            || (! in_array($expectedFormFactor, ['virtual_card', 'physical_card'], true) || ($formFactor !== $expectedFormFactor && ! ($formFactor === '' && $expectedFormFactor === 'virtual_card')))) {
            return null;
        }

        $expiry = $this->string($data['expirationDate'] ?? null);
        [$month, $year] = $this->expiry($expiry);
        $balance = null;
        if (is_string($data['cardBalance'] ?? null) || is_int($data['cardBalance'] ?? null)) {
            try {
                $decimal = BigDecimal::of((string) $data['cardBalance'])->toScale(8, RoundingMode::Unnecessary);
                $balance = $decimal->isNegative() || strlen((string) $decimal->getIntegralPart()) > 12 ? null : (string) $decimal;
            } catch (\Throwable) {
                $balance = null;
            }
        }

        return new ProviderCardDTO(
            $cardId,
            '',
            ($isTest ? 'TEST ' : '').'•••• '.$last4,
            $last4,
            $month,
            $year,
            $currency,
            strtolower($this->string($data['cardStatus'] ?? null) ?? 'normal'),
            $isTest,
            $balance,
            $expectedFormFactor,
            in_array($data['produceStatus'] ?? null, ['pending', 'produced'], true) ? $data['produceStatus'] : null,
            isset($data['trackingNumber']) && is_string($data['trackingNumber']) && preg_match('/^[A-Za-z0-9-]{1,100}$/D', $data['trackingNumber']) ? $data['trackingNumber'] : null,
        );
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function last4(?string $value): ?string
    {
        if ($value === null || preg_match('/([0-9]{4})$/', $value, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /** @return array{?int,?int} */
    private function expiry(?string $expiry): array
    {
        if ($expiry === null || preg_match('/^(0[1-9]|1[0-2])\/(\d{2}|\d{4})$/', $expiry, $match) !== 1) {
            return [null, null];
        }

        $year = (int) $match[2];

        return [(int) $match[1], $year < 100 ? 2000 + $year : $year];
    }
}
