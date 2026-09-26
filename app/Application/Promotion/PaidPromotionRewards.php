<?php

namespace App\Application\Promotion;

use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Promotion\Models\CommissionAward;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class PaidPromotionRewards
{
    public function __construct(private PaidPromotionRules $rules, private CommissionAccounts $accounts, private LedgerWriter $ledger, private PaidPromotionRebate $rebates, private RecordAccountActivation $activation) {}

    /** Only within the source financial transaction; zero shares preserve count/routing evidence. */
    public function execute(string $tenant, string $sourceUser, string $kind, string $sourceId, string $amount, ?int $purchasedRank = null): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Reward allocation requires source transaction.');
        }
        if (DB::table('paid_promotion_events')->where('tenant_id', $tenant)->where('kind', $kind)->where('source_id', $sourceId)->exists()) {
            return;
        }
        $time = CarbonImmutable::now();
        $id = (string) Str::uuid();
        $activationEligible = false;
        $firstFunding = false;
        if ($kind === 'ACTIVATION') {
            $funding = DB::table('promotion_funding_events')->where('tenant_id', $tenant)->where('user_id', $sourceUser)->where('id', $sourceId)->firstOrFail();
            $time = CarbonImmutable::parse($funding->funded_at);
            $firstFunding = ! DB::table('promotion_funding_events')->where('tenant_id', $tenant)->where('user_id', $sourceUser)->where('id', '<>', $sourceId)->exists();
            $activationEligible = $this->activation->execute($tenant, $sourceUser, 'DEPOSIT', $funding->funding_entry_id, $funding->funding_entry_id, $time);
        } else {
            $order = DB::table('paid_promotion_orders')->where('tenant_id', $tenant)->where('user_id', $sourceUser)->where('id', $sourceId)->where('status', 'COMPLETED')->firstOrFail();
            $time = CarbonImmutable::parse($order->completed_at);
            $activationEligible = $this->activation->execute($tenant, $sourceUser, 'ANNUAL', $sourceId, $order->ledger_entry_id, $time);
        }
        DB::table('paid_promotion_events')->insert(['id' => $id, 'tenant_id' => $tenant, 'user_id' => $sourceUser, 'kind' => $kind, 'source_id' => $sourceId,
            'source_rank' => $purchasedRank ?? ($this->rules->cycle($tenant, $sourceUser, $time)?->rank ?? 0), 'amount' => $amount, 'occurred_at' => $time, 'created_at' => $time, 'is_first_funding' => $kind === 'ACTIVATION' ? $firstFunding : false]);
        $highest = 0;
        $ancestors = $this->rules->ancestors($tenant, $sourceUser);
        foreach ($ancestors as $ancestor) {
            $cycle = $this->rules->cycle($tenant, $ancestor->user_id, $time);
            $standard = $kind === 'ANNUAL' ? ($cycle?->percent ?? 0) : ($cycle?->reward ?? ($ancestor->depth === 1 ? 20 : 0));
            if (($kind === 'ACTIVATION' && ! $activationEligible)
                || ($kind === 'ANNUAL' && $ancestor->depth > 1 && ($cycle?->rank ?? 0) < $purchasedRank)) {
                // Subsequent funding earns no reward; retain a zero share for activity history only.
                $standard = 0;
            }
            $covered = $highest;
            $difference = max(0, $standard - $highest);
            $highest = max($standard, $highest);
            $reward = $kind === 'ANNUAL' ? BigDecimal::of($amount)->multipliedBy($difference)->dividedBy(100, 8, RoundingMode::Down) : BigDecimal::of($difference)->toScale(8);
            $shareId = (string) Str::uuid();
            $entry = null;
            if ($reward->isPositive()) {
                $eventKey = $kind === 'ANNUAL' ? 'promotion_annual_award:'.$shareId : 'commission_award:'.$shareId;
                $entry = $this->ledger->post(new LedgerPostingPlan($tenant, 'USDT', $eventKey, $kind === 'ANNUAL' ? 'PROMOTION_ANNUAL_COMMISSION' : 'COMMISSION_EARN',
                    $kind === 'ANNUAL' ? 'PROMOTION_ANNUAL_AWARD' : 'COMMISSION_AWARD', $shareId, null, [
                        new LedgerPostingInstruction($this->accounts->company($tenant)->id, Money::of((string) $reward->negated(), 'USDT')),
                        new LedgerPostingInstruction($this->accounts->forUser($tenant, $ancestor->user_id)->id, Money::of((string) $reward, 'USDT')),
                    ]));
                if ($kind === 'ACTIVATION') {
                    CommissionAward::query()->create(['id' => $shareId, 'tenant_id' => $tenant, 'funding_event_id' => $sourceId,
                        'user_id' => $ancestor->user_id, 'level_id' => null, 'level_revision' => $cycle?->revision ?? 1, 'level_reward' => (string) $standard,
                        'amount' => (string) $reward, 'asset_code' => 'USDT', 'ledger_entry_id' => $entry->id]);
                }
            }
            DB::table('paid_promotion_shares')->insert(['id' => $shareId, 'tenant_id' => $tenant, 'user_id' => $ancestor->user_id, 'event_id' => $id,
                'cycle_id' => $cycle?->id, 'revision' => $cycle?->revision, 'standard' => $standard, 'covered' => $covered,
                'depth' => $ancestor->depth, 'rank' => $cycle?->rank ?? 0, 'rate' => (string) $difference, 'amount' => (string) $reward, 'ledger_entry_id' => $entry?->id, 'created_at' => $time]);
            if ($entry) {
                app(\App\Application\Inbox\InboxWriter::class)->record($tenant, $ancestor->user_id, 'commission:'.$shareId,
                    $kind === 'ACTIVATION' ? 'commission_activation' : 'commission_annual',
                    ['amount' => (string) $reward, 'asset' => 'USDT'], '/promotion/commissions', $time);
            }
        }
        if ($activationEligible) {
            foreach ($ancestors as $ancestor) {
                $this->rebates->capture($tenant, $ancestor->user_id, $time);
            }
        }
    }
}
