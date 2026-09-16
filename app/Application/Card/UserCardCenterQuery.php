<?php

namespace App\Application\Card;

use App\Application\CardProduct\CardProductCatalogQuery;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\ProviderReference;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\SecurityDeposit\Services\RefundCardPolicy;
use App\Domain\Wallet\Models\Wallet;
use App\Infrastructure\Providers\Card\LocalCardSimulation;

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
        $cardholder = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('provider', 'PHOTONPAY')->whereNotNull('request_id')
            ->when(! app()->environment('testing') && ! LocalCardSimulation::active(), fn ($query) => $query->withoutTestReferences())
            ->whereNotIn('id', CardIssueOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->select('provider_cardholder_id'))
            ->latest('created_at')->first();
        $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', 'USDT')->first();
        $available = $wallet ? LedgerAccount::query()->where('tenant_id', $tenantId)->where('wallet_id', $wallet->id)
            ->where('account_type', LedgerAccountType::UserAvailable->value)->first() : null;

        $pendingOperations = CardManagementOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->whereIn('status', ['QUOTING', 'QUOTED', 'PROCESSING', 'UNKNOWN'])
            ->where(fn ($q) => $q->where('status', '!=', 'QUOTED')->orWhere('quote_expires_at', '>', now()))
            ->selectRaw('card_id, COUNT(*) AS count')->groupBy('card_id')->pluck('count', 'card_id');

        $providerAvailable = $this->provider->available() && $this->provider->name() === 'PHOTONPAY';
        if (config('card-provider.driver') === 'directory') {
            $providerAvailable = CardProduct::query()->where(fn ($query) => $query
                ->whereIn('id', TenantCardProductConfig::query()->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->select('card_product_id'))
                ->orWhereIn('id', UserCard::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->select('card_product_id')))
                ->with('cardProviderReference')->get()->contains(fn ($product) => app(CardProductProviderRouter::class)->forProduct($product)->available());
        }

        return $this->catalog->user($tenantId, $userId) + [
            'refundPending' => RefundCardPolicy::blocked($tenantId, $userId),
            'providerAvailable' => $providerAvailable,
            'kycApproved' => $this->kycStatus->forUser($tenantId, $userId) === KycUserStatus::Approved,
            'availableBalance' => $available?->balance,
            'walletAsset' => $wallet?->asset_code,
            'cardholder' => $cardholder ? [
                'id' => $cardholder->id,
                'requestId' => $cardholder->request_id,
                'productId' => $cardholder->card_product_id,
                'canSync' => $cardholder->provider_cardholder_id !== null && $cardholder->status->value !== 'SUBMITTING',
                'state' => match ($cardholder->status->value) {
                    'SUBMITTING' => 'submitting',
                    'PENDING' => 'submitting',
                    'READY' => 'ready',
                    'ACTION_REQUIRED' => 'action_required',
                    'REJECTED', 'DISABLED' => 'not_available',
                    default => 'unknown',
                },
                'safeReason' => $cardholder->safe_reason,
                'submittedAt' => $cardholder->submitted_at?->toIso8601String(),
                'syncedAt' => $cardholder->synced_at?->toIso8601String(),
            ] : ['state' => 'setup', 'id' => null, 'requestId' => null, 'productId' => null, 'canSync' => false, 'safeReason' => null, 'submittedAt' => null, 'syncedAt' => null],
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
                ->whereNull('archived_at')
                ->with('product')->latest('created_at')->get()->map(fn (UserCard $card): array => [
                    'id' => $card->id,
                    'productName' => $card->product->name,
                    'maskedPan' => $card->masked_pan,
                    'state' => match ($card->provider_status) {
                        'normal' => 'Normal', 'frozen' => 'Frozen', 'expired' => 'Expired', default => 'Awaiting confirmation'
                    },
                    'pendingOperationCount' => (int) ($pendingOperations[$card->id] ?? 0),
                    'last4' => $card->last4,
                    'expiry' => $card->expiry,
                    'currency' => $card->card_currency,
                    'balance' => $card->provider_balance,
                    'syncedAt' => $card->provider_balance_synced_at?->toIso8601String(),
                    'minimumReload' => $card->product->minimum_reload,
                    'refundLocked' => RefundCardPolicy::blocked($tenantId, $userId, $card->id),
                    'management' => app(CardProductProviderRouter::class)->forCard($card)->available()
                        && (! ProviderReference::isTest($card->provider_card_id) || LocalCardSimulation::allowsCard($card->provider_card_id))
                        ? (RefundCardPolicy::blocked($tenantId, $userId, $card->id) ? ['transactions'] : match ($card->provider_status) {
                            'normal' => ['reveal', 'transactions', 'holder', 'load', 'return', 'freeze', 'cancel'],
                            'frozen' => ['reveal', 'transactions', 'holder', 'return', 'unfreeze', 'cancel'],
                            'expired' => ['transactions', 'cancel'], default => ['transactions'],
                        }) : [],
                ])->all(),
        ];
    }
}
