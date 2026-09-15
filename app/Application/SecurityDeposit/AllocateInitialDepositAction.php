<?php

namespace App\Application\SecurityDeposit;

use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\SecurityDeposit\Models\InitialDepositIntent;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class AllocateInitialDepositAction
{
    public function __construct(private SecurityDepositFundingQuery $query, private FundSecurityDepositAction $fund) {}

    public function execute(string $tenantId, string $intentId): void
    {
        DB::transaction(function () use ($tenantId, $intentId): void {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $snapshot = InitialDepositIntent::query()->where('tenant_id', $tenantId)->whereKey($intentId)->firstOrFail();
            User::query()->where('tenant_id', $tenantId)->whereKey($snapshot->user_id)->lockForUpdate()->firstOrFail();
            $intent = InitialDepositIntent::query()->where('tenant_id', $tenantId)->whereKey($intentId)->lockForUpdate()->firstOrFail();
            if ($intent->status === 'DONE') {
                return;
            }
            $topup = WalletTopupOrder::query()->where('tenant_id', $tenantId)->where('user_id', $intent->user_id)->whereKey($intent->topup_id)->where('status', 'CREDITED')->firstOrFail();
            if (LedgerEntry::query()->where('tenant_id', $tenantId)->where('event_type', 'SECURITY_DEPOSIT_FUND')->where('reference_id', $topup->wallet_id)->exists()) {
                $intent->update(['status' => 'DONE']);

                return;
            }
            $preview = $this->query->preview($tenantId, $intent->user_id);
            if (! $preview['canFund']) {
                return;
            }
            $this->fund->execute($tenantId, $intent->user_id, $intent->id, $preview['remaining']['amount']);
            $intent->update(['status' => 'DONE']);
        }, 3);
    }
}
