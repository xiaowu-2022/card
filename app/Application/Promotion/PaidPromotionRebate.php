<?php

namespace App\Application\Promotion;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class PaidPromotionRebate
{
    public function __construct(private PaidPromotionRules $rules, private LedgerWriter $ledger, private AuditLogger $audit) {}

    public function progress(object $cycle): array
    {
        $counts = DB::table('paid_promotion_shares as s')->join('paid_promotion_events as e', fn ($j) => $j->on('e.id', '=', 's.event_id')->on('e.tenant_id', '=', 's.tenant_id'))
            ->where('s.tenant_id', $cycle->tenant_id)->where('s.user_id', $cycle->user_id)->where('e.kind', 'ACTIVATION')
            ->where('e.occurred_at', '>=', $cycle->starts_at)->where('e.occurred_at', '<', $cycle->ends_at)
            ->selectRaw('COUNT(*) FILTER (WHERE depth=1) AS direct, COUNT(*) FILTER (WHERE depth>1) AS indirect')->first();
        $paid = DB::table('paid_promotion_orders')->where('tenant_id', $cycle->tenant_id)->where('user_id', $cycle->user_id)->where('cycle_id', $cycle->id)->where('status', 'COMPLETED')->sum('amount');
        $returned = DB::table('paid_promotion_rebates')->where('tenant_id', $cycle->tenant_id)->where('user_id', $cycle->user_id)->where('cycle_id', $cycle->id)->where('status', 'APPROVED')->sum('amount');

        return ['direct' => (int) $counts->direct, 'indirect' => (int) $counts->indirect, 'target' => $cycle->target,
            'paid' => (string) BigDecimal::of((string) $paid)->toScale(8), 'returned' => (string) BigDecimal::of((string) $returned)->toScale(8),
            'remaining' => (string) BigDecimal::of((string) $paid)->minus((string) $returned)->toScale(8)];
    }

    public function apply(string $tenant, string $user, string $request): object
    {
        $this->rules->requestId($request);

        return DB::transaction(function () use ($tenant, $user, $request) {
            $this->rules->operational($tenant, $user);
            $existing = DB::table('paid_promotion_rebates')->where('tenant_id', $tenant)->where('user_id', $user)->where('request_id', $request)->first();
            if ($existing) {
                return $existing;
            }
            $cycle = $this->rules->cycle($tenant, $user);
            if (! $cycle) {
                throw new DomainException('PROMOTION_CYCLE_EXPIRED', 'An active paid promotion period is required.');
            }
            if (DB::table('paid_promotion_rebates')->where('tenant_id', $tenant)->where('cycle_id', $cycle->id)->where('status', 'PENDING')->exists()) {
                throw new DomainException('PROMOTION_REBATE_PENDING', 'A fee rebate request is already under review.', 409);
            }
            $p = $this->progress($cycle);
            if (2 * $p['direct'] + $p['indirect'] < 2 * $p['target'] || ! BigDecimal::of($p['remaining'])->isPositive()) {
                throw new DomainException('PROMOTION_REBATE_NOT_READY', 'The annual fee rebate requirements are not met.');
            }
            $id = (string) Str::uuid();
            DB::table('paid_promotion_rebates')->insert(['id' => $id, 'tenant_id' => $tenant, 'user_id' => $user, 'cycle_id' => $cycle->id, 'request_id' => $request,
                'rank' => $cycle->rank, 'target' => $cycle->target, 'direct_count' => $p['direct'], 'indirect_count' => $p['indirect'], 'amount' => $p['remaining'], 'status' => 'PENDING', 'created_at' => now()]);
            $this->audit->record($tenant, 'USER', $user, 'PROMOTION_REBATE_REQUESTED', 'promotion_rebate', $id, null, ['amount' => $p['remaining'], 'cycle_id' => $cycle->id]);

            return DB::table('paid_promotion_rebates')->where('id', $id)->first();
        }, 3);
    }

    public function withdraw(string $tenant, string $user, string $id): void
    {
        DB::transaction(function () use ($tenant, $user, $id) {
            $this->rules->operational($tenant, $user);
            $claim = DB::table('paid_promotion_rebates')->where('tenant_id', $tenant)->where('user_id', $user)->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($claim->status === 'WITHDRAWN') {
                return;
            }
            if ($claim->status !== 'PENDING') {
                throw new DomainException('PROMOTION_REBATE_FINAL', 'This fee rebate request is already finalized.', 409);
            }
            DB::table('paid_promotion_rebates')->where('tenant_id', $tenant)->where('id', $id)->update(['status' => 'WITHDRAWN']);
            $this->audit->record($tenant, 'USER', $user, 'PROMOTION_REBATE_WITHDRAWN', 'promotion_rebate', $id, null, []);
        });
    }

    public function review(string $tenant, string $id, AdminUser $actor, bool $approve, ?string $reason): void
    {
        $this->rules->platform($actor, 'promotion_refunds.review');
        DB::transaction(function () use ($tenant, $id, $actor, $approve, $reason) {
            Tenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();
            $claim = DB::table('paid_promotion_rebates')->where('tenant_id', $tenant)->where('id', $id)->lockForUpdate()->firstOrFail();
            User::query()->where('tenant_id', $tenant)->whereKey($claim->user_id)->lockForUpdate()->firstOrFail();
            $status = $approve ? 'APPROVED' : 'REJECTED';
            if ($claim->status === $status) {
                return;
            }
            if ($claim->status !== 'PENDING') {
                throw new DomainException('PROMOTION_REBATE_FINAL', 'This fee rebate request is already finalized.', 409);
            }
            $entry = null;
            if ($approve) {
                $cycle = DB::table('paid_promotion_cycles')->where('tenant_id', $tenant)->where('user_id', $claim->user_id)->where('id', $claim->cycle_id)->firstOrFail();
                $p = $this->progress($cycle);
                if ($cycle->rank !== $claim->rank || $cycle->target !== $claim->target || BigDecimal::of($p['remaining'])->compareTo($claim->amount) !== 0
                    || $p['direct'] < $claim->direct_count || $p['indirect'] < $claim->indirect_count) {
                    throw new DomainException('PROMOTION_REBATE_CHANGED', 'The fee rebate evidence does not match.', 409);
                }
                $entry = $this->ledger->post(new LedgerPostingPlan($tenant, 'USDT', 'promotion_rebate:'.$id, 'PROMOTION_FEE_REBATE', 'PROMOTION_REBATE', $id, null, [
                    new LedgerPostingInstruction($this->rules->revenue($tenant)->id, Money::of('-'.$claim->amount, 'USDT')),
                    new LedgerPostingInstruction($this->rules->available($tenant, $claim->user_id)->id, Money::of($claim->amount, 'USDT')),
                ]));
            }
            DB::table('paid_promotion_rebates')->where('tenant_id', $tenant)->where('id', $id)->update(['status' => $status, 'ledger_entry_id' => $entry?->id, 'reviewer_id' => $actor->id, 'reviewed_at' => now(), 'reason' => $reason]);
            $this->audit->record($tenant, 'ADMIN', $actor->id, 'PROMOTION_REBATE_'.$status, 'promotion_rebate', $id, ['status' => 'PENDING'], ['amount' => $claim->amount, 'status' => $status, 'ledger_entry_id' => $entry?->id]);
        }, 3);
    }
}
