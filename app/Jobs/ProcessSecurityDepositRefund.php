<?php

namespace App\Jobs;

use App\Application\SecurityDeposit\TimedDepositRefundAction;
use App\Domain\SecurityDeposit\Models\SecurityDepositRefundRequest;
use App\Jobs\Middleware\CleanupJobFence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessSecurityDepositRefund implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $tenantId, public readonly string $resourceId) {}

    public function middleware(): array
    {
        return [new CleanupJobFence($this->tenantId)];
    }

    public function handle(TimedDepositRefundAction $action): void
    {
        $refund = SecurityDepositRefundRequest::query()->where('tenant_id', $this->tenantId)->whereKey($this->resourceId)->firstOrFail();
        $action->execute($this->tenantId, $refund->user_id, $refund->id);
    }
}
