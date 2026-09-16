<?php

namespace App\Application\Promotion;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class PaidPromotionPurchase
{
    public function __construct(private PaidPromotionRules $rules, private PaidPromotionRewards $rewards, private LedgerWriter $ledger, private AuditLogger $audit) {}

    public function quote(string $tenant, string $user, string $levelId, string $requestId): object
    {
        $this->rules->requestId($requestId);

        return DB::transaction(function () use ($tenant, $user, $levelId, $requestId) {
            $this->rules->operational($tenant, $user);
            $existing = DB::table('paid_promotion_orders')->where('tenant_id', $tenant)->where('user_id', $user)->where('request_id', $requestId)->first();
            if ($existing) {
                if ($existing->level_id !== $levelId) {
                    throw new DomainException('PROMOTION_REQUEST_CONFLICT', 'This request was already used for another level.', 409);
                }

                return $existing;
            }
            $level = DB::table('paid_promotion_levels')->where('tenant_id', $tenant)->where('id', $levelId)->where('enabled', true)->firstOrFail();
            $cycle = $this->rules->cycle($tenant, $user);
            $this->assertUpgrade($tenant, $cycle, $level);
            $previous = $cycle?->tariff ?? '0';
            $amount = (string) BigDecimal::of($level->fee)->minus($previous)->toScale(8);
            $id = (string) Str::uuid();
            DB::table('paid_promotion_orders')->insert(['id' => $id, 'tenant_id' => $tenant, 'user_id' => $user, 'request_id' => $requestId,
                'cycle_id' => $cycle?->id, 'level_id' => $level->id, 'revision' => $level->revision, 'rank' => $level->rank, 'tariff' => $level->fee,
                'percent' => $level->percent, 'reward' => $level->reward, 'target' => $level->target, 'amount' => $amount, 'previous_tariff' => $previous,
                'status' => 'QUOTED', 'expires_at' => now()->addMinutes(5), 'created_at' => now()]);

            return DB::table('paid_promotion_orders')->where('id', $id)->first();
        }, 3);
    }

    public function confirm(string $tenant, string $user, string $orderId): object
    {
        return DB::transaction(function () use ($tenant, $user, $orderId) {
            Tenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();
            User::query()->where('tenant_id', $tenant)->whereKey($user)->lockForUpdate()->firstOrFail();
            $order = DB::table('paid_promotion_orders')->where('tenant_id', $tenant)->where('user_id', $user)->where('id', $orderId)->lockForUpdate()->firstOrFail();
            if ($order->status === 'COMPLETED') {
                return $order;
            }
            $company = $this->rules->operational($tenant, $user);
            if (CarbonImmutable::parse($order->expires_at)->lessThanOrEqualTo(now())) {
                throw new DomainException('PROMOTION_QUOTE_EXPIRED', 'The promotion quote expired. Request a new quote.', 409);
            }
            $level = DB::table('paid_promotion_levels')->where('tenant_id', $tenant)->where('id', $order->level_id)->where('enabled', true)->firstOrFail();
            $cycle = $this->rules->cycle($tenant, $user);
            if ($level->revision !== $order->revision || $order->cycle_id !== $cycle?->id || BigDecimal::of($order->previous_tariff)->compareTo($cycle?->tariff ?? '0') !== 0) {
                throw new DomainException('PROMOTION_QUOTE_CHANGED', 'Promotion terms changed. Request a new quote.', 409);
            }
            $this->assertUpgrade($tenant, $cycle, $level);
            $now = CarbonImmutable::now();
            $cycleId = $cycle?->id ?? (string) Str::uuid();
            $values = ['level_id' => $order->level_id, 'revision' => $order->revision, 'rank' => $order->rank, 'tariff' => $order->tariff, 'percent' => $order->percent, 'reward' => $order->reward, 'target' => $order->target];
            if ($cycle) {
                DB::table('paid_promotion_cycles')->where('tenant_id', $tenant)->where('id', $cycleId)->update($values);
            } else {
                DB::table('paid_promotion_cycles')->insert($values + ['id' => $cycleId, 'tenant_id' => $tenant, 'user_id' => $user, 'starts_at' => $now,
                    'ends_at' => $now->setTimezone($company->timezone)->addYearNoOverflow()->utc(), 'created_at' => $now]);
            }
            $entry = $this->ledger->post(new LedgerPostingPlan($tenant, 'USDT', 'promotion_fee:'.$order->id, 'PROMOTION_ANNUAL_FEE', 'PROMOTION_ORDER', $order->id, null, [
                new LedgerPostingInstruction($this->rules->available($tenant, $user)->id, Money::of('-'.$order->amount, 'USDT')),
                new LedgerPostingInstruction($this->rules->revenue($tenant)->id, Money::of($order->amount, 'USDT')),
            ]));
            DB::table('paid_promotion_orders')->where('tenant_id', $tenant)->where('id', $order->id)->update(['status' => 'COMPLETED', 'cycle_id' => $cycleId, 'completed_at' => $now, 'ledger_entry_id' => $entry->id]);
            $this->rewards->execute($tenant, $user, 'ANNUAL', $order->id, $order->amount, $order->rank);
            $this->audit->record($tenant, 'USER', $user, 'PROMOTION_FEE_PAID', 'promotion_order', $order->id, null, ['amount' => $order->amount, 'rank' => $order->rank, 'cycle_id' => $cycleId]);

            return DB::table('paid_promotion_orders')->where('tenant_id', $tenant)->where('id', $order->id)->first();
        }, 3);
    }

    private function assertUpgrade(string $tenant, ?object $cycle, object $level): void
    {
        if (! $cycle) {
            return;
        }
        if ($level->rank <= $cycle->rank || BigDecimal::of($level->fee)->compareTo($cycle->tariff) <= 0) {
            throw new DomainException('PROMOTION_UPGRADE_INVALID', 'Only a higher promotion level can be purchased before expiry.');
        }
        if (DB::table('paid_promotion_rebates')->where('tenant_id', $tenant)->where('cycle_id', $cycle->id)->where('status', 'PENDING')->exists()) {
            throw new DomainException('PROMOTION_REBATE_PENDING', 'Withdraw the pending fee rebate request before upgrading.', 409);
        }
    }
}
