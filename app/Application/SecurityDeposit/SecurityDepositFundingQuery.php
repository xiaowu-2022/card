<?php

namespace App\Application\SecurityDeposit;

use App\Application\Wallet\WalletEligibilityService;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\SecurityDeposit\Models\SecurityDepositRefundRequest;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class SecurityDepositFundingQuery
{
    public function __construct(private WalletEligibilityService $eligibility, private BlockchainGatewayInterface $gateway) {}

    /** @return array<string,mixed> */
    public function preview(string $tenantId, string $userId): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->with('businessSettings')->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $state = $this->eligibility->forUser($tenant, $user);
        if (! $state['canActivate'] && ($state['wallet'] === null || $state['available'] === null)) {
            throw new DomainException('WALLET_NOT_ACTIVE', 'An active wallet is required before funding a security deposit.', 403);
        }

        $blocking = array_values(array_intersect($state['reasonCodes'], [
            'TENANT_NOT_ACTIVE', 'USER_NOT_ACTIVE', 'KYC_NOT_APPROVED', 'WALLET_NOT_ACTIVE', 'SECURITY_DEPOSIT_ASSET_MISMATCH',
        ]));
        if ($state['canActivate']) {
            $blocking = array_values(array_diff($blocking, ['WALLET_NOT_ACTIVE']));
        }
        if ($blocking !== []) {
            throw new DomainException($blocking[0], 'Security deposit funding is not currently available.', 403);
        }

        $remaining = $state['depositRemaining'];
        $available = $state['available'] ?? Money::of('0', $remaining['asset'])->jsonSerialize();
        $canFund = $remaining['amount'] !== '0.00000000'
            && Money::of($available['amount'], $available['asset'])->compare(Money::of($remaining['amount'], $remaining['asset'])) >= 0;
        $pending = SecurityDepositRefundRequest::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'CHECKING')->first();

        return [
            'current' => $state['depositCurrent'],
            'required' => $state['depositRequired'],
            'remaining' => $remaining,
            'available' => $available,
            'availableAfter' => $canFund
                ? Money::of($available['amount'], $available['asset'])->subtract(Money::of($remaining['amount'], $remaining['asset']))->jsonSerialize()
                : null,
            'canFund' => $canFund && $pending === null,
            'refund' => ['pendingId' => $pending?->id,
                'canRequest' => $pending === null && $tenant->businessSettings->security_deposit_refund_wait_days !== null && $available['asset'] === 'USDT' && Money::of($state['depositCurrent']['amount'], 'USDT')->isPositive(),
                'waitDays' => $pending?->refund_wait_days ?? $tenant->businessSettings->security_deposit_refund_wait_days,
                'eligibleAt' => $pending?->refund_eligible_at?->toIso8601String(),
                'serverNow' => now()->toIso8601String(),
                'progress' => $pending === null ? null : ($pending->refund_wait_days === null ? 'legacy' : $pending->progress),
                'cancelling' => $pending?->cancel_requested_at !== null],
            'satisfied' => $state['depositSatisfied'],
            // Round the editable top-up minimum upward; never round the deposit funding amount.
            'minimumTopup' => ['amount' => (string) BigDecimal::of($remaining['amount'])->toScale(2, RoundingMode::Ceiling)->toScale(8), 'asset' => $remaining['asset']],
            'topupAvailable' => $pending === null && ! $state['depositSatisfied']
                && $tenant->default_asset === 'USDT' && $remaining['asset'] === 'USDT'
                && (bool) $tenant->businessSettings->allow_wallet_topup && $this->gateway->available()
                && (string) config('payment.trc20_deposit_address') !== ''
                && (string) config('payment.trc20_token_contract') !== '',
        ];
    }
}
