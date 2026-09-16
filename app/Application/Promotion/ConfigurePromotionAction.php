<?php

namespace App\Application\Promotion;

use App\Application\Tenant\CompanyConfigurationAuthority;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Promotion\Models\PromotionLevel;
use App\Domain\Promotion\Models\PromotionMember;
use App\Domain\Promotion\Services\DifferentialCommissionCalculator;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final readonly class ConfigurePromotionAction
{
    public function __construct(private DifferentialCommissionCalculator $calculator, private PromotionMembershipAction $members, private AuditLogger $audit) {}

    public function level(string $tenantId, string $actorId, int $rank, string $name, string $reward, ?int $expectedRevision): PromotionLevel
    {
        app(CompanyConfigurationAuthority::class)->assert(AdminUser::query()->findOrFail($actorId));
        $amount = $this->calculator->normalizeReward($reward);
        if ($rank < 1 || $rank > 1000000 || trim($name) === '' || mb_strlen($name) > 80) {
            throw new DomainException('PROMOTION_LEVEL_INVALID', 'Enter a valid promotion level.');
        }

        return DB::transaction(function () use ($tenantId, $actorId, $rank, $name, $amount, $expectedRevision): PromotionLevel {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $level = PromotionLevel::query()->where('tenant_id', $tenantId)->where('rank', $rank)->lockForUpdate()->first();
            if (($level?->revision ?? 0) !== ($expectedRevision ?? 0)) {
                throw new DomainException('PROMOTION_LEVEL_CHANGED', 'The promotion level changed. Reload and try again.', 409);
            }
            $decimal = BigDecimal::of($amount);
            foreach (PromotionLevel::query()->where('tenant_id', $tenantId)->where('rank', '<>', $rank)->get() as $other) {
                $comparison = $decimal->compareTo($other->reward_amount);
                if (($other->rank < $rank && $comparison < 0) || ($other->rank > $rank && $comparison > 0)) {
                    throw new DomainException('PROMOTION_REWARDS_NOT_ORDERED', 'Reward amounts must not decrease at higher levels.');
                }
            }
            $before = $level ? ['name' => $level->name, 'reward' => $level->reward_amount, 'revision' => $level->revision] : null;
            if ($level) {
                $level->update(['name' => trim($name), 'reward_amount' => $amount, 'revision' => $level->revision + 1]);
            } else {
                $level = PromotionLevel::query()->create(['tenant_id' => $tenantId, 'rank' => $rank, 'name' => trim($name), 'reward_amount' => $amount, 'revision' => 1]);
            }
            $this->audit->record($tenantId, 'ADMIN', $actorId, 'PROMOTION_LEVEL_CONFIGURED', 'promotion_level', $level->id, $before,
                ['name' => $level->name, 'reward' => $level->reward_amount, 'revision' => $level->revision]);

            return $level;
        });
    }

    public function memberLevel(string $tenantId, string $actorId, string $userId, ?string $levelId): PromotionMember
    {
        throw new DomainException('PROMOTION_PAYMENT_REQUIRED', 'Paid promotion levels require a successful annual fee payment.', 403);
    }
}
