<?php

namespace App\Domain\Assets;

use App\Domain\Ledger\ValueObjects\Money;
use App\Support\Errors\DomainException;

final class AssetCatalog
{
    public const ASSETS = ['USDT', 'USDC', 'ETH', 'BTC'];

    public static function assert(string $asset): void
    {
        if (! in_array($asset, self::ASSETS, true)) {
            throw new DomainException('ASSET_INVALID', 'Select a supported asset.');
        }
    }

    public static function chainScale(string $asset): int
    {
        return $asset === 'USDT' ? 6 : Money::scale($asset);
    }
}
