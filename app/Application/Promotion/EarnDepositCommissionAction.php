<?php

namespace App\Application\Promotion;

use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Promotion\Models\PromotionFundingEvent;
use Illuminate\Support\Facades\DB;

final readonly class EarnDepositCommissionAction
{
    public function __construct(private PaidPromotionRewards $rewards) {}

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
        $this->rewards->execute($tenantId, $userId, 'ACTIVATION', $event->id, $amount);
    }
}
