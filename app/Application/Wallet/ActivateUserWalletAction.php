<?php

namespace App\Application\Wallet;

use App\Application\Wallet\DTOs\WalletActivationResult;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Services\WalletProvisioner;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ActivateUserWalletAction
{
    public function __construct(
        private KycStatusService $kycStatus,
        private WalletProvisioner $provisioner,
        private AuditLogger $audit,
    ) {}

    public function execute(string $tenantId, string $userId, ?string $requestId = null): WalletActivationResult
    {
        return DB::transaction(function () use ($tenantId, $userId, $requestId): WalletActivationResult {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();

            if ($tenant->status !== TenantStatus::Active) {
                throw new DomainException('TENANT_NOT_ACTIVE', 'Wallet activation is unavailable while this tenant is not active.', 403);
            }
            if ($user->status !== UserStatus::Active) {
                throw new DomainException('USER_NOT_ACTIVE', 'Your account must be active to activate a wallet.', 403);
            }

            $assetCode = strtoupper(trim($tenant->default_asset));
            $existing = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->first();
            if ($existing) {
                if ($existing->asset_code !== $assetCode) {
                    throw new DomainException('WALLET_ASSET_MISMATCH', 'The existing Wallet asset does not match the Tenant financial asset.', 409);
                }

                return new WalletActivationResult($existing, false);
            }
            if ($this->kycStatus->forUser($tenantId, $userId) !== KycUserStatus::Approved) {
                throw new DomainException('KYC_NOT_APPROVED', 'Identity verification must be approved before wallet activation.', 403);
            }

            $wallet = $this->provisioner->provision($tenant, $user, $assetCode);
            $this->audit->record($tenantId, 'USER', $userId, 'USER_WALLET_ACTIVATED', 'wallet', $wallet->id, null, [
                'asset' => $assetCode,
                'status' => $wallet->status->value,
            ], $requestId);

            return new WalletActivationResult($wallet, true);
        }, 3);
    }
}
