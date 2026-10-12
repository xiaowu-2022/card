<?php

namespace App\Infrastructure\Support;

use App\Application\Support\SupportSocketOrigins;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Protocols\Pusher\Exceptions\InvalidOrigin;
use Laravel\Reverb\Protocols\Pusher\Server;

final class CompanySupportSocketServer extends Server
{
    protected function verifyOrigin(Connection $connection): void
    {
        try {
            $allowed = app(SupportSocketOrigins::class)->allows($connection->origin());
        } catch (\Throwable) {
            // Fail closed without leaking SQL, credentials or configuration through Reverb logs.
            $allowed = false;
        }
        if (! $allowed) {
            throw new InvalidOrigin;
        }
    }
}
