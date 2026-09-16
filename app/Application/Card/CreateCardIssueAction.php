<?php

namespace App\Application\Card;

use App\Application\SecurityDeposit\RefundSecurityDepositAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Enums\CardIssueStatus;
use App\Domain\Card\Enums\ProviderCardholderStatus;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\CardProduct\Enums\CardProductStatus;
use App\Domain\CardProduct\Enums\TenantCardProductStatus;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\DTOs\IssueCardRequestDTO;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Domain\CardProvider\Exceptions\ProviderAuthenticationException;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class CreateCardIssueAction
{
    public function __construct(
        private CardProviderInterface $provider,
        private KycStatusService $kycStatus,
        private LedgerWriter $ledger,
        private ApplyCardIssueResultAction $results,
        private AuditLogger $audit,
        private CardProductProviderRouter $router,
    ) {}

    public function execute(string $tenantId, string $userId, string $requestId, string $productId, mixed $initialAmount, string $cardholderApplicationId, ?string $auditRequestId = null): CardIssueOrder
    {
        if (! Str::isUuid($requestId) || ! Str::isUuid($productId) || ! Str::isUuid($cardholderApplicationId) || ! is_string($initialAmount)
            || preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/', $initialAmount) !== 1) {
            throw new DomainException('CARD_ISSUE_REQUEST_INVALID', 'Enter a valid Card request and initial balance.');
        }
        try {
            $initial = Money::of($initialAmount, 'USDT');
        } catch (InvalidArgumentException) {
            throw new DomainException('CARD_INITIAL_LOAD_INVALID', 'Enter a valid initial Card balance.');
        }
        if (! $initial->isPositive()) {
            throw new DomainException('CARD_INITIAL_LOAD_INVALID', 'Initial Card balance must be positive.');
        }
        $requestHash = hash('sha256', "card-issue-v2\0{$tenantId}\0{$userId}\0{$productId}\0{$cardholderApplicationId}\0{$initial->amount()}");
        $existing = CardIssueOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('request_id', $requestId)->first();
        if ($existing) {
            return $this->sameRequest($existing, $requestHash);
        }
        $provider = $this->router->forProduct(CardProduct::query()->findOrFail($productId));
        if (! $provider->available() || $provider->name() !== 'PHOTONPAY') {
            throw new DomainException('CARD_PROVIDER_UNAVAILABLE', 'New Card setup is currently unavailable.', 503);
        }

        /** @var array{order:CardIssueOrder,created:bool,cardholderReference:string} $prepared */
        $prepared = DB::transaction(function () use ($tenantId, $userId, $requestId, $productId, $initial, $requestHash, $cardholderApplicationId, $auditRequestId): array {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$this->lockKey($tenantId, $userId, $requestId)]);
            $existing = CardIssueOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('request_id', $requestId)->lockForUpdate()->first();
            if ($existing) {
                return ['order' => $this->sameRequest($existing, $requestHash), 'created' => false, 'cardholderReference' => ''];
            }
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            RefundSecurityDepositAction::assertNoPending($tenantId, $userId);
            $settings = $tenant->businessSettings()->lockForUpdate()->firstOrFail();
            $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', $tenant->default_asset)->lockForUpdate()->first();
            $config = TenantCardProductConfig::query()->where('tenant_id', $tenantId)->where('card_product_id', $productId)->lockForUpdate()->first();
            $product = CardProduct::query()->whereKey($productId)->lockForUpdate()->first();
            if ($product) {
                $this->router->assertConfigured($product);
            }
            $cardholder = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->whereKey($cardholderApplicationId)->where('card_product_id', $productId)
                ->whereNotNull('request_id')->where('provider', 'PHOTONPAY')->lockForUpdate()->first();
            if ($tenant->status !== TenantStatus::Active || $user->status !== UserStatus::Active) {
                throw new DomainException('CARD_ISSUE_UNAVAILABLE', 'Opening a Card requires an active account.', 403);
            }
            if ($this->kycStatus->forUser($tenantId, $userId) !== KycUserStatus::Approved) {
                throw new DomainException('KYC_NOT_APPROVED', 'Approved identity verification is required.', 403);
            }
            if (! $wallet || $wallet->status !== WalletStatus::Active || $wallet->asset_code !== 'USDT'
                || $tenant->default_asset !== 'USDT' || $settings->required_security_deposit_asset !== 'USDT') {
                throw new DomainException('CARD_WALLET_UNAVAILABLE', 'An active USDT Wallet is required.', 409);
            }
            if (! $product || ! $config || $product->status !== CardProductStatus::Active || $config->status !== TenantCardProductStatus::Active
                || ($product->provider !== 'PHOTONPAY' && ! $this->router->isLocalMock($product) && ! $this->router->isSandbox($product)) || $product->card_currency !== 'USD' || $product->card_type !== 'REGULAR') {
                throw new DomainException('CARD_PRODUCT_UNAVAILABLE', 'This Card product is not available.', 409);
            }
            if (! $cardholder || $cardholder->status !== ProviderCardholderStatus::Ready || ! $cardholder->provider_cardholder_id) {
                throw new DomainException('CARDHOLDER_NOT_READY', 'The cardholder must be added successfully before opening a card.', 409);
            }
            LiveCardReferenceGuard::forProduct($product, $this->router->productReference($product), $cardholder->provider_cardholder_id);
            if (CardIssueOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('provider_cardholder_id', $cardholder->id)->exists()) {
                throw new DomainException('CARD_APPLICATION_USED', 'Submit new cardholder materials for each card.', 409);
            }
            $reservedCardCapacity = CardIssueOrder::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('card_product_id', $productId)
                ->where('status', '!=', CardIssueStatus::Failed->value)
                ->count();
            if ($reservedCardCapacity >= $config->max_cards_per_user) {
                throw new DomainException('CARD_LIMIT_REACHED', 'You have reached the maximum number of Cards for this product.', 409);
            }
            $minimum = Money::of($product->minimum_initial_load, 'USDT');
            if ($initial->compare($minimum) < 0) {
                throw new DomainException('CARD_INITIAL_LOAD_BELOW_MINIMUM', 'Initial Card balance must meet the product minimum.');
            }
            $accounts = LedgerAccount::query()->where('tenant_id', $tenantId)->where('wallet_id', $wallet->id)
                ->whereIn('account_type', [
                    LedgerAccountType::UserAvailable->value,
                    LedgerAccountType::UserSecurityDeposit->value,
                    LedgerAccountType::UserCardIssueHold->value,
                    LedgerAccountType::UserCardFundingHold->value,
                ])->get()->keyBy(fn (LedgerAccount $account): string => $account->account_type->value);
            foreach ([LedgerAccountType::UserAvailable, LedgerAccountType::UserSecurityDeposit, LedgerAccountType::UserCardIssueHold, LedgerAccountType::UserCardFundingHold] as $type) {
                if (! $accounts->has($type->value)) {
                    throw new DomainException('CARD_ISSUE_ACCOUNTS_UNAVAILABLE', 'Card issue accounts are unavailable.', 409);
                }
            }
            $requiredDeposit = Money::of($settings->required_security_deposit_amount, 'USDT');
            if (Money::of($accounts[LedgerAccountType::UserSecurityDeposit->value]->balance, 'USDT')->compare($requiredDeposit) < 0) {
                throw new DomainException('SECURITY_DEPOSIT_INSUFFICIENT', 'Complete the Security Deposit before opening a Card.', 409);
            }
            if ($product->opening_fee === null) {
                throw new DomainException('CARD_PRODUCT_NOT_ACTIVE', 'Card setup is currently unavailable.', 409);
            }
            $opening = Money::of($product->opening_fee, 'USDT');
            $total = $opening->add($initial);
            if (Money::of($accounts[LedgerAccountType::UserAvailable->value]->balance, 'USDT')->compare($total) < 0) {
                throw new DomainException('INSUFFICIENT_AVAILABLE_BALANCE', 'Your available Wallet balance is not enough to open this Card.');
            }
            $orderId = (string) Str::uuid();
            $order = new CardIssueOrder;
            $order->forceFill([
                'id' => $orderId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'wallet_id' => $wallet->id,
                'card_product_id' => $product->id,
                'tenant_card_product_config_id' => $config->id,
                'provider_cardholder_id' => $cardholder->id,
                'cardholder_request_id' => $cardholder->request_id,
                'request_id' => $requestId,
                'request_hash' => $requestHash,
                'opening_fee' => $opening->amount(),
                'minimum_initial_load' => $minimum->amount(),
                'initial_load_amount' => $initial->amount(),
                'wallet_asset' => 'USDT',
                'card_currency' => 'USD',
                'provider' => 'PHOTONPAY',
                'provider_product_ref' => $this->router->productReference($product),
                'provider_request_id' => $orderId,
                'status' => CardIssueStatus::Processing,
                'requested_at' => now(),
                'processing_at' => now(),
            ])->save();
            $feeEntry = null;
            if (! $opening->isZero()) {
                $feeEntry = $this->ledger->post(new LedgerPostingPlan(
                    $tenantId,
                    'USDT',
                    "card_issue:{$order->id}:fee_hold",
                    'CARD_ISSUE_FEE_HOLD',
                    'CARD_ISSUE_ORDER',
                    $order->id,
                    null,
                    [
                        new LedgerPostingInstruction($accounts[LedgerAccountType::UserAvailable->value]->id, Money::of('-'.$opening->amount(), 'USDT')),
                        new LedgerPostingInstruction($accounts[LedgerAccountType::UserCardIssueHold->value]->id, $opening),
                    ],
                ));
            }
            $fundingEntry = $this->ledger->post(new LedgerPostingPlan(
                $tenantId,
                'USDT',
                "card_issue:{$order->id}:funding_hold",
                'CARD_INITIAL_LOAD_HOLD',
                'CARD_ISSUE_ORDER',
                $order->id,
                null,
                [
                    new LedgerPostingInstruction($accounts[LedgerAccountType::UserAvailable->value]->id, Money::of('-'.$initial->amount(), 'USDT')),
                    new LedgerPostingInstruction($accounts[LedgerAccountType::UserCardFundingHold->value]->id, $initial),
                ],
            ));
            $order->forceFill(['fee_hold_ledger_entry_id' => $feeEntry?->id, 'funding_hold_ledger_entry_id' => $fundingEntry->id])->save();
            $this->audit->record($tenantId, 'USER', $userId, 'CARD_ISSUE_REQUESTED', 'card_issue_order', $order->id, null, [
                'opening_fee' => $opening->amount(),
                'initial_load_amount' => $initial->amount(),
                'wallet_asset' => 'USDT',
                'card_currency' => 'USD',
                'fee_hold_ledger_entry_id' => $feeEntry?->id,
                'funding_hold_ledger_entry_id' => $fundingEntry->id,
            ], $auditRequestId);

            return ['order' => $order, 'created' => true, 'cardholderReference' => $cardholder->provider_cardholder_id];
        }, 3);

        if (! $prepared['created']) {
            return $prepared['order'];
        }
        $order = $prepared['order'];
        try {
            $result = $provider->issueCard(new IssueCardRequestDTO(
                $order->provider_product_ref,
                $prepared['cardholderReference'],
                'USD',
                $order->initial_load_amount,
                $order->provider_request_id,
            ));
        } catch (ProviderRejectedException|ProviderAuthenticationException) {
            return $this->results->fail($tenantId, $order->id);
        } catch (Throwable) {
            return $this->results->markUnknown($tenantId, $order->id);
        }
        if (! hash_equals($order->provider_request_id, $result->providerOperationId)) {
            return $this->results->markUnknown($tenantId, $order->id);
        }

        return match ($result->status) {
            ProviderOperationStatus::Succeeded => $this->results->succeed($tenantId, $order->id, $result),
            ProviderOperationStatus::Failed => $this->results->fail($tenantId, $order->id),
            ProviderOperationStatus::Unknown => $this->results->markUnknown($tenantId, $order->id),
            default => $order->fresh(),
        };
    }

    private function sameRequest(CardIssueOrder $order, string $requestHash): CardIssueOrder
    {
        if (! hash_equals($order->request_hash, $requestHash)) {
            throw new DomainException('IDEMPOTENCY_CONFLICT', 'This request identifier was already used with different Card details.', 409);
        }

        return $order;
    }

    private function lockKey(string $tenantId, string $userId, string $requestId): int
    {
        /** @var array{high:int,low:int} $words */
        $words = unpack('Nhigh/Nlow', substr(hash('sha256', "card-issue-request-v1\0{$tenantId}\0{$userId}\0{$requestId}", true), 0, 8));

        return ($words['high'] << 32) | $words['low'];
    }
}
