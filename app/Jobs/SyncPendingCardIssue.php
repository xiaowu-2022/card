<?php

namespace App\Jobs;

use App\Application\Card\SyncCardIssueAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

final class SyncPendingCardIssue implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 60;
    public int $uniqueFor = 600;

    public function __construct(public readonly string $tenantId, public readonly string $orderId) {}

    public function uniqueId(): string { return $this->tenantId.':'.$this->orderId; }
    public function backoff(): array { return [30, 120]; }
    public function middleware(): array
    {
        return [(new WithoutOverlapping('card-issue-sync:'.$this->orderId))->releaseAfter(15)->expireAfter(90)];
    }

    public function handle(SyncCardIssueAction $sync): void
    {
        $order = $sync->execute($this->tenantId, $this->orderId);
        Log::info('Pending card issue automatically synchronized', [
            'tenant_id' => $this->tenantId, 'order_id' => $this->orderId,
            'provider' => $order->provider, 'status' => $order->status->value,
        ]);
    }

    public function failed(?\Throwable $error): void
    {
        Log::warning('Pending card issue automatic synchronization failed', [
            'tenant_id' => $this->tenantId, 'order_id' => $this->orderId,
            'exception_class' => $error ? get_class($error) : null,
        ]);
    }
}
