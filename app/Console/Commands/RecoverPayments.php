<?php

namespace App\Console\Commands;

use App\Domain\Payment\Enums\PaymentEventProcessingStatus;
use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Models\PaymentProviderEvent;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Models\TenantDomain;
use App\Jobs\CreditWalletTopupJob;
use App\Jobs\InitiateWalletTopupPaymentJob;
use App\Jobs\ProcessPaymentProviderEventJob;
use App\Jobs\QueryPaymentStatusJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RecoverPayments extends Command
{
    protected $signature = 'payments:recover {--tenant= : Restrict recovery to one trusted Tenant UUID}';

    protected $description = 'Redispatch recoverable payment initiation, events, paid settlements, and provider queries';

    public function handle(): int
    {
        $lock = DB::selectOne('SELECT pg_try_advisory_lock(?) AS acquired', [58210051]);
        if (! (bool) ($lock->acquired ?? false)) {
            $this->info('Payment recovery is already running.');

            return self::SUCCESS;
        }

        try {
            return $this->recover();
        } finally {
            DB::selectOne('SELECT pg_advisory_unlock(?)', [58210051]);
        }
    }

    private function recover(): int
    {
        $limit = max(1, min((int) config('payment.recovery_batch_size'), 500));
        $tenantId = $this->option('tenant');
        $uninitiated = PaymentProviderTransaction::query()->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNull('provider_transaction_id')
            ->whereIn('status', [
                PaymentProviderTransactionStatus::Pending->value,
                PaymentProviderTransactionStatus::Processing->value,
                PaymentProviderTransactionStatus::Unknown->value,
            ])
            ->where(fn ($query) => $query->whereNull('initiation_lease_expires_at')->orWhere('initiation_lease_expires_at', '<=', now()))
            ->where('updated_at', '<=', now()->subSeconds((int) config('payment.initiation_recovery_after_seconds')))
            ->oldest('created_at')->limit($limit)->get();
        foreach ($uninitiated as $transaction) {
            $hostname = TenantDomain::query()->where('tenant_id', $transaction->tenant_id)
                ->where('status', TenantDomainStatus::Active->value)->orderByDesc('is_primary')->value('hostname');
            if ($hostname) {
                $scheme = app()->environment(['local', 'testing']) ? 'http' : 'https';
                InitiateWalletTopupPaymentJob::dispatch(
                    $transaction->tenant_id,
                    $transaction->wallet_topup_order_id,
                    "{$scheme}://{$hostname}/wallet/top-ups/__ORDER__/return",
                );
            }
        }
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
            ->whereNotIn('id', $uninitiated->pluck('id'))
            ->where('updated_at', '<=', now()->subMinutes((int) config('payment.unknown_after_minutes')))
            ->oldest('updated_at')->limit($limit)->get();
        foreach ($transactions as $transaction) {
            QueryPaymentStatusJob::dispatch($transaction->tenant_id, $transaction->id);
        }

        $this->info("Dispatched {$uninitiated->count()} initiation(s), {$events->count()} event(s), {$orders->count()} settlement(s), and {$transactions->count()} query job(s).");

        return self::SUCCESS;
    }
}
