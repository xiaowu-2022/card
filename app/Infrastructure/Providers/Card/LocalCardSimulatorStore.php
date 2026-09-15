<?php

namespace App\Infrastructure\Providers\Card;

use App\Domain\CardProvider\Exceptions\ProviderUnavailableException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class LocalCardSimulatorStore
{
    public function access(callable $callback, bool $write = true): mixed
    {
        if (! LocalCardSimulation::enabled()) {
            throw new ProviderUnavailableException('Isolated local card simulation is unavailable.');
        }

        return DB::transaction(function () use ($callback, $write): mixed {
            DB::select("select pg_advisory_xact_lock(hashtextextended('local-card-simulator-v1', 0))");
            $payload = DB::table('local_card_simulator_states')->where('id', 'v1')->value('payload');
            $state = $payload === null ? ['holders' => [], 'cards' => [], 'operations' => [], 'quotes' => [], 'trades' => []]
                : json_decode(Crypt::decryptString($payload), true, flags: JSON_THROW_ON_ERROR);
            $result = $callback($state);
            if ($write) {
                DB::table('local_card_simulator_states')->updateOrInsert(['id' => 'v1'], [
                    'payload' => Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)),
                ]);
            }

            return $result;
        });
    }
}
