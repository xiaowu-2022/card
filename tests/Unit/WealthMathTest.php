<?php

use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Wealth\WealthMath;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

it('anchors month ends and preserves cumulative rounding for every term and currency', function () {
    foreach (['USDT', 'USDC', 'ETH', 'BTC'] as $asset) {
        foreach (WealthMath::RATES as $months => $rate) {
            $schedule = WealthMath::schedule('1000', $asset, $rate, $months, CarbonImmutable::parse('2028-01-31T04:00:00Z'), 'Asia/Kuala_Lumpur');
            expect($schedule)->toHaveCount($months)->and($schedule[0]['due_at'])->toBe('2028-02-29T04:00:00+00:00');
            if ($months >= 3) {
                expect($schedule[1]['due_at'])->toBe('2028-03-31T04:00:00+00:00');
            }
            $sum = array_reduce($schedule, fn ($carry, $row) => $carry->plus($row['amount']), BigDecimal::zero());
            expect($sum->isEqualTo(BigDecimal::of('1000')->multipliedBy($rate)->multipliedBy($months)->dividedBy('1200', Money::scale($asset), RoundingMode::Down)))->toBeTrue();
        }
    }
});

it('carries tiny fractions forward and retains local time across daylight saving', function () {
    $rows = WealthMath::schedule('0.000001', 'USDC', '18', 60, CarbonImmutable::parse('2026-01-31T17:00:00Z'), 'America/New_York');
    expect($rows[0]['amount'])->toBe('0.000000')->and($rows[1]['due_at'])->toBe('2026-03-31T16:00:00+00:00');
});
