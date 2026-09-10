<?php

namespace App\Application\Payment;

use App\Application\Payment\DTOs\CreatedWalletTopup;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Payment\Contracts\PaymentProviderInterface;
use App\Domain\Payment\DTOs\PaymentInitiationRequest;
use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Exceptions\PaymentProviderTimeoutException;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Domain\Wallet\Models\Wallet;
use App\Jobs\QueryPaymentStatusJob;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class CreateWalletTopupAction
{
    public function __construct(
        private PaymentProviderInterface $provider,
        private KycStatusService $kycStatus,
        private AuditLogger $audit,
    ) {}

    public function execute(string $tenantId, string $userId, string $walletId, mixed $amount, string $assetCode, string $requestId, string $returnUrl, ?string $auditRequestId = null): CreatedWalletTopup
    {
        if (! is_string($amount)) {
            throw new DomainException('TOPUP_AMOUNT_INVALID', 'Amount must be a decimal string.');
        }
        if (! Str::isUuid($requestId)) {
            throw new DomainException('TOPUP_REQUEST_ID_INVALID', 'A valid request identifier is required.');
        }
        try {
            $money = Money::of($amount, strtoupper($assetCode));
        } catch (InvalidArgumentException) {
            throw new DomainException('TOPUP_AMOUNT_INVALID', 'Enter a valid positive amount with at most 8 decimal places.');
        }
        if (! $money->isPositive()) {
            throw new DomainException('TOPUP_AMOUNT_INVALID', 'Top-up amount must be greater than zero.');
        }
        if (! $this->provider->available()) {
            throw new DomainException('PAYMENT_PROVIDER_UNAVAILABLE', 'Top-up is currently unavailable.', 503);
        }
        $requestHash = $this->requestHash($tenantId, $userId, $walletId, $money->amount(), $money->assetCode);
        $created = false;

        try {
            /** @var WalletTopupOrder $order */
            $order = DB::transaction(function () use ($tenantId, $userId, $walletId, $requestId, $requestHash, $money, $auditRequestId, &$created): WalletTopupOrder {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [$this->lockKey($tenantId, $requestId)]);
                $existing = WalletTopupOrder::query()->where('tenant_id', $tenantId)->where('request_id', $requestId)->first();
                if ($existing) {
                    if (! hash_equals($existing->request_hash, $requestHash)) {
                        throw new DomainException('IDEMPOTENCY_CONFLICT', 'This request identifier was already used with different top-up details.', 409);
                    }

                    return $existing;
                }

                $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
                $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
                $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($walletId)->firstOrFail();
                $tenant->loadMissing('businessSettings');
                if ($tenant->status !== TenantStatus::Active) {
                    throw new DomainException('TENANT_NOT_ACTIVE', 'Top-up is unavailable while this tenant is not active.', 403);
                }
                if ($user->status !== UserStatus::Active) {
                    throw new DomainException('USER_NOT_ACTIVE', 'Your account must be active to start a top-up.', 403);
                }
                if ($this->kycStatus->forUser($tenantId, $userId) !== KycUserStatus::Approved) {
                    throw new DomainException('KYC_NOT_APPROVED', 'Identity verification must be approved before topping up.', 403);
                }
                if ($wallet->status !== WalletStatus::Active) {
                    throw new DomainException('WALLET_NOT_ACTIVE', 'An active wallet is required.', 403);
                }
                if (! $tenant->businessSettings->allow_wallet_topup) {
                    throw new DomainException('WALLET_TOPUP_DISABLED', 'Top-up is not enabled for this tenant.', 403);
                }
                if ($wallet->asset_code !== $money->assetCode) {
                    throw new DomainException('TOPUP_ASSET_MISMATCH', 'Top-up asset must match the wallet asset.');
                }

                $id = (string) Str::uuid();
                $now = now();
                DB::table('wallet_topup_orders')->insert([
                    'id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'wallet_id' => $walletId,
                    'request_id' => $requestId, 'request_hash' => $requestHash, 'asset_code' => $money->assetCode,
                    'amount' => $money->amount(), 'status' => WalletTopupStatus::Pending->value,
                    'payment_provider' => $this->provider->name(), 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('payment_provider_transactions')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'wallet_topup_order_id' => $id,
                    'provider' => $this->provider->name(), 'provider_request_id' => $id,
                    'status' => PaymentProviderTransactionStatus::Pending->value, 'asset_code' => $money->assetCode,
                    'amount' => $money->amount(), 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->audit->record($tenantId, 'USER', $userId, 'WALLET_TOPUP_CREATED', 'wallet_topup_order', $id, null, [
                    'asset' => $money->assetCode, 'status' => WalletTopupStatus::Pending->value,
                ], $auditRequestId);
                $created = true;

                return WalletTopupOrder::query()->findOrFail($id);
            }, 3);
        } catch (QueryException $exception) {
            $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->where('request_id', $requestId)->first();
            if (! $order || ! hash_equals($order->request_hash, $requestHash)) {
                throw $exception;
            }
        }

        if (! $created) {
            return new CreatedWalletTopup($order, null, false);
        }

        try {
            $result = $this->provider->initiatePayment(new PaymentInitiationRequest(
                $tenantId, $order->id, $order->id, $order->amount, $order->asset_code, str_replace('__ORDER__', $order->id, $returnUrl),
            ));
            $checkoutUrl = $this->validateCheckoutUrl($result->checkoutUrl);
            DB::transaction(function () use ($tenantId, $order, $result): void {
                $locked = WalletTopupOrder::query()->where('tenant_id', $tenantId)->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $transaction = PaymentProviderTransaction::query()->where('tenant_id', $tenantId)->where('wallet_topup_order_id', $locked->id)->lockForUpdate()->firstOrFail();
                $transaction->provider_transaction_id = $result->providerTransactionId;
                $transaction->status = $result->status;
                $transaction->save();
                $locked->provider_transaction_id = $result->providerTransactionId;
                $locked->status = $result->status === PaymentProviderTransactionStatus::Failed ? WalletTopupStatus::Failed : WalletTopupStatus::Processing;
                $locked->failed_at = $result->status === PaymentProviderTransactionStatus::Failed ? now() : null;
                $locked->save();
            }, 3);
            if (in_array($result->status, [PaymentProviderTransactionStatus::Succeeded, PaymentProviderTransactionStatus::Unknown], true)) {
                $transactionId = PaymentProviderTransaction::query()->where('tenant_id', $tenantId)->where('wallet_topup_order_id', $order->id)->value('id');
                QueryPaymentStatusJob::dispatch($tenantId, $transactionId);
            }
        } catch (PaymentProviderTimeoutException) {
            $checkoutUrl = null;
            $this->markUnknown($tenantId, $order->id);
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable) {
            $checkoutUrl = null;
            $this->markUnknown($tenantId, $order->id);
        }

        return new CreatedWalletTopup($order->fresh(), $checkoutUrl, true);
    }

    private function markUnknown(string $tenantId, string $orderId): void
    {
        DB::transaction(function () use ($tenantId, $orderId): void {
            $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $transaction = PaymentProviderTransaction::query()->where('tenant_id', $tenantId)->where('wallet_topup_order_id', $orderId)->lockForUpdate()->firstOrFail();
            $transaction->status = PaymentProviderTransactionStatus::Unknown;
            $transaction->save();
            $order->status = WalletTopupStatus::Processing;
            $order->save();
        }, 3);
    }

    private function requestHash(string $tenantId, string $userId, string $walletId, string $amount, string $asset): string
    {
        $parts = ['wallet-topup-v1', $tenantId, $userId, $walletId, $amount, $asset];

        return hash('sha256', implode('', array_map(static fn (string $part): string => pack('N', strlen($part)).$part, $parts)));
    }

    private function lockKey(string $tenantId, string $requestId): int
    {
        /** @var array{high:int,low:int} $words */
        $words = unpack('Nhigh/Nlow', substr(hash('sha256', "topup-request-v1\0{$tenantId}\0{$requestId}", true), 0, 8));

        return ($words['high'] << 32) | $words['low'];
    }

    private function validateCheckoutUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, app()->environment(['local', 'testing']) ? ['http', 'https'] : ['https'], true)) {
            throw new DomainException('PAYMENT_REDIRECT_INVALID', 'Payment destination is invalid.', 502);
        }

        return $url;
    }
}
