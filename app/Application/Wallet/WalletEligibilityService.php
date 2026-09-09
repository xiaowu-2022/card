<?php

namespace App\Application\Wallet;

use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Domain\Wallet\Models\Wallet;

final readonly class WalletEligibilityService
{
    public function __construct(private KycStatusService $kycStatus) {}

    /** @return array<string, mixed> */
    public function forUser(Tenant $tenant, User $user): array
    {
        $kycStatus = $this->kycStatus->forUser($tenant->id, $user->id);
        $tenantAsset = strtoupper((string) $tenant->default_asset);
        $wallet = Wallet::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
        $requiredAsset = strtoupper((string) $tenant->businessSettings->required_security_deposit_asset);
        $required = Money::of($tenant->businessSettings->required_security_deposit_amount, $requiredAsset);
        $deposit = Money::of('0', $requiredAsset);
        $available = null;
        $assetMismatch = $wallet !== null && ($wallet->asset_code !== $requiredAsset || $wallet->asset_code !== $tenantAsset);
        if ($wallet) {
            $accounts = LedgerAccount::query()->where('tenant_id', $tenant->id)->where('wallet_id', $wallet->id)->get()->keyBy(fn (LedgerAccount $account): string => $account->account_type->value);
            $availableAccount = $accounts->get(LedgerAccountType::UserAvailable->value);
            $depositAccount = $accounts->get(LedgerAccountType::UserSecurityDeposit->value);
            $available = $availableAccount ? Money::of($availableAccount->balance, $availableAccount->asset_code) : null;
            if (! $assetMismatch && $depositAccount && $depositAccount->asset_code === $requiredAsset) {
                $deposit = Money::of($depositAccount->balance, $requiredAsset);
            }
        }
        $remaining = $required->subtract($deposit);
        if ($remaining->isNegative()) {
            $remaining = Money::of('0', $requiredAsset);
        }

        $reasons = [];
        if ($tenant->status !== TenantStatus::Active) {
            $reasons[] = 'TENANT_NOT_ACTIVE';
        }
        if ($user->status !== UserStatus::Active) {
            $reasons[] = 'USER_NOT_ACTIVE';
        }
        if ($kycStatus !== KycUserStatus::Approved) {
            $reasons[] = 'KYC_NOT_APPROVED';
        }
        if (! $wallet || $wallet->status !== WalletStatus::Active) {
            $reasons[] = 'WALLET_NOT_ACTIVE';
        }
        if ($assetMismatch) {
            $reasons[] = 'SECURITY_DEPOSIT_ASSET_MISMATCH';
        } elseif ($deposit->compare($required) < 0) {
            $reasons[] = 'SECURITY_DEPOSIT_INSUFFICIENT';
        }
        $reasons[] = 'CARD_SERVICE_NOT_CONFIGURED';

        return [
            'userStatus' => $user->status->value,
            'tenantStatus' => $tenant->status->value,
            'kycStatus' => $kycStatus->value,
            'walletStatus' => $wallet?->status->value,
            'canActivate' => $tenant->status === TenantStatus::Active && $user->status === UserStatus::Active && $kycStatus === KycUserStatus::Approved && $wallet === null,
            'depositSatisfied' => ! $assetMismatch && $deposit->compare($required) >= 0,
            'canUseCardService' => false,
            'reasonCodes' => $reasons,
            'wallet' => $wallet ? ['id' => $wallet->id, 'asset' => $wallet->asset_code, 'status' => $wallet->status->value] : null,
            'available' => $available?->jsonSerialize(),
            'depositCurrent' => $deposit->jsonSerialize(),
            'depositRequired' => $required->jsonSerialize(),
            'depositRemaining' => $remaining->jsonSerialize(),
        ];
    }
}
