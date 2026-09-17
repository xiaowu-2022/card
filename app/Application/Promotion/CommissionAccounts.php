<?php

namespace App\Application\Promotion;

use App\Domain\Ledger\Enums\LedgerAccountStatus;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\User\Models\User;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Services\WalletProvisioner;
use App\Domain\Audit\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

final class CommissionAccounts
{
    /** Called only within a business transaction holding the Tenant lock. No balance mutations. */
    public function forUser(string $tenantId, string $userId): LedgerAccount
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Commission receipt requires the source transaction and Tenant lock.');
        }
        $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
        $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', 'USDT')->first();
        if (! $wallet) {
            $wallet = app(WalletProvisioner::class)->provision($tenant, $user, 'USDT');
            app(AuditLogger::class)->record($tenantId, 'SYSTEM', null, 'COMMISSION_RECEIPT_WALLET_CREATED', 'wallet', $wallet->id, null, ['asset' => 'USDT', 'user_id' => $userId]);
        }

        return LedgerAccount::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('wallet_id', $wallet->id)->where('asset_code', 'USDT')->where('account_type', LedgerAccountType::UserAvailable)->firstOrFail();
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
