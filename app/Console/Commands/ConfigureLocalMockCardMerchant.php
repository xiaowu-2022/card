<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Providers\Card\LocalCardSimulation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ConfigureLocalMockCardMerchant extends Command
{
    protected $signature = 'cards:configure-local-mock-merchant {reference}';

    protected $description = 'Bind the existing test merchant to isolated local simulation without changing funds or historical cards';

    public function handle(AuditLogger $audit): int
    {
        if (! app()->environment('local') || ! LocalCardSimulation::active()) {
            $this->error('An explicitly enabled isolated local simulator is required.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($audit): void {
            $record = CardProviderReference::query()->whereKey($this->argument('reference'))->lockForUpdate()->firstOrFail();
            if ($record->runtime_driver === 'LOCAL_MOCK') {
                return;
            }
            if (strtolower(trim($record->name)) !== 'test') {
                throw new \LogicException('Only the selected test merchant can be registered.');
            }
            // The database also rejects changing a runtime referenced by card history.
            $record->forceFill(['runtime_driver' => 'LOCAL_MOCK', 'version' => $record->version + 1])->save();
            $audit->record(null, 'SYSTEM', null, 'LOCAL_MOCK_CARD_MERCHANT_CONFIGURED', 'card_provider_reference', $record->id,
                ['runtime_driver' => 'UNCONFIGURED'], ['runtime_driver' => 'LOCAL_MOCK']);
        });
        $this->info('Local mock merchant configured. No card operations or financial changes were performed.');

        return self::SUCCESS;
    }
}
