<?php

namespace App\Application\Cleanup;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DiscardCleanedJobs
{
    public function __invoke(JobProcessing $event): void
    {
        if ($this->matches($event->job->payload())) {
            $event->job->delete();
        }
    }

    public function matches(array $payload): bool
    {
        if (! str_starts_with($payload['data']['commandName'] ?? '', 'App\\Jobs\\') || ! Schema::hasTable('data_cleanup_tombstones')) {
            return false;
        }
        // Extract opaque IDs only; never unserialize or log a queued payload.
        preg_match_all('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/i', $payload['data']['command'] ?? '', $matches);
        $ids = array_unique(array_map('strtolower', $matches[0]));

        return $ids && DB::table('data_cleanup_tombstones')->where('namespace', 'resource')->whereIn('identifier_hash', array_map('md5', $ids))->exists();
    }
}
