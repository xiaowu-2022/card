<?php

namespace App\Application\Partners;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PartnerStockValue
{
    /** Signed personal difference: a negative difference increases the reported stock. */
    public static function afterDifference(string $businessStock, string $difference, string $subordinateAdvances = '0', string $annualSubsidy = '0'): string
    {
        return (string) BigDecimal::of($businessStock)->minus($difference)->minus($subordinateAdvances)->minus($annualSubsidy)->toScale(8, RoundingMode::HalfUp);
    }
}
