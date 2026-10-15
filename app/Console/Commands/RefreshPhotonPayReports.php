<?php

namespace App\Console\Commands;

use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Jobs\RefreshPhotonPayAccountReport;
use Illuminate\Console\Command;

final class RefreshPhotonPayReports extends Command
{
    protected $signature = 'photonpay:refresh-reports';
    protected $description = 'Queue read-only merchant report snapshots for current PhotonPay providers';
    public function handle(): int
    {
        $count = 0;
        foreach (CardProviderReference::whereNotNull('photonpay_reporting_encrypted')->cursor() as $reference) {
            RefreshPhotonPayAccountReport::dispatch($reference->id)
                ->onConnection(config('queue.default') === 'sync' ? 'database' : config('queue.default'))->afterCommit();
            $count++;
        }
        $this->info('Queued '.$count.' PhotonPay report refresh jobs.');
        return self::SUCCESS;
    }
}
