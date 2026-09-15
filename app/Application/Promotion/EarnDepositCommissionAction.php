<?php

namespace App\Application\Promotion;

use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Promotion\Models\CommissionAward;
use App\Domain\Promotion\Models\PromotionFundingEvent;
use App\Domain\Promotion\Models\PromotionMember;
use App\Domain\Promotion\Services\DifferentialCommissionCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class EarnDepositCommissionAction
{
    public function __construct(private DifferentialCommissionCalculator $calculator, private CommissionAccounts $accounts, private LedgerWriter $ledger) {}

    /** Invoked atomically inside FundSecurityDepositAction, after its exact funding event. Never from a top-up/webhook. */
    public function execute(string $tenantId, string $userId, LedgerEntry $funding): void
    {
        if (DB::transactionLevel() < 1 || $funding->tenant_id !== $tenantId || $funding->event_type !== 'SECURITY_DEPOSIT_FUND' || $funding->sealed_at === null) {
            throw new \LogicException('Commission requires a sealed funding event inside its business transaction.');
        }
        if ($funding->asset_code !== 'USDT') {
            // Legacy non-USDT deposits have no USDT promotion capability; never invent FX.
            return;
        }
        if (PromotionFundingEvent::query()->where('tenant_id', $tenantId)->where('funding_entry_id', $funding->id)->exists()) {
            return;
        }
        $amount = DB::table('ledger_postings as p')->join('ledger_accounts as a', 'a.id', '=', 'p.ledger_account_id')
            ->where('p.tenant_id', $tenantId)->where('a.tenant_id', $tenantId)->where('a.user_id', $userId)
            ->where('p.ledger_entry_id', $funding->id)->where('a.account_type', 'USER_SECURITY_DEPOSIT')->value('p.delta');
        if (! is_string($amount) || ! Money::of($amount, 'USDT')->isPositive()) {
            throw new \LogicException('Commission source is not a positive deposit funding event.');
        }
        $event = PromotionFundingEvent::query()->create(['tenant_id' => $tenantId, 'user_id' => $userId,
            'funding_entry_id' => $funding->id, 'asset_code' => 'USDT', 'amount' => $amount, 'funded_at' => $funding->posted_at]);
        $member = PromotionMember::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->first();
        if (! $member?->inviter_id) {
            return;
        }
        $chain = DB::select(<<<'SQL'
            WITH RECURSIVE ancestry AS (
                SELECT m.id, m.inviter_id, m.user_id, m.level_id, 1 AS depth FROM promotion_members m WHERE m.tenant_id=? AND m.id=?
                UNION ALL
                SELECT m.id, m.inviter_id, m.user_id, m.level_id, a.depth+1 FROM promotion_members m JOIN ancestry a ON m.id=a.inviter_id WHERE m.tenant_id=?
            ) SELECT a.user_id, a.level_id, l.revision, COALESCE(l.reward_amount,0)::text AS reward
            FROM ancestry a LEFT JOIN promotion_levels l ON l.id=a.level_id AND l.tenant_id=? ORDER BY a.depth
            SQL, [$tenantId, $member->inviter_id, $tenantId, $tenantId]);
        $snapshot = [];
        foreach ($chain as $row) {
            $snapshot[$row->user_id] = $row;
        }
        $allocations = $this->calculator->allocate(array_map(fn ($row) => ['userId' => $row->user_id, 'reward' => $row->reward], $chain));
        if ($allocations === []) {
            return;
        }
        $company = $this->accounts->company($tenantId);
        foreach ($allocations as $allocation) {
            $id = (string) Str::uuid();
            $beneficiary = $this->accounts->forUser($tenantId, $allocation['userId']);
            $money = Money::of($allocation['amount'], 'USDT');
            $entry = $this->ledger->post(new LedgerPostingPlan($tenantId, 'USDT', 'commission_award:'.$id, 'COMMISSION_EARN', 'COMMISSION_AWARD', $id, null, [
                new LedgerPostingInstruction($company->id, Money::of('-'.$money->amount(), 'USDT')),
                new LedgerPostingInstruction($beneficiary->id, $money),
            ]));
            $level = $snapshot[$allocation['userId']];
            CommissionAward::query()->create(['id' => $id, 'tenant_id' => $tenantId, 'funding_event_id' => $event->id,
                'user_id' => $allocation['userId'], 'level_id' => $level->level_id, 'level_revision' => $level->revision,
                'level_reward' => $level->reward, 'amount' => $money->amount(), 'asset_code' => 'USDT', 'ledger_entry_id' => $entry->id]);
        }
    }
}
