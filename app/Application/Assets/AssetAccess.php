<?php

namespace App\Application\Assets;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Assets\AssetCatalog;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Services\WalletProvisioner;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssetAccess
{
    /** Lock ownership before Ledger/Audit foreign-key work, including settled incoming funds. */
    public function settlementOwners(string $tenantId, string $userId): array
    {
        return [Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail(), User::where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail()];
    }

    public function operational(string $tenantId, string $userId): array
    {
        [$tenant, $user] = $this->settlementOwners($tenantId, $userId);
        $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', 'USDT')->first();
        if ($tenant->status->value !== 'ACTIVE' || $user->status->value !== 'ACTIVE' || $wallet?->status->value !== 'ACTIVE'
            || app(KycStatusService::class)->forUser($tenantId, $userId)->value !== 'APPROVED') {
            throw new DomainException('ASSET_ACCESS_UNAVAILABLE', 'An active verified wallet is required.', 403);
        }

        return [$tenant, $user];
    }

    public function wallet(Tenant $tenant, User $user, string $asset): Wallet
    {
        AssetCatalog::assert($asset);
        $wallet = app(WalletProvisioner::class)->provision($tenant, $user, $asset);
        if ($wallet->status->value !== 'ACTIVE') {
            throw new DomainException('ASSET_ACCESS_UNAVAILABLE', 'An active verified wallet is required.', 403);
        }

        return $wallet;
    }

    public function account(Wallet $wallet, string $type): LedgerAccount
    {
        return LedgerAccount::query()->where('tenant_id', $wallet->tenant_id)->where('wallet_id', $wallet->id)->where('user_id', $wallet->user_id)->where('asset_code', $wallet->asset_code)->where('account_type', $type)->firstOrFail();
    }

    public function companyAccount(string $tenantId, string $asset, string $type): LedgerAccount
    {
        abort_unless(in_array($type, ['TENANT_EXCHANGE_CLEARING', 'TENANT_TOPUP_CLEARING', 'TENANT_WITHDRAWAL_CLEARING', 'TENANT_FEE_REVENUE'], true), 500);
        DB::table('ledger_accounts')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'asset_code' => $asset, 'account_type' => $type, 'balance' => '0', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        return LedgerAccount::query()->where('tenant_id', $tenantId)->where('asset_code', $asset)->where('account_type', $type)->whereNull('user_id')->whereNull('wallet_id')->firstOrFail();
    }

    public function platform(AdminUser $actor, string $permission): void
    {
        $actor = $actor->fresh();
        abort_unless($actor && $actor->status->value === 'ACTIVE' && app(AuthorizationService::class)->allows($actor, ScopeType::Platform, null, $permission), 403);
    }

    public static function lock(string $key): void
    {
        DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$key]);
    }

    public static function requestId(string $id): void
    {
        if (! Str::isUuid($id)) {
            throw new DomainException('REQUEST_INVALID', 'A valid request identifier is required.');
        }
    }
}
