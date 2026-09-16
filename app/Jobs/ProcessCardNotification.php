<?php

namespace App\Jobs;

use App\Support\Logging\PhotonPayLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Retained solely to safely consume pre-deployment serialized jobs without querying providers. */
final class ProcessCardNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $tenantId, public readonly string $resourceId) {}

    public function handle(): void
    {
        PhotonPayLog::write('notification.legacy_job_skipped', [
            'tenant_id' => $this->tenantId, 'event_id' => $this->resourceId,
        ]);
    }
}
