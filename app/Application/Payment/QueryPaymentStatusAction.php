<?php

namespace App\Application\Payment;

use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Payment\Contracts\PaymentProviderInterface;
use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Exceptions\PaymentProviderTimeoutException;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Jobs\CreditWalletTopupJob;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class QueryPaymentStatusAction
{
    public function __construct(private PaymentProviderInterface $provider) {}

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
                    $resultAmount = Money::of($result->amount, $result->assetCode)->amount();
                } catch (Throwable) {
                    $transaction->status = PaymentProviderTransactionStatus::Unknown;
                    $transaction->save();

                    return false;
                }
                if ($resultAmount !== $order->amount || strtoupper($result->assetCode) !== $order->asset_code) {
                    $transaction->status = PaymentProviderTransactionStatus::Unknown;
                    $transaction->save();

                    return false;
                }
                $transaction->status = PaymentProviderTransactionStatus::Succeeded;
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
            if ($result->status === PaymentProviderTransactionStatus::Failed && ! in_array($order->status, [WalletTopupStatus::Paid, WalletTopupStatus::Credited], true)) {
                $transaction->status = PaymentProviderTransactionStatus::Failed;
                $transaction->save();
                $order->status = WalletTopupStatus::Failed;
                $order->failed_at = now();
                $order->save();

                return false;
            }
            if (! in_array($order->status, [WalletTopupStatus::Paid, WalletTopupStatus::Credited], true)) {
                $transaction->status = $result->status;
                $transaction->save();
                $order->status = WalletTopupStatus::Processing;
                $order->save();
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
            if (! in_array($order->status, [WalletTopupStatus::Paid, WalletTopupStatus::Credited], true)) {
                $transaction->status = PaymentProviderTransactionStatus::Unknown;
                $transaction->last_queried_at = now();
                $transaction->save();
                $order->status = WalletTopupStatus::Processing;
                $order->save();
            }
        }, 3);
    }
}
