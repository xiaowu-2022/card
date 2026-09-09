<?php

use App\Domain\Ledger\ValueObjects\Money;

it('adds money with the same asset and preserves decimal precision', function (): void {
    $sum = Money::of('100.00000001', 'USD')->add(Money::of('20.50000009', 'USD'));

    expect($sum->amount())->toBe('120.50000010')
        ->and($sum->assetCode)->toBe('USD');
});

it('subtracts and compares same-asset money', function (): void {
    $result = Money::of('10.00000000', 'USD')->subtract(Money::of('3.25000000', 'USD'));

    expect($result->amount())->toBe('6.75000000')
        ->and($result->compare(Money::of('6.75000000', 'USD')))->toBe(0)
        ->and($result->isPositive())->toBeTrue()
        ->and(Money::of('0', 'USD')->isZero())->toBeTrue();
});

it('rejects arithmetic across different assets', function (): void {
    Money::of('1', 'USD')->add(Money::of('1', 'USDT'));
})->throws(DomainException::class);

it('rejects amounts that exceed eight decimal places', function (): void {
    Money::of('0.000000001', 'USD');
})->throws(InvalidArgumentException::class, 'at most 8 decimal places');

it('preserves the minimum unit and large values exactly', function (): void {
    expect(Money::of('0.00000001', 'USD')->amount())->toBe('0.00000001')
        ->and(Money::of('999999999999.12345678', 'USD')->amount())->toBe('999999999999.12345678');
});

it('adds common decimal fractions without binary floating point error', function (): void {
    expect(Money::of('0.1', 'USD')->add(Money::of('0.2', 'USD'))->amount())->toBe('0.30000000');
});

it('rejects non-string and non-decimal amount input', function (mixed $amount): void {
    Money::of($amount, 'USD');
})->with([
    'float' => 0.1,
    'integer' => 1,
    'scientific notation' => '1e3',
    'whitespace' => ' 1.00 ',
])->throws(InvalidArgumentException::class);
