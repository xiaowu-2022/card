<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class ConfigurePhotonPayMerchantReporting extends Command
{
    protected $signature = 'cards:configure-photonpay-reporting {reference} {--env-file=}';

    protected $description = 'Bind one merchant to encrypted read-only PhotonPay reporting; does not change card routing';

    public function handle(AuditLogger $audit): int
    {
        $source = $this->option('env-file');
        if (! app()->environment('local') || ! is_string($source) || ! is_file($source)) {
            $this->error('This local setup requires an existing environment file.');

            return self::FAILURE;
        }
        $values = Dotenv::parse(file_get_contents($source));
        $config = ['base_url' => $values['PHOTONPAY_BASE_URL'] ?? '', 'app_id' => $values['PHOTONPAY_APP_ID'] ?? '', 'app_secret' => $values['PHOTONPAY_APP_SECRET'] ?? ''];
        if (! in_array($config['base_url'], ['https://x-api.sandbox.photontech.cc', 'https://x-api.photonpay.com'], true) || $config['app_id'] === '' || $config['app_secret'] === '') {
            $this->error('PhotonPay reporting configuration is incomplete.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($config, $audit): void {
            $record = CardProviderReference::query()->whereKey($this->argument('reference'))->lockForUpdate()->firstOrFail();
            if ($record->runtime_driver === 'LOCAL_MOCK') {
                throw new \LogicException('A local mock merchant cannot bind real reporting.');
            }
            $record->forceFill(['photonpay_reporting_encrypted' => Crypt::encryptString(json_encode($config, JSON_THROW_ON_ERROR)), 'version' => $record->version + 1])->save();
            $audit->record(null, 'SYSTEM', null, 'PHOTONPAY_MERCHANT_REPORTING_CONFIGURED', 'card_provider_reference', $record->id, null,
                ['source' => 'PHOTONPAY_READ_ONLY', 'environment' => $config['base_url'] === 'https://x-api.photonpay.com' ? 'production' : 'sandbox']);
        });
        $this->info('Encrypted reporting connection saved. Card routing and funds unchanged.');

        return self::SUCCESS;
    }
}
