<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

trait RefreshIsolatedDatabase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase()
    {
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'card_ui_test') {
            throw new \RuntimeException('Database refresh requires the isolated card_ui_test database.');
        }

        // PostgreSQL function bodies survive Laravel's table-only reset. Recreate these
        // known functions only in the isolated local test database, never card_platform.
        if (! RefreshDatabaseState::$migrated && app()->environment('testing')
            && DB::getDriverName() === 'pgsql' && DB::connection()->getDatabaseName() === 'card_ui_test') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_user_account_id() CASCADE;
                DROP FUNCTION IF EXISTS assign_user_account_id() CASCADE;
                DROP FUNCTION IF EXISTS allocate_user_account_id(uuid, timestamptz) CASCADE;');
        }
    }
}
