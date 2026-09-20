<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class RefreshAssetPrices extends Command
{
    protected $signature = 'assets:refresh-prices';

    protected $description = 'Disabled: exchange quotes fetch OKX prices on demand';

    public function handle(): int
    {
        $this->error('Scheduled price refresh is disabled. New exchange quotes fetch OKX prices on demand.');

        return self::FAILURE;
    }
}
