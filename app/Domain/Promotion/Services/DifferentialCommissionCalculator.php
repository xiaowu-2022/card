<?php

declare(strict_types=1);

namespace App\Domain\Promotion\Services;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class DifferentialCommissionCalculator
{
    public function normalizeReward(mixed $amount): string
    {
        if (! is_string($amount) || ! preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.0{1,8})?$/D', $amount)) {
            throw new InvalidArgumentException('Promotion rewards must be non-negative integer USDT decimal strings.');
        }

        return (string) BigDecimal::of($amount)->toScale(8);
    }

    /**
     * @param  list<array{userId: string, reward: string}>  $ancestors  Nearest inviter first; use a trusted Tenant-scoped snapshot.
     * @return list<array{userId: string, amount: string}>
     */
    public function allocate(array $ancestors): array
    {
        $highest = BigDecimal::zero()->toScale(8);
        $seen = [];
        $allocations = [];
        foreach ($ancestors as $ancestor) {
            $id = $ancestor['userId'] ?? null;
            if (! is_string($id) || $id === '' || isset($seen[$id])) {
                throw new InvalidArgumentException('An ancestry snapshot must contain unique beneficiaries.');
            }
            $seen[$id] = true;
            $reward = BigDecimal::of($this->normalizeReward($ancestor['reward'] ?? null));
            if ($reward->compareTo($highest) > 0) {
                $allocations[] = ['userId' => $id, 'amount' => (string) $reward->minus($highest)->toScale(8)];
                $highest = $reward;
            }
        }

        return $allocations;
    }
}
