<?php

namespace App\Application\SecurityDeposit;

use App\Application\Wallet\WalletEligibilityService;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Payment\Contracts\PaymentProviderInterface;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;

final readonly class SecurityDepositFundingQuery
{
    public function __construct(private WalletEligibilityService $eligibility, private PaymentProviderInterface $paymentProvider) {}

    /** @return array<string,mixed> */
    public function preview(string $tenantId, string $userId): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->with('businessSettings')->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $state = $this->eligibility->forUser($tenant, $user);
        if ($state['wallet'] === null || $state['available'] === null) {
            throw new DomainException('WALLET_NOT_ACTIVE', 'An active wallet is required before funding a security deposit.', 403);
        }

        $blocking = array_values(array_intersect($state['reasonCodes'], [
            'TENANT_NOT_ACTIVE', 'USER_NOT_ACTIVE', 'KYC_NOT_APPROVED', 'WALLET_NOT_ACTIVE', 'SECURITY_DEPOSIT_ASSET_MISMATCH',
        ]));
        if ($blocking !== []) {
            throw new DomainException($blocking[0], 'Security deposit funding is not currently available.', 403);
        }

        $remaining = $state['depositRemaining'];
        $available = $state['available'];
        $canFund = $remaining['amount'] !== '0.00000000'
            && Money::of($available['amount'], $available['asset'])->compare(Money::of($remaining['amount'], $remaining['asset'])) >= 0;

        return [
            'current' => $state['depositCurrent'],
            'required' => $state['depositRequired'],
            'remaining' => $remaining,
            'available' => $available,
            'availableAfter' => $canFund
                ? Money::of($available['amount'], $available['asset'])->subtract(Money::of($remaining['amount'], $remaining['asset']))->jsonSerialize()
                : null,
            'canFund' => $canFund,
            'satisfied' => $state['depositSatisfied'],
            'topupAvailable' => (bool) $tenant->businessSettings->allow_wallet_topup && $this->paymentProvider->available(),
        ];
    }
}
