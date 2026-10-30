<?php

namespace App\Console\Commands;

use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Jobs\RefreshU1AccountReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RefreshCardProviderReports extends Command
{
    protected $signature = 'cards:refresh-provider-reports';

    protected $description = 'Queue read-only balance reports for current PhotonPay and verified U1 providers';

    public function handle(): int
    {
        $this->call('photonpay:refresh-reports');

        $count = 0;
        foreach (DB::table('u1_connections')->where('check_status', 'VERIFIED')
            ->whereIn('reference_id', CardProviderReference::query()->select('id'))->select('id')->cursor() as $connection) {
            RefreshU1AccountReport::dispatch($connection->id)
                ->onConnection(config('queue.default') === 'sync' ? 'database' : config('queue.default'))->afterCommit();
            $count++;
        }
        $this->info('Queued '.$count.' U1 report refresh jobs.');

        return self::SUCCESS;
    }
}
