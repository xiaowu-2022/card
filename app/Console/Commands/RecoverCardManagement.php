<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Compatibility stub: stale server cron entries must not resume card polling. */
final class RecoverCardManagement extends Command
{
    protected $signature = 'cards:recover {--tenant=}';

    protected $description = 'Disabled: card synchronization now runs on notifications or explicit refresh';

    public function handle(): int
    {
        $this->error('Automatic card recovery is disabled. Remove this cron entry and use the scoped card refresh action.');

        return self::FAILURE;
    }
}
