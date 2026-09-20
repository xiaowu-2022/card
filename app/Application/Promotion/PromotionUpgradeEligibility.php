<?php

namespace App\Application\Promotion;

use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final readonly class PromotionUpgradeEligibility
{
    public function context(string $tenant, ?object $cycle): array
    {
        $progress = $cycle ? app(PaidPromotionRebate::class)->progress($cycle) : null;
        $units = 2 * ($progress['direct'] ?? 0) + ($progress['indirect'] ?? 0);

        return ['weightedUnits' => $units, 'weightedCount' => (string) intdiv($units, 2).($units % 2 ? '.5' : ''),
            'highestEnabledRank' => (int) (DB::table('paid_promotion_levels')->where('tenant_id', $tenant)->where('enabled', true)->max('rank') ?? 0),
            'pending' => $progress['pending'] ?? false];
    }

    public function decision(?object $cycle, object $level, array $context): array
    {
        [$code, $reason] = match (true) {
            ! $level->enabled => ['PROMOTION_LEVEL_DISABLED', 'This promotion level is disabled.'],
            $cycle && ($level->rank <= $cycle->rank || BigDecimal::of($level->fee)->compareTo($cycle->tariff) <= 0) => ['PROMOTION_UPGRADE_INVALID', 'Only a higher promotion level can be purchased before expiry.'],
            $cycle && $context['pending'] => ['PROMOTION_REBATE_PENDING', 'Annual fee return is processing. Try upgrading shortly.'],
            $cycle && $level->rank !== $context['highestEnabledRank'] && 2 * $level->target <= $context['weightedUnits'] => ['PROMOTION_UPGRADE_TARGET_REACHED', 'This level’s target must exceed your current cycle activation count. Choose a higher level.'],
            default => [null, null],
        };

        return ['selectable' => $code === null, 'unavailableCode' => $code, 'unavailableReason' => $reason];
    }

    public function assertAllowed(string $tenant, ?object $cycle, object $level): void
    {
        $result = $this->decision($cycle, $level, $this->context($tenant, $cycle));
        if (! $result['selectable']) {
            throw new DomainException($result['unavailableCode'], $result['unavailableReason'], $result['unavailableCode'] === 'PROMOTION_UPGRADE_INVALID' ? 422 : 409);
        }
    }
}
