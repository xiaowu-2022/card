<?php

namespace App\Application\Payment;

use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Payment\Contracts\PaymentProviderInterface;
use App\Domain\Payment\DTOs\PaymentInitiationRequest;
use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Exceptions\PaymentProviderTimeoutException;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Payment\Services\PaymentStateTransitionPolicy;
use App\Jobs\QueryPaymentStatusJob;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class InitiateWalletTopupPaymentAction
{
    public function __construct(
        private PaymentProviderInterface $provider,
        private PaymentStateTransitionPolicy $transitions,
    ) {}

    public function execute(string $tenantId, string $orderId, string $returnUrl): ?string
    {
        $claimed = DB::transaction(function () use ($tenantId, $orderId): ?PaymentProviderTransaction {
            $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $transaction = PaymentProviderTransaction::query()->where('tenant_id', $tenantId)
                ->where('wallet_topup_order_id', $orderId)->lockForUpdate()->firstOrFail();

            if ($transaction->provider !== $this->provider->name() || ! $this->provider->available()) {
                throw new DomainException('PAYMENT_PROVIDER_UNAVAILABLE', 'Top-up is currently unavailable.', 503);
            }
            if ($transaction->provider_transaction_id !== null || in_array($transaction->status, [PaymentProviderTransactionStatus::Succeeded, PaymentProviderTransactionStatus::Failed], true)) {
                return null;
            }
            if ($transaction->initiation_lease_expires_at?->isFuture()) {
                return null;
            }

            $transaction->initiation_attempted_at = now();
            $transaction->initiation_lease_expires_at = now()->addSeconds((int) config('payment.initiation_lease_seconds'));
            $transaction->status = PaymentProviderTransactionStatus::Processing;
            $transaction->save();
            if ($this->transitions->orderMayTransition($order->status, WalletTopupStatus::Processing)) {
                $order->status = WalletTopupStatus::Processing;
                $order->save();
            }

            return $transaction;
        }, 3);

        if (! $claimed) {
            return null;
        }

        try {
            $result = $this->provider->initiatePayment(new PaymentInitiationRequest(
                $tenantId,
                $orderId,
                $claimed->provider_request_id,
                $claimed->amount,
                $claimed->asset_code,
                str_replace('__ORDER__', $orderId, $returnUrl),
            ));
            try {
                $resultMoney = Money::of($result->amount, $result->assetCode);
            } catch (Throwable) {
                $this->markUnknown($tenantId, $orderId);
                throw new DomainException('PAYMENT_PROVIDER_RESPONSE_INVALID', 'Payment provider response is invalid.', 502);
            }
            if (! hash_equals($claimed->provider_request_id, $result->providerRequestId)
                || $resultMoney->amount() !== $claimed->amount || $resultMoney->assetCode !== $claimed->asset_code) {
                $this->markUnknown($tenantId, $orderId);
                throw new DomainException('PAYMENT_PROVIDER_RESPONSE_MISMATCH', 'Payment provider response requires review.', 502);
            }
            $checkoutUrl = $this->validateCheckoutUrl($result->checkoutUrl, str_replace('__ORDER__', $orderId, $returnUrl));

            DB::transaction(function () use ($tenantId, $orderId, $result): void {
                $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->lockForUpdate()->firstOrFail();
                $transaction = PaymentProviderTransaction::query()->where('tenant_id', $tenantId)
                    ->where('wallet_topup_order_id', $orderId)->lockForUpdate()->firstOrFail();
                $transaction->initiation_lease_expires_at = null;
                $decision = $this->transitions->providerTransition($transaction->status, $result->status);
                $transaction->provider_transaction_id ??= $result->providerTransactionId;
                if ($decision === 'APPLY' || $decision === 'UNCHANGED') {
                    $transaction->status = $result->status;
                    $transaction->save();
                    if ($result->status === PaymentProviderTransactionStatus::Failed && $this->transitions->orderMayTransition($order->status, WalletTopupStatus::Failed)) {
                        $order->status = WalletTopupStatus::Failed;
                        $order->failed_at = now();
                        $order->save();
                    }
                } else {
                    $transaction->save();
                }
            }, 3);

            if (in_array($result->status, [PaymentProviderTransactionStatus::Succeeded, PaymentProviderTransactionStatus::Unknown], true)) {
                QueryPaymentStatusJob::dispatch($tenantId, $claimed->id);
            }

            return $checkoutUrl;
        } catch (PaymentProviderTimeoutException) {
            $this->markUnknown($tenantId, $orderId);

            return null;
        } catch (DomainException $exception) {
            $this->releaseLease($tenantId, $orderId);
            throw $exception;
        } catch (Throwable) {
            $this->markUnknown($tenantId, $orderId);

            return null;
        }
    }

    private function markUnknown(string $tenantId, string $orderId): void
    {
        DB::transaction(function () use ($tenantId, $orderId): void {
            $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $transaction = PaymentProviderTransaction::query()->where('tenant_id', $tenantId)
                ->where('wallet_topup_order_id', $orderId)->lockForUpdate()->firstOrFail();
            $transaction->status = PaymentProviderTransactionStatus::Unknown;
            $transaction->initiation_lease_expires_at = null;
            $transaction->save();
            if ($this->transitions->orderMayTransition($order->status, WalletTopupStatus::Processing)) {
                $order->status = WalletTopupStatus::Processing;
                $order->save();
            }
        }, 3);
    }

    private function releaseLease(string $tenantId, string $orderId): void
    {
        PaymentProviderTransaction::query()->where('tenant_id', $tenantId)->where('wallet_topup_order_id', $orderId)
            ->update(['initiation_lease_expires_at' => null, 'updated_at' => now()]);
    }

    private function validateCheckoutUrl(?string $url, string $returnUrl): ?string
    {
        if ($url === null) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $returnHost = strtolower((string) parse_url($returnUrl, PHP_URL_HOST));
        $allowedHosts = array_filter(array_map('trim', explode(',', (string) config('payment.checkout_hosts'))));
        $schemeAllowed = in_array($scheme, app()->environment(['local', 'testing']) ? ['http', 'https'] : ['https'], true);
        $hostAllowed = app()->environment(['local', 'testing']) ? hash_equals($returnHost, $host) : in_array($host, $allowedHosts, true);
        if (! $schemeAllowed || $host === '' || ! $hostAllowed) {
            throw new DomainException('PAYMENT_REDIRECT_INVALID', 'Payment destination is invalid.', 502);
        }

        return $url;
    }
}
