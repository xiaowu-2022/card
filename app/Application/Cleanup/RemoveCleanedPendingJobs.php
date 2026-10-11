<?php

namespace App\Application\Cleanup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** Bounded physical removal; the worker tombstone gate covers racing arrivals. */
final class RemoveCleanedPendingJobs
{
    public function run(int $limit = 10000): void
    {
        foreach ($this->connections(config('queue.default')) as $name) {
            $configuration = config('queue.connections.'.$name);
            if ($configuration['driver'] === 'database') {
                $connection = DB::connection($configuration['connection'] ?? null);
                $table = $configuration['table'];
                if (! $connection->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }
                foreach ($connection->table($table)->whereNull('reserved_at')->orderBy('id')->limit($limit)->get(['id', 'payload']) as $job) {
                    if ($this->matches($job->payload)) {
                        $connection->table($table)->where('id', $job->id)->whereNull('reserved_at')->where('payload', $job->payload)->delete();
                    }
                }
            } elseif ($configuration['driver'] === 'redis') {
                $redis = Queue::connection($name)->getRedis()->connection($configuration['connection'] ?? 'default');
                $queue = 'queues:'.$configuration['queue'];
                $offset = 0;
                $scanned = 0;
                do {
                    $payloads = $redis->lrange($queue, $offset, $offset + 249);
                    $removed = 0;
                    foreach ($payloads as $payload) {
                        if ($this->matches($payload)) {
                            $removed += (int) $redis->lrem($queue, 0, $payload);
                        }
                    }
                    $scanned += count($payloads);
                    $offset += count($payloads) - $removed;
                } while (count($payloads) === 250 && $scanned < $limit);
                // Reserved jobs belong to live workers and are never removed here.
                foreach ($redis->zrange($queue.':delayed', 0, $limit - 1) as $payload) {
                    if ($this->matches($payload)) {
                        $redis->zrem($queue.':delayed', $payload);
                    }
                }
            }
        }
    }

    private function matches(string $payload): bool
    {
        $data = json_decode($payload, true);

        return is_array($data) && app(DiscardCleanedJobs::class)->matches($data);
    }

    private function connections(string $name): array
    {
        $configuration = config('queue.connections.'.$name);

        return ($configuration['driver'] ?? null) === 'failover'
            ? array_unique(array_merge(...array_map($this->connections(...), $configuration['connections']))) : [$name];
    }
}
