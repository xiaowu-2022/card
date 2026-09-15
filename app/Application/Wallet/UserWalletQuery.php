<?php

namespace App\Application\Wallet;

use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;

final readonly class UserWalletQuery
{
    public function __construct(private WalletEligibilityService $eligibility, private BlockchainGatewayInterface $blockchainGateway) {}

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $userId): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->with('businessSettings')->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $eligibility = $this->eligibility->forUser($tenant, $user);
        $activity = $eligibility['wallet'] === null ? [] : app(WalletActivityQuery::class)->get($tenantId, $userId);

        return [
            'transferAvailable' => $eligibility['wallet'] !== null
                && $eligibility['tenantStatus'] === 'ACTIVE' && $eligibility['userStatus'] === 'ACTIVE'
                && $eligibility['kycStatus'] === 'APPROVED' && $eligibility['walletStatus'] === 'ACTIVE'
                && $eligibility['wallet']['asset'] === $tenant->default_asset,
            'eligibility' => $eligibility,
            'activity' => $activity,
            'topupAvailable' => $eligibility['wallet'] !== null
                && $eligibility['userStatus'] === 'ACTIVE'
                && $eligibility['tenantStatus'] === 'ACTIVE'
                && $eligibility['wallet']['asset'] === 'USDT'
                && $tenant->businessSettings->allow_wallet_topup
                && $this->blockchainGateway->available()
                && (string) config('payment.trc20_deposit_address') !== ''
                && (string) config('payment.trc20_token_contract') !== '',
            'depositFundingAvailable' => $eligibility['wallet'] !== null
                && $eligibility['userStatus'] === 'ACTIVE'
                && $eligibility['tenantStatus'] === 'ACTIVE'
                && $eligibility['kycStatus'] === 'APPROVED'
                && $eligibility['walletStatus'] === 'ACTIVE'
                && ! in_array('SECURITY_DEPOSIT_ASSET_MISMATCH', $eligibility['reasonCodes'], true)
                && ! $eligibility['depositSatisfied'],
            'depositHasEnoughAvailable' => $eligibility['available'] !== null
                && ! in_array('SECURITY_DEPOSIT_ASSET_MISMATCH', $eligibility['reasonCodes'], true)
                && Money::of($eligibility['available']['amount'], $eligibility['available']['asset'])
                    ->compare(Money::of($eligibility['depositRemaining']['amount'], $eligibility['depositRemaining']['asset'])) >= 0,
            'withdrawalAvailable' => $eligibility['wallet'] !== null
                && $eligibility['wallet']['asset'] === 'USDT'
                && $eligibility['walletStatus'] === 'ACTIVE'
                && $eligibility['tenantStatus'] === 'ACTIVE'
                && $eligibility['userStatus'] === 'ACTIVE'
                && $eligibility['kycStatus'] === 'APPROVED'
                && (bool) $tenant->businessSettings->allow_withdrawal,
        ];
    }
}
