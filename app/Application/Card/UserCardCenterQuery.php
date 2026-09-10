<?php

namespace App\Application\Card;

use App\Application\CardProduct\CardProductCatalogQuery;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;

final readonly class UserCardCenterQuery
{
    public function __construct(
        private CardProductCatalogQuery $catalog,
        private KycStatusService $kycStatus,
        private CardProviderInterface $provider,
    ) {}

    /** @return array<string,mixed> */
    public function get(string $tenantId, string $userId): array
    {
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->with('profile')->firstOrFail();
        $cardholder = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('provider', 'PHOTONPAY')->first();
        $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->first();
        $available = $wallet ? LedgerAccount::query()->where('tenant_id', $tenantId)->where('wallet_id', $wallet->id)
            ->where('account_type', LedgerAccountType::UserAvailable->value)->first() : null;

        return $this->catalog->user($tenantId, $userId) + [
            'providerAvailable' => $this->provider->available() && $this->provider->name() === 'PHOTONPAY',
            'kycApproved' => $this->kycStatus->forUser($tenantId, $userId) === KycUserStatus::Approved,
            'availableBalance' => $available?->balance,
            'walletAsset' => $wallet?->asset_code,
            'profile' => [
                'legalFirstName' => $user->profile?->legal_first_name,
                'legalLastName' => $user->profile?->legal_last_name,
                'dateOfBirth' => $user->profile?->date_of_birth?->format('Y-m-d'),
                'nationalityCountryCode' => $user->profile?->nationality_country_code,
                'residentialAddress' => $user->profile?->residential_address,
                'residentialCity' => $user->profile?->residential_city,
                'residentialState' => $user->profile?->residential_state,
                'residentialCountryCode' => $user->profile?->residential_country_code,
                'residentialPostalCode' => $user->profile?->residential_postal_code,
            ],
            'cardholder' => $cardholder ? [
                'state' => match ($cardholder->status->value) {
                    'SUBMITTING' => 'submitting',
                    'PENDING' => 'reviewing',
                    'READY' => 'ready',
                    'ACTION_REQUIRED' => 'action_required',
                    'REJECTED', 'DISABLED' => 'not_available',
                    default => 'unknown',
                },
                'safeReason' => $cardholder->safe_reason,
                'submittedAt' => $cardholder->submitted_at?->toIso8601String(),
                'syncedAt' => $cardholder->synced_at?->toIso8601String(),
            ] : ['state' => 'setup', 'safeReason' => null, 'submittedAt' => null, 'syncedAt' => null],
            'issueOrders' => CardIssueOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->with('product')->latest('created_at')->limit(10)->get()->map(fn (CardIssueOrder $order): array => [
                    'id' => $order->id,
                    'productName' => $order->product->name,
                    'openingFee' => $order->opening_fee,
                    'initialLoadAmount' => $order->initial_load_amount,
                    'state' => match ($order->status->value) {
                        'SUCCEEDED' => 'created',
                        'FAILED' => 'failed',
                        'UNKNOWN' => 'unknown',
                        default => 'creating',
                    },
                    'requestedAt' => $order->requested_at->toIso8601String(),
                ])->all(),
            'cards' => UserCard::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->with('product')->latest('created_at')->get()->map(fn (UserCard $card): array => [
                    'id' => $card->id,
                    'productName' => $card->product->name,
                    'maskedPan' => $card->masked_pan,
                    'last4' => $card->last4,
                    'expiry' => $card->expiry,
                    'currency' => $card->card_currency,
                    'balance' => $card->provider_balance,
                ])->all(),
        ];
    }
}
