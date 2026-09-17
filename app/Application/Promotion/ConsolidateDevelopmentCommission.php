<?php

namespace App\Application\Promotion;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ConsolidateDevelopmentCommission
{
    public function __construct(private CommissionAccounts $accounts, private LedgerWriter $ledger, private AuditLogger $audit) {}

    public function assertDevelopment(): void
    {
        if (! app()->environment(['local', 'testing']) || ! in_array(DB::connection()->getDatabaseName(), ['card_mock', 'card_ui_test'], true)) {
            throw new \RuntimeException('Commission consolidation is restricted to isolated development databases.');
        }
    }

    public function execute(string $tenant, string $sourceId): ?object
    {
        $this->assertDevelopment();
        return DB::transaction(function () use ($tenant, $sourceId) {
            Tenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();
            $source = LedgerAccount::query()->where('tenant_id', $tenant)->whereKey($sourceId)->where('account_type', 'USER_COMMISSION')->where('asset_code', 'USDT')->firstOrFail();
            User::query()->where('tenant_id', $tenant)->whereKey($source->user_id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('commission_balance_consolidations')->where('tenant_id', $tenant)->where('source_account_id', $sourceId)->first();
            if ($existing) { return $existing; }
            $amount = Money::of($source->balance, 'USDT');
            if (! $amount->isPositive()) { return null; }
            $destination = $this->accounts->forUser($tenant, $source->user_id);
            $id = (string) Str::uuid();
            $entry = $this->ledger->post(new LedgerPostingPlan($tenant, 'USDT', 'commission_consolidation:'.$sourceId,
                'COMMISSION_BALANCE_CONSOLIDATED', 'COMMISSION_CONSOLIDATION', $id, null, [
                    new LedgerPostingInstruction($sourceId, Money::of('-'.$amount->amount(), 'USDT')),
                    new LedgerPostingInstruction($destination->id, $amount),
                ]));
            DB::table('commission_balance_consolidations')->insert(['id' => $id, 'tenant_id' => $tenant, 'user_id' => $source->user_id,
                'source_account_id' => $sourceId, 'destination_account_id' => $destination->id, 'amount' => $amount->amount(),
                'asset_code' => 'USDT', 'ledger_entry_id' => $entry->id, 'processed_at' => now()]);
            $this->audit->record($tenant, 'SYSTEM', null, 'COMMISSION_BALANCE_CONSOLIDATED', 'commission_consolidation', $id, null,
                ['amount' => $amount->amount(), 'asset' => 'USDT', 'source_account_id' => $sourceId, 'destination_account_id' => $destination->id, 'ledger_entry_id' => $entry->id]);
            return DB::table('commission_balance_consolidations')->where('tenant_id', $tenant)->where('id', $id)->firstOrFail();
        }, 3);
    }
}
