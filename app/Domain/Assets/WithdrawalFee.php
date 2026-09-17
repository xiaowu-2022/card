<?php

namespace App\Domain\Assets;

use App\Domain\Ledger\ValueObjects\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class WithdrawalFee
{
    public static function calculate(string $amount, string $percent, string $asset): Money
    {
        $rate = BigDecimal::of($percent);
        if ($rate->isNegative() || $rate->isGreaterThanOrEqualTo('100')) {
            throw new \InvalidArgumentException('Invalid withdrawal fee percentage.');
        }
        // Round up to the network's smallest transferable unit; never round the payout up.
        $fee = BigDecimal::of($amount)->multipliedBy($rate)->dividedBy('100', AssetCatalog::chainScale($asset), RoundingMode::Ceiling);

        return Money::of((string) $fee, $asset);
    }
}
