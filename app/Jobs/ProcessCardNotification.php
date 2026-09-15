<?php

namespace App\Jobs;

use App\Application\Card\ProcessCardNotificationAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessCardNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly string $tenantId, public readonly string $resourceId) {}

    public function handle(ProcessCardNotificationAction $action): void
    {
        $action->execute($this->tenantId, $this->resourceId);
    }
}
