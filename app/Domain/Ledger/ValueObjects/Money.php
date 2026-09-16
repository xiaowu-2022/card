<?php

declare(strict_types=1);

namespace App\Domain\Ledger\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

final readonly class Money implements JsonSerializable, Stringable
{
    public static function scale(string $asset): int
    {
        return match (strtoupper($asset)) {
            'ETH' => 18, 'USDC' => 6, default => 8
        };
    }

    private const MAX_INTEGER_DIGITS = 12;

    private BigDecimal $decimal;

    public string $assetCode;

    private function __construct(string $amount, string $assetCode)
    {
        $assetCode = strtoupper(trim($assetCode));

        if (! preg_match('/^[A-Z0-9]{3,12}$/', $assetCode)) {
            throw new InvalidArgumentException('Asset code must contain 3 to 12 uppercase letters or numbers.');
        }

        if (! preg_match('/^[+-]?\d+(?:\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException('Amount must be a plain decimal string.');
        }

        $fraction = str_contains($amount, '.') ? substr(strrchr($amount, '.'), 1) : '';

        if (strlen(rtrim($fraction, '0')) > self::scale($assetCode)) {
            throw new InvalidArgumentException('Amount must have at most '.self::scale($assetCode).' decimal places.');
        }

        $integer = ltrim(strtok(ltrim($amount, '+-'), '.') ?: '0', '0');
        if (strlen($integer) > self::MAX_INTEGER_DIGITS) {
            throw new InvalidArgumentException('Amount exceeds NUMERIC(20,8) ledger bounds.');
        }

        $this->assetCode = $assetCode;
        $this->decimal = BigDecimal::of($amount)->toScale(self::scale($assetCode), RoundingMode::Unnecessary);
    }

    public static function of(mixed $amount, string $assetCode): self
    {
        if (! is_string($amount)) {
            throw new InvalidArgumentException('Amount must be provided as a decimal string.');
        }

        return new self($amount, $assetCode);
    }

    public function amount(): string
    {
        return (string) $this->decimal;
    }

    public function add(self $other): self
    {
        $this->assertSameAsset($other);

        return self::of((string) $this->decimal->plus($other->decimal), $this->assetCode);
    }

    public function subtract(self $other): self
    {
        $this->assertSameAsset($other);

        return self::of((string) $this->decimal->minus($other->decimal), $this->assetCode);
    }

    public function compare(self $other): int
    {
        $this->assertSameAsset($other);

        return $this->decimal->compareTo($other->decimal);
    }

    public function isZero(): bool
    {
        return $this->decimal->isZero();
    }

    public function isPositive(): bool
    {
        return $this->decimal->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->decimal->isNegative();
    }

    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount(), 'asset' => $this->assetCode];
    }

    public function __toString(): string
    {
        return $this->amount().' '.$this->assetCode;
    }

    private function assertSameAsset(self $other): void
    {
        if ($this->assetCode !== $other->assetCode) {
            throw new DomainException("Cannot operate on {$this->assetCode} and {$other->assetCode} amounts.");
        }
    }
}
