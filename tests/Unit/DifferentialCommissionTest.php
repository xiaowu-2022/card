<?php

use App\Domain\Promotion\Services\DifferentialCommissionCalculator;

it('allocates only increasing differences and never repeats an equal or lower tier', function (): void {
    $calculator = new DifferentialCommissionCalculator;
    expect($calculator->allocate([
        ['userId' => 'near', 'reward' => '5'], ['userId' => 'equal', 'reward' => '5'],
        ['userId' => 'lower', 'reward' => '3'], ['userId' => 'middle', 'reward' => '10'], ['userId' => 'top', 'reward' => '15'],
    ]))->toBe([
        ['userId' => 'near', 'amount' => '5.00000000'], ['userId' => 'middle', 'amount' => '5.00000000'], ['userId' => 'top', 'amount' => '5.00000000'],
    ]);
    expect($calculator->allocate([]))->toBe([]);
    expect($calculator->allocate([['userId' => 'zero', 'reward' => '0.00000000']]))->toBe([]);
});

it('retains exact large integer rewards', function (): void {
    expect((new DifferentialCommissionCalculator)->allocate([
        ['userId' => 'a', 'reward' => '999999999998'], ['userId' => 'b', 'reward' => '999999999999'],
    ]))->toBe([['userId' => 'a', 'amount' => '999999999998.00000000'], ['userId' => 'b', 'amount' => '1.00000000']]);
});

it('rejects fractional floating negative and oversized rewards', function (mixed $value): void {
    expect(fn () => (new DifferentialCommissionCalculator)->normalizeReward($value))->toThrow(InvalidArgumentException::class);
})->with(['1.2', 2, 1.5, '-1', '1e3', '1000000000000', null]);

it('rejects repeated ancestry rather than paying a cycle', function (): void {
    expect(fn () => (new DifferentialCommissionCalculator)->allocate([
        ['userId' => 'a', 'reward' => '5'], ['userId' => 'a', 'reward' => '10'],
    ]))->toThrow(InvalidArgumentException::class);
});
