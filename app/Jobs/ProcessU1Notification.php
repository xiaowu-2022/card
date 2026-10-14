<?php

namespace App\Jobs;

use App\Application\Card\U1Callbacks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessU1Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $connectionId, public readonly string $notificationId) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(U1Callbacks $callbacks): void
    {
        $status = $callbacks->process($this->connectionId, $this->notificationId);
        if ($status === 'RETRY' && $this->job && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 60);
        }
        // Exhausted pending evidence stays RETRY for explicit scoped recovery; never resend funds.
    }
}
