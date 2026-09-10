<?php

namespace App\Application\Wallet;

use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Payment\Contracts\PaymentProviderInterface;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;

final readonly class UserWalletQuery
{
    public function __construct(private WalletEligibilityService $eligibility, private PaymentProviderInterface $paymentProvider) {}

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $userId): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->with('businessSettings')->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $eligibility = $this->eligibility->forUser($tenant, $user);
        $activity = $eligibility['wallet'] === null ? [] : LedgerEntry::query()
            ->where('ledger_entries.tenant_id', $tenantId)
            ->whereNotNull('ledger_entries.sealed_at')
            ->whereHas('postings.account', fn ($query) => $query->where('ledger_accounts.user_id', $userId))
            ->with(['postings.account'])->latest('posted_at')->limit(20)->get()->map(function (LedgerEntry $entry) use ($userId): array {
                $posting = $entry->postings->first(fn ($item) => $item->account?->user_id === $userId && $item->account?->account_type === LedgerAccountType::UserAvailable);

                return [
                    'id' => $entry->id,
                    'eventType' => $entry->event_type,
                    'asset' => $entry->asset_code,
                    'amount' => $posting?->delta,
                    'postedAt' => $entry->posted_at->toIso8601String(),
                ];
            })->all();

        return [
            'eligibility' => $eligibility,
            'activity' => $activity,
            'topupAvailable' => $eligibility['wallet'] !== null
                && $eligibility['userStatus'] === 'ACTIVE'
                && $eligibility['tenantStatus'] === 'ACTIVE'
                && $tenant->businessSettings->allow_wallet_topup
                && $this->paymentProvider->available(),
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
        ];
    }
}
