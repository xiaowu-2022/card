<?php

namespace App\Application\Cleanup;

use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final class CleanupCompanyFence
{
    public function run(string $tenant, callable $operation, bool $exclusive = false): mixed
    {
        $key = 'cleanup-company:'.$tenant;
        $lock = $exclusive ? 'pg_try_advisory_lock' : 'pg_try_advisory_lock_shared';
        $unlock = $exclusive ? 'pg_advisory_unlock' : 'pg_advisory_unlock_shared';
        if (! DB::scalar("SELECT $lock(hashtextextended(?,0))", [$key])) {
            throw new DomainException('CLEANUP_BUSY', 'A related operation is in progress. Try again after it finishes.', 409);
        }
        try {
            return $operation();
        } finally {
            DB::select("SELECT $unlock(hashtextextended(?,0))", [$key]);
        }
    }
}
