<?php

namespace App\Jobs\Middleware;

use App\Application\Cleanup\CleanupCompanyFence;
use App\Support\Errors\DomainException;

final readonly class CleanupJobFence
{
    public function __construct(private string $tenant) {}

    public function handle(object $job, callable $next): mixed
    {
        try {
            return app(CleanupCompanyFence::class)->run($this->tenant, fn () => $next($job));
        } catch (DomainException $exception) {
            if ($exception->errorCode !== 'CLEANUP_BUSY') {
                throw $exception;
            }
            $job->release(5);

            return null;
        }
    }
}
