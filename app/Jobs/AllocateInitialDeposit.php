<?php

namespace App\Jobs;

use App\Application\SecurityDeposit\AllocateInitialDepositAction;
use App\Jobs\Middleware\CleanupJobFence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class AllocateInitialDeposit implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $tenantId, public readonly string $resourceId) {}

    public function middleware(): array
    {
        return [new CleanupJobFence($this->tenantId)];
    }

    public function handle(AllocateInitialDepositAction $action): void
    {
        $action->execute($this->tenantId, $this->resourceId);
    }
}
