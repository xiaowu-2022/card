<?php

namespace App\Application\Promotion;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final readonly class ConfigurePaidPromotion
{
    public function __construct(private PaidPromotionRules $rules, private AuditLogger $audit) {}

    public function execute(string $tenant, AdminUser $actor, string $levelId, array $values): void
    {
        $this->rules->platform($actor, 'tenant.manage');
        DB::transaction(function () use ($tenant, $actor, $levelId, $values) {
            Tenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();
            $level = DB::table('paid_promotion_levels')->where('tenant_id', $tenant)->where('id', $levelId)->lockForUpdate()->firstOrFail();
            if ($level->revision !== (int) $values['revision']) {
                throw new DomainException('PROMOTION_QUOTE_CHANGED', 'Promotion terms changed. Request a new quote.', 409);
            }
            $fee = BigDecimal::of($values['fee'])->toScale(8);
            if (! $fee->isPositive() || $values['percent'] < 0 || $values['percent'] > 100 || $values['reward'] < 20 || $values['target'] < 1) {
                throw new DomainException('PROMOTION_LEVEL_INVALID', 'Enter valid promotion terms.');
            }
            foreach (DB::table('paid_promotion_levels')->where('tenant_id', $tenant)->where('id', '<>', $levelId)->get() as $other) {
                $sign = $other->rank < $level->rank ? 1 : -1;
                if ($fee->compareTo($other->fee) * $sign <= 0 || ($values['percent'] - $other->percent) * $sign < 0 || ($values['reward'] - $other->reward) * $sign < 0) {
                    throw new DomainException('PROMOTION_LEVEL_INVALID', 'Promotion prices and rewards must increase with rank.');
                }
            }
            $next = ['fee' => (string) $fee, 'percent' => $values['percent'], 'reward' => $values['reward'], 'target' => $values['target'], 'enabled' => $values['enabled'], 'revision' => $level->revision + 1, 'updated_at' => now()];
            DB::table('paid_promotion_levels')->where('tenant_id', $tenant)->where('id', $levelId)->update($next);
            $this->audit->record($tenant, 'ADMIN', $actor->id, 'PAID_PROMOTION_CONFIGURED', 'promotion_level', $levelId, ['revision' => $level->revision], $next);
        });
    }
}
