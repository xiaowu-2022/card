<?php

namespace App\Console\Commands;

use App\Application\Assets\ScanAssetNetwork;
use App\Domain\Assets\ChainConnection;
use Illuminate\Console\Command;

final class ScanAssetNetworks extends Command
{
    protected $signature = 'assets:scan {network? : ETHEREUM or BITCOIN}';

    protected $description = 'Incrementally verify deposits from explicit configured network boundaries';

    public function handle(ScanAssetNetwork $scanner): int
    {
        $query = ChainConnection::where('enabled', true)->whereNotNull('next_height');
        if ($this->argument('network')) {
            $query->where('network', $this->argument('network'));
        }
        $failed = false;
        foreach ($query->get() as $connection) {
            try {
                $scanner->execute($connection->network);
            } catch (\Throwable) {
                $failed = true;
                $this->error($connection->network.': scan stopped; cursor retained. Review network evidence.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
