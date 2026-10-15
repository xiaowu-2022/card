<?php

namespace App\Jobs;

use App\Application\CardProviderDirectory\U1Accounts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

final class RefreshU1AccountReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $timeout = 60;

    public function __construct(public readonly string $connectionId) {}

    public function backoff(): array { return [10, 30, 60, 120]; }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('u1-report:'.$this->connectionId))->releaseAfter(10)->expireAfter(90)];
    }

    public function handle(U1Accounts $accounts): void
    {
        $accounts->refreshReport($this->connectionId);
    }

    public function failed(?\Throwable $error): void
    {
        Log::warning('U1 account report refresh failed', ['connection_id' => $this->connectionId,
            'exception_class' => $error ? get_class($error) : null]);
    }
}
