<?php

namespace App\Jobs;

use App\Application\Payment\ProcessPaymentProviderEventAction;
use App\Domain\Payment\Enums\PaymentEventProcessingStatus;
use App\Domain\Payment\Models\PaymentProviderEvent;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ProcessPaymentProviderEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $tenantId, public readonly string $eventId) {}

    public function handle(ProcessPaymentProviderEventAction $action, TenantContext $context): void
    {
        $context->set(Tenant::query()->whereKey($this->tenantId)->firstOrFail());
        try {
            $action->execute($this->tenantId, $this->eventId);
        } finally {
            $context->clear();
        }
    }

    public function failed(?Throwable $exception): void
    {
        PaymentProviderEvent::query()->where('tenant_id', $this->tenantId)->whereKey($this->eventId)
            ->whereNotIn('processing_status', [PaymentEventProcessingStatus::Processed->value, PaymentEventProcessingStatus::RequiresReview->value])
            ->update(['processing_status' => PaymentEventProcessingStatus::Failed->value, 'updated_at' => now()]);
    }
}
