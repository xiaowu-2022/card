<?php

namespace App\Application\Payment;

use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Payment\Contracts\PaymentProviderInterface;
use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Exceptions\PaymentProviderTimeoutException;
use App\Domain\Payment\Models\PaymentProviderEvent;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Payment\Services\PaymentStateTransitionPolicy;
use App\Jobs\CreditWalletTopupJob;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class QueryPaymentStatusAction
{
    public function __construct(
        private PaymentProviderInterface $provider,
        private PaymentStateTransitionPolicy $transitions,
    ) {}

    public function execute(string $tenantId, string $transactionId): void
    {
        $snapshot = PaymentProviderTransaction::query()->where('tenant_id', $tenantId)->whereKey($transactionId)->firstOrFail();
        if ($snapshot->provider !== $this->provider->name()) {
            throw new DomainException('PAYMENT_PROVIDER_MISMATCH', 'Payment provider mapping is invalid.', 409);
        }
        try {
            $result = $this->provider->queryPayment($snapshot->provider_request_id);
        } catch (PaymentProviderTimeoutException) {
            $this->markUnknown($tenantId, $transactionId);

            return;
        } catch (Throwable) {
            $this->markUnknown($tenantId, $transactionId);

            return;
        }
        if (! hash_equals($snapshot->provider_request_id, $result->providerRequestId)) {
            $this->markUnknown($tenantId, $transactionId);

            return;
        }

        $shouldCredit = DB::transaction(function () use ($tenantId, $transactionId, $result): bool {
            $transaction = PaymentProviderTransaction::query()->where('tenant_id', $tenantId)->whereKey($transactionId)->lockForUpdate()->firstOrFail();
            $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->whereKey($transaction->wallet_topup_order_id)->lockForUpdate()->firstOrFail();
            $transaction->last_queried_at = now();
            if ($order->status === WalletTopupStatus::Refunded) {
                $transaction->save();

                return false;
            }
            if ($result->status === PaymentProviderTransactionStatus::Succeeded) {
                try {
                    $resultMoney = Money::of($result->amount, $result->assetCode);
                } catch (Throwable) {
                    $transaction->status = PaymentProviderTransactionStatus::Unknown;
                    $transaction->save();

                    return false;
                }
                if ($resultMoney->amount() !== $order->amount || $resultMoney->assetCode !== $order->asset_code
                    || ($transaction->provider_transaction_id !== null && $result->providerTransactionId !== null
                        && ! hash_equals($transaction->provider_transaction_id, $result->providerTransactionId))) {
                    if ($transaction->status !== PaymentProviderTransactionStatus::Succeeded) {
                        $transaction->status = PaymentProviderTransactionStatus::Unknown;
                    }
                    $transaction->save();

                    return false;
                }
                $expiredSuccess = $order->status === WalletTopupStatus::Expired;
                if (($this->transitions->providerTransition($transaction->status, $result->status) === 'CONFLICT' && ! $expiredSuccess)
                    || ! $this->transitions->orderMayTransition($order->status, WalletTopupStatus::Paid)) {
                    $transaction->save();

                    return false;
                }
                $transaction->status = $result->status;
                $transaction->provider_transaction_id ??= $result->providerTransactionId;
                $transaction->save();
                if ($order->status !== WalletTopupStatus::Credited) {
                    $order->status = WalletTopupStatus::Paid;
                    $order->provider_transaction_id ??= $result->providerTransactionId;
                    $order->paid_at ??= now();
                    $order->save();
                }

                return $order->status === WalletTopupStatus::Paid;
            }
            if ($result->status === PaymentProviderTransactionStatus::Failed
                && $this->transitions->providerTransition($transaction->status, $result->status) !== 'CONFLICT'
                && $this->transitions->orderMayTransition($order->status, WalletTopupStatus::Failed)) {
                $verifiedSuccessPending = PaymentProviderEvent::query()
                    ->where('tenant_id', $tenantId)->where('payment_provider_transaction_id', $transaction->id)
                    ->where('normalized_status', PaymentProviderTransactionStatus::Succeeded->value)
                    ->whereIn('processing_status', ['PENDING', 'PROCESSING'])->exists();
                if ($verifiedSuccessPending) {
                    $transaction->status = PaymentProviderTransactionStatus::Unknown;
                    $transaction->save();

                    return false;
                }
                $transaction->status = PaymentProviderTransactionStatus::Failed;
                $transaction->save();
                $order->status = WalletTopupStatus::Failed;
                $order->failed_at = now();
                $order->save();

                return false;
            }
            if (! in_array($order->status, [WalletTopupStatus::Paid, WalletTopupStatus::Credited], true)) {
                if ($this->transitions->providerTransition($transaction->status, $result->status) === 'APPLY') {
                    $transaction->status = $result->status;
                    $transaction->save();
                }
                if ($this->transitions->orderMayTransition($order->status, WalletTopupStatus::Processing)) {
                    $order->status = WalletTopupStatus::Processing;
                    $order->save();
                }
            } else {
                $transaction->save();
            }

            return false;
        }, 3);

        if ($shouldCredit) {
            CreditWalletTopupJob::dispatch($tenantId, $snapshot->wallet_topup_order_id);
        }
    }

    private function markUnknown(string $tenantId, string $transactionId): void
    {
        DB::transaction(function () use ($tenantId, $transactionId): void {
            $transaction = PaymentProviderTransaction::query()->where('tenant_id', $tenantId)->whereKey($transactionId)->lockForUpdate()->firstOrFail();
            $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->whereKey($transaction->wallet_topup_order_id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, [WalletTopupStatus::Paid, WalletTopupStatus::Credited], true)
                && $this->transitions->providerTransition($transaction->status, PaymentProviderTransactionStatus::Unknown) === 'APPLY') {
                $transaction->status = PaymentProviderTransactionStatus::Unknown;
                $transaction->last_queried_at = now();
                $transaction->save();
                $order->status = WalletTopupStatus::Processing;
                $order->save();
            }
        }, 3);
    }
}
