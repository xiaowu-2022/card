<?php

namespace App\Application\Payment;

use App\Domain\Payment\Enums\PaymentEventProcessingStatus;
use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Models\PaymentProviderEvent;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Payment\Services\PaymentStateTransitionPolicy;
use App\Jobs\CreditWalletTopupJob;
use Illuminate\Support\Facades\DB;

final readonly class ProcessPaymentProviderEventAction
{
    public function __construct(private PaymentStateTransitionPolicy $transitions) {}

    public function execute(string $tenantId, string $eventId): void
    {
        $shouldCredit = DB::transaction(function () use ($tenantId, $eventId): bool {
            $event = PaymentProviderEvent::query()->where('tenant_id', $tenantId)->whereKey($eventId)->lockForUpdate()->firstOrFail();
            if (in_array($event->processing_status, [PaymentEventProcessingStatus::Processed, PaymentEventProcessingStatus::RequiresReview], true)) {
                return false;
            }
            $event->processing_status = PaymentEventProcessingStatus::Processing;
            $event->save();
            $transaction = PaymentProviderTransaction::query()->where('tenant_id', $tenantId)->whereKey($event->payment_provider_transaction_id)->lockForUpdate()->firstOrFail();
            $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->whereKey($transaction->wallet_topup_order_id)->lockForUpdate()->firstOrFail();

            if (($event->provider_request_id !== null && ! hash_equals($transaction->provider_request_id, $event->provider_request_id))
                || ($event->provider_transaction_id !== null && $transaction->provider_transaction_id !== null
                    && ! hash_equals($transaction->provider_transaction_id, $event->provider_transaction_id))) {
                $event->processing_status = PaymentEventProcessingStatus::RequiresReview;
                $event->processed_at = now();
                $event->save();

                return false;
            }

            if ($event->isRefundLike()) {
                if ($order->status === WalletTopupStatus::Paid && $order->ledger_entry_id === null && $order->credited_at === null) {
                    $transaction->status = PaymentProviderTransactionStatus::Failed;
                    $transaction->save();
                    $order->status = WalletTopupStatus::Refunded;
                    $order->save();
                    $event->processing_status = PaymentEventProcessingStatus::Processed;
                } else {
                    $event->processing_status = PaymentEventProcessingStatus::RequiresReview;
                }
                $event->processed_at = now();
                $event->save();

                return false;
            }
            if ($event->amount === null || $event->asset_code === null || $event->amount !== $order->amount || $event->asset_code !== $order->asset_code) {
                $event->processing_status = PaymentEventProcessingStatus::RequiresReview;
                $event->processed_at = now();
                $event->save();

                return false;
            }
            $eventType = strtoupper($event->event_type);
            if (str_contains($eventType, 'CANCELLED') || str_contains($eventType, 'EXPIRED')) {
                if (in_array($order->status, [WalletTopupStatus::Paid, WalletTopupStatus::Credited], true)) {
                    $event->processing_status = PaymentEventProcessingStatus::RequiresReview;
                } else {
                    $transaction->status = PaymentProviderTransactionStatus::Failed;
                    $transaction->save();
                    $order->status = str_contains($eventType, 'EXPIRED') ? WalletTopupStatus::Expired : WalletTopupStatus::Cancelled;
                    $order->cancelled_at = str_contains($eventType, 'CANCELLED') ? now() : null;
                    $order->save();
                    $event->processing_status = PaymentEventProcessingStatus::Processed;
                }
                $event->processed_at = now();
                $event->save();

                return false;
            }
            $status = $event->normalized_status;
            if ($status === null) {
                $event->processing_status = PaymentEventProcessingStatus::RequiresReview;
                $event->processed_at = now();
                $event->save();

                return false;
            }
            if ($status === PaymentProviderTransactionStatus::Succeeded) {
                $decision = $this->transitions->providerTransition($transaction->status, $status);
                $expiredSuccess = $order->status === WalletTopupStatus::Expired;
                if (($decision === 'CONFLICT' && ! $expiredSuccess) || ! $this->transitions->orderMayTransition($order->status, WalletTopupStatus::Paid)) {
                    $event->processing_status = PaymentEventProcessingStatus::RequiresReview;
                    $event->processed_at = now();
                    $event->save();

                    return false;
                }
                $transaction->status = $status;
                $transaction->provider_transaction_id ??= $event->provider_transaction_id;
                $transaction->save();
                if (! in_array($order->status, [WalletTopupStatus::Paid, WalletTopupStatus::Credited], true)) {
                    $order->status = WalletTopupStatus::Paid;
                    $order->provider_transaction_id ??= $event->provider_transaction_id;
                    $order->paid_at = now();
                    $order->save();
                }
            } elseif (in_array($status, [PaymentProviderTransactionStatus::Pending, PaymentProviderTransactionStatus::Processing, PaymentProviderTransactionStatus::Unknown], true)) {
                $decision = $this->transitions->providerTransition($transaction->status, $status);
                if ($decision === 'CONFLICT') {
                    $event->processing_status = PaymentEventProcessingStatus::RequiresReview;
                    $event->processed_at = now();
                    $event->save();

                    return false;
                }
                if (! in_array($order->status, [WalletTopupStatus::Paid, WalletTopupStatus::Credited], true)) {
                    if ($decision === 'APPLY') {
                        $transaction->status = $status;
                        $transaction->save();
                    }
                    if ($this->transitions->orderMayTransition($order->status, WalletTopupStatus::Processing)) {
                        $order->status = WalletTopupStatus::Processing;
                        $order->save();
                    }
                }
            } elseif ($status === PaymentProviderTransactionStatus::Failed) {
                $decision = $this->transitions->providerTransition($transaction->status, $status);
                if ($decision === 'CONFLICT' || ! $this->transitions->orderMayTransition($order->status, WalletTopupStatus::Failed)) {
                    $event->processing_status = PaymentEventProcessingStatus::RequiresReview;
                    $event->processed_at = now();
                    $event->save();

                    return false;
                }
                $transaction->status = $status;
                $transaction->save();
                $order->status = WalletTopupStatus::Failed;
                $order->failed_at = now();
                $order->save();
            }
            $event->processing_status = PaymentEventProcessingStatus::Processed;
            $event->processed_at = now();
            $event->save();

            return $order->status === WalletTopupStatus::Paid;
        }, 3);

        if ($shouldCredit) {
            CreditWalletTopupJob::dispatch($tenantId, PaymentProviderEvent::query()->where('tenant_id', $tenantId)->findOrFail($eventId)->paymentTransaction->wallet_topup_order_id);
        }
    }
}
