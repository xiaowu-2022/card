<?php

namespace App\Domain\Ledger\Services;

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class LedgerReconciliationService
{
    /** @return list<array{tenant_id:string,account_id:string,account_type:string,asset:string,cached_balance:string,posting_balance:string,difference:string}> */
    public function mismatches(?string $tenantId = null, ?string $accountId = null): array
    {
        $query = LedgerAccount::query()
            ->leftJoin('ledger_postings', 'ledger_postings.ledger_account_id', '=', 'ledger_accounts.id')
            ->leftJoin('ledger_entries', function ($join): void {
                $join->on('ledger_entries.id', '=', 'ledger_postings.ledger_entry_id')->whereNotNull('ledger_entries.sealed_at');
            })
            ->select([
                'ledger_accounts.id', 'ledger_accounts.tenant_id', 'ledger_accounts.account_type',
                'ledger_accounts.asset_code', 'ledger_accounts.balance',
                DB::raw('COALESCE(SUM(CASE WHEN ledger_entries.id IS NOT NULL THEN ledger_postings.delta ELSE 0 END), 0)::numeric(38,18) AS posting_balance'),
            ])
            ->groupBy('ledger_accounts.id');
        $this->scope($query, $tenantId, $accountId);

        return $query->get()->map(function (LedgerAccount $account): ?array {
            $cached = Money::of($account->balance, $account->asset_code);
            $posted = Money::of((string) $account->getAttribute('posting_balance'), $account->asset_code);
            if ($cached->compare($posted) === 0) {
                return null;
            }

            return [
                'tenant_id' => $account->tenant_id,
                'account_id' => $account->id,
                'account_type' => $account->account_type->value,
                'asset' => $account->asset_code,
                'cached_balance' => $cached->amount(),
                'posting_balance' => $posted->amount(),
                'difference' => $cached->subtract($posted)->amount(),
            ];
        })->filter()->values()->all();
    }

    private function scope(Builder $query, ?string $tenantId, ?string $accountId): void
    {
        if ($tenantId !== null) {
            $query->where('ledger_accounts.tenant_id', $tenantId);
        }
        if ($accountId !== null) {
            $query->where('ledger_accounts.id', $accountId);
        }
    }
}
