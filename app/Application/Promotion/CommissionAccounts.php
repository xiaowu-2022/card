<?php

namespace App\Application\Promotion;

use App\Domain\Ledger\Enums\LedgerAccountStatus;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\User\Models\User;

final class CommissionAccounts
{
    /** Called only within a business transaction holding the Tenant lock. No balance mutations. */
    public function forUser(string $tenantId, string $userId): LedgerAccount
    {
        User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();

        $identity = [
            'tenant_id' => $tenantId, 'user_id' => $userId, 'wallet_id' => null,
            'asset_code' => 'USDT', 'account_type' => LedgerAccountType::UserCommission,
        ];

        return LedgerAccount::query()->where($identity)->first() ?? LedgerAccount::query()->forceCreate([...$identity, 'status' => LedgerAccountStatus::Active])->refresh();
    }

    public function company(string $tenantId): LedgerAccount
    {
        $identity = [
            'tenant_id' => $tenantId, 'user_id' => null, 'wallet_id' => null,
            'asset_code' => 'USDT', 'account_type' => LedgerAccountType::TenantCommissionClearing,
        ];

        return LedgerAccount::query()->where($identity)->first() ?? LedgerAccount::query()->forceCreate([...$identity, 'status' => LedgerAccountStatus::Active])->refresh();
    }
}
