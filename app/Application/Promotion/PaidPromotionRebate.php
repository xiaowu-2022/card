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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final readonly class PaidPromotionRebate
{
    public function __construct(private PaidPromotionRules $rules, private LedgerWriter $ledger, private AuditLogger $audit) {}

    public function progress(object $cycle): array
    {
        $counts = DB::table('account_activation_relations as s')->join('account_activations as e', fn ($j) => $j->on('e.id', '=', 's.activation_id')->on('e.tenant_id', '=', 's.tenant_id'))
            ->leftJoin('activation_count_snapshots as c', fn ($j) => $j->on('c.activation_id', '=', 's.activation_id')->on('c.tenant_id', '=', 's.tenant_id')->on('c.ancestor_user_id', '=', 's.ancestor_user_id'))
            ->where(fn ($q) => $q->where('e.counting_policy', 'LEGACY')->orWhere('c.eligible', true))
            ->where('s.tenant_id', $cycle->tenant_id)->where('s.ancestor_user_id', $cycle->user_id)
            ->where('e.activated_at', '>=', $cycle->starts_at)->where('e.activated_at', '<', $cycle->ends_at)
            ->selectRaw('COUNT(*) FILTER (WHERE s.depth=1) AS direct, COUNT(*) FILTER (WHERE s.depth>1) AS indirect')->first();
        $paid = DB::table('paid_promotion_orders')->where('tenant_id', $cycle->tenant_id)->where('user_id', $cycle->user_id)->where('cycle_id', $cycle->id)->where('status', 'COMPLETED')->sum('settlement_total');
        $returned = DB::table('paid_promotion_rebates')->where('tenant_id', $cycle->tenant_id)->where('user_id', $cycle->user_id)->where('cycle_id', $cycle->id)->where('status', 'APPROVED')->sum('amount');

        $pending = DB::table('paid_promotion_rebates')->where('tenant_id', $cycle->tenant_id)->where('cycle_id', $cycle->id)->where('status', 'PENDING')->exists();

        return ['policy' => $cycle->rebate_policy, 'pending' => $pending, 'direct' => (int) $counts->direct, 'indirect' => (int) $counts->indirect, 'target' => $cycle->target,
            'paid' => (string) BigDecimal::of((string) $paid)->toScale(8), 'returned' => (string) BigDecimal::of((string) $returned)->toScale(8),
            'remaining' => (string) BigDecimal::of((string) $paid)->minus((string) $returned)->toScale(8)];
    }

    /** Capture in the source transaction; settlement never changes the source result. */
    public function capture(string $tenant, string $user, ?CarbonImmutable $at = null): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Automatic rebate capture requires the source transaction.');
        }
        $cycle = $this->rules->cycle($tenant, $user, $at);
        if (! $cycle || $cycle->rebate_policy !== 'AUTO_FIRST_FUNDING') {
            return;
        }
        $p = $this->progress($cycle);
        if ($p['pending'] || 2 * $p['direct'] + $p['indirect'] < 2 * $p['target'] || ! BigDecimal::of($p['remaining'])->isPositive()) {
            return;
        }
        $id = (string) Str::uuid();
        DB::table('paid_promotion_rebates')->insert(['id' => $id, 'tenant_id' => $tenant, 'user_id' => $user, 'cycle_id' => $cycle->id,
            'request_id' => $id, 'source' => 'AUTO', 'paid_total' => $p['paid'], 'rank' => $cycle->rank, 'target' => $cycle->target,
            'direct_count' => $p['direct'], 'indirect_count' => $p['indirect'], 'amount' => $p['remaining'], 'status' => 'PENDING', 'created_at' => now()]);
        $this->audit->record($tenant, 'SYSTEM', null, 'PROMOTION_REBATE_QUEUED', 'promotion_rebate', $id, null, ['amount' => $p['remaining'], 'cycle_id' => $cycle->id]);
        DB::afterCommit(fn () => $this->attempt($tenant, $id));
    }

    public function attempt(string $tenant, string $id): bool
    {
        try {
            $this->settleAutomatic($tenant, $id);

            return true;
        } catch (\Throwable $error) {
            Log::warning('promotion.rebate.retry_required', ['tenant_id' => $tenant, 'rebate_id' => $id, 'exception' => $error::class, 'code' => $error instanceof DomainException ? $error->errorCode : $error->getCode()]);

            return false;
        }
    }

    public function settleAutomatic(string $tenant, string $id): void
    {
        DB::transaction(function () use ($tenant, $id) {
            Tenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();
            $claim = DB::table('paid_promotion_rebates')->where('tenant_id', $tenant)->where('id', $id)->where('source', 'AUTO')->lockForUpdate()->firstOrFail();
            User::query()->where('tenant_id', $tenant)->whereKey($claim->user_id)->lockForUpdate()->firstOrFail();
            if ($claim->status === 'APPROVED') {
                return;
            }
            $cycle = DB::table('paid_promotion_cycles')->where('tenant_id', $tenant)->where('user_id', $claim->user_id)->where('id', $claim->cycle_id)->lockForUpdate()->firstOrFail();
            $p = $this->progress($cycle);
            if ($cycle->rebate_policy !== 'AUTO_FIRST_FUNDING' || $claim->status !== 'PENDING' || $cycle->rank !== $claim->rank || $cycle->target !== $claim->target
                || BigDecimal::of($p['paid'])->compareTo($claim->paid_total) !== 0 || BigDecimal::of($p['remaining'])->compareTo($claim->amount) !== 0
                || $p['direct'] < $claim->direct_count || $p['indirect'] < $claim->indirect_count) {
                throw new DomainException('PROMOTION_REBATE_CHANGED', 'The fee rebate evidence does not match.', 409);
            }
            $entry = $this->ledger->post(new LedgerPostingPlan($tenant, 'USDT', 'promotion_rebate:'.$id, 'PROMOTION_FEE_REBATE', 'PROMOTION_REBATE', $id, null, [
                new LedgerPostingInstruction($this->rules->revenue($tenant)->id, Money::of('-'.$claim->amount, 'USDT')),
                new LedgerPostingInstruction($this->rules->available($tenant, $claim->user_id)->id, Money::of($claim->amount, 'USDT')),
            ]));
            DB::table('paid_promotion_rebates')->where('tenant_id', $tenant)->where('id', $id)->update(['status' => 'APPROVED', 'ledger_entry_id' => $entry->id, 'processed_at' => now()]);
            $this->audit->record($tenant, 'SYSTEM', null, 'PROMOTION_REBATE_AUTO_COMPLETED', 'promotion_rebate', $id, ['status' => 'PENDING'], ['amount' => $claim->amount, 'status' => 'APPROVED', 'ledger_entry_id' => $entry->id]);
        }, 3);
    }
}
