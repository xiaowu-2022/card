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
        $this->batch($tenant, $actor, [['id' => $levelId] + $values]);
    }

    public function batch(string $tenant, AdminUser $actor, array $changes): void
    {
        $this->rules->platform($actor, 'tenant.manage');
        DB::transaction(function () use ($tenant, $actor, $changes) {
            Tenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();
            $this->rules->platform($actor, 'tenant.manage');
            $levels = DB::table('paid_promotion_levels')->where('tenant_id', $tenant)->orderBy('rank')->lockForUpdate()->get()->keyBy('id');
            $next = [];
            foreach ($changes as $values) {
                $level = $levels->get($values['id']);
                if (! $level || isset($next[$values['id']])) {
                    throw new DomainException('PROMOTION_LEVEL_INVALID', 'Enter valid promotion terms.');
                }
                if ($level->revision !== (int) $values['revision']) {
                    throw new DomainException('PROMOTION_QUOTE_CHANGED', 'Promotion terms changed. Request a new quote.', 409);
                }
                $fee = BigDecimal::of($values['fee'])->toScale(8);
                if (! $fee->isPositive() || $values['percent'] < 0 || $values['percent'] > 100 || $values['reward'] < 20 || $values['target'] < 1) {
                    throw new DomainException('PROMOTION_LEVEL_INVALID', 'Enter valid promotion terms.');
                }
                $next[$level->id] = ['fee' => (string) $fee, 'percent' => $values['percent'], 'reward' => $values['reward'], 'target' => $values['target'], 'enabled' => $values['enabled'], 'revision' => $level->revision + 1, 'updated_at' => now()];
            }
            $previous = null;
            foreach ($levels as $level) {
                $terms = $next[$level->id] ?? (array) $level;
                if ($previous && (BigDecimal::of($terms['fee'])->isLessThanOrEqualTo($previous['fee']) || $terms['percent'] < $previous['percent'] || $terms['reward'] < $previous['reward'])) {
                    throw new DomainException('PROMOTION_LEVEL_INVALID', 'Promotion prices and rewards must increase with rank.');
                }
                $previous = $terms;
            }
            foreach ($next as $id => $values) {
                DB::table('paid_promotion_levels')->where('tenant_id', $tenant)->where('id', $id)->update($values);
                $this->audit->record($tenant, 'ADMIN', $actor->id, 'PAID_PROMOTION_CONFIGURED', 'promotion_level', $id, ['revision' => $levels[$id]->revision], $values);
            }
        });
    }
}
