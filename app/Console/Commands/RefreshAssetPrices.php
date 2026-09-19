<?php

namespace App\Console\Commands;

use App\Application\Assets\MarketPrices;
use Illuminate\Console\Command;

final class RefreshAssetPrices extends Command
{
    protected $signature = 'assets:refresh-prices';

    protected $description = 'Refresh public read-only price snapshots (never trades or changes balances)';

    public function handle(MarketPrices $prices): int
    {
        try {
            $prices->refresh();

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Asset market prices unavailable. Quotes fail closed.');

            return self::FAILURE;
        }
    }
}
