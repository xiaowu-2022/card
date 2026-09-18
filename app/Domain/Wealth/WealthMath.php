<?php

namespace App\Domain\Wealth;

use App\Domain\Ledger\ValueObjects\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

final class WealthMath
{
    public const RATES = [1 => '6', 3 => '8', 6 => '12', 12 => '15', 24 => '16', 36 => '17', 60 => '18'];

    public static function schedule(string $principal, string $asset, string $rate, int $months, CarbonImmutable $start, string $timezone): array
    {
        $rows = [];
        $previous = BigDecimal::zero();
        for ($month = 1; $month <= $months; $month++) {
            $cumulative = BigDecimal::of($principal)->multipliedBy($rate)->multipliedBy($month)->dividedBy('1200', Money::scale($asset), RoundingMode::Down);
            $rows[] = ['month' => $month, 'due_at' => $start->setTimezone($timezone)->addMonthsNoOverflow($month)->utc()->toIso8601String(), 'amount' => (string) $cumulative->minus($previous)];
            $previous = $cumulative;
        }

        return $rows;
    }
}
