<?php

namespace App\Jobs;

use App\Application\Payment\QueryPaymentStatusAction;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\TenantContext;
use App\Jobs\Middleware\CleanupJobFence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class QueryPaymentStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $tenantId, public readonly string $transactionId) {}

    public function middleware(): array
    {
        return [new CleanupJobFence($this->tenantId)];
    }

    public function handle(QueryPaymentStatusAction $action, TenantContext $context): void
    {
        $context->set(Tenant::query()->whereKey($this->tenantId)->firstOrFail());
        try {
            $action->execute($this->tenantId, $this->transactionId);
        } finally {
            $context->clear();
        }
    }
}
