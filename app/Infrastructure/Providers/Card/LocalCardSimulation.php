<?php

namespace App\Infrastructure\Providers\Card;

use App\Domain\CardProvider\Contracts\CardProviderInterface;
use Illuminate\Support\Facades\DB;

final class LocalCardSimulation
{
    public static function enabled(): bool
    {
        $directory = config('card-provider.driver') === 'directory';
        if (! $directory && (! app()->environment('local') || config('card-provider.driver') !== 'mock')) {
            return false;
        }
        // Unified directory routing preserves registered providers; legacy mock mode stays local.
        $database = DB::connection()->getDatabaseName();
        if (DB::getDriverName() !== 'pgsql' || (! $directory && ! in_array($database, ['card_mock', 'card_ui_test'], true))) {
            return false;
        }

        return DB::selectOne('select current_database() as name', [], false)->name === $database;
    }

    public static function active(): bool
    {
        return self::enabled() && (config('card-provider.driver') === 'directory' || app(CardProviderInterface::class) instanceof LocalMockCardProvider);
    }

    public static function allowsCard(string $reference): bool
    {
        return str_starts_with($reference, 'MOCK-LOCAL-CARD-') && self::active();
    }
}
