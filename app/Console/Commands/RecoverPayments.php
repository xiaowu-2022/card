<?php

namespace App\Console\Commands;

use App\Domain\Payment\Enums\PaymentEventProcessingStatus;
use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Models\PaymentProviderEvent;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Jobs\CreditWalletTopupJob;
use App\Jobs\ProcessPaymentProviderEventJob;
use App\Jobs\QueryPaymentStatusJob;
use Illuminate\Console\Command;

final class RecoverPayments extends Command
{
    protected $signature = 'payments:recover {--tenant= : Restrict recovery to one trusted Tenant UUID}';

    protected $description = 'Redispatch persisted payment events, paid settlements, and stale unknown provider queries';

    public function handle(): int
    {
        $limit = max(1, min((int) config('payment.recovery_batch_size'), 500));
        $tenantId = $this->option('tenant');
        $events = PaymentProviderEvent::query()->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereIn('processing_status', [PaymentEventProcessingStatus::Pending->value, PaymentEventProcessingStatus::Failed->value])
            ->oldest('received_at')->limit($limit)->get();
        foreach ($events as $event) {
            ProcessPaymentProviderEventJob::dispatch($event->tenant_id, $event->id);
        }
        $orders = WalletTopupOrder::query()->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', WalletTopupStatus::Paid->value)->oldest('paid_at')->limit($limit)->get();
        foreach ($orders as $order) {
            CreditWalletTopupJob::dispatch($order->tenant_id, $order->id);
        }
        $transactions = PaymentProviderTransaction::query()->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', PaymentProviderTransactionStatus::Unknown->value)
            ->where('updated_at', '<=', now()->subMinutes((int) config('payment.unknown_after_minutes')))
            ->oldest('updated_at')->limit($limit)->get();
        foreach ($transactions as $transaction) {
            QueryPaymentStatusJob::dispatch($transaction->tenant_id, $transaction->id);
        }

        $this->info("Dispatched {$events->count()} event(s), {$orders->count()} settlement(s), and {$transactions->count()} query job(s).");

        return self::SUCCESS;
    }
}
