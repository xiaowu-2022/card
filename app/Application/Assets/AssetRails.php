<?php

namespace App\Application\Assets;

use App\Domain\Assets\AssetCatalog;
use App\Domain\Assets\AssetRail;
use App\Domain\Assets\ChainConnection;
use App\Domain\Assets\CompanyRail;
use App\Domain\Ledger\ValueObjects\Money;
use App\Infrastructure\Assets\PublicChainNodes;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;

final class AssetRails
{
    public function enabled(string $tenantId, string $code, string $operation): array
    {
        $rail = AssetRail::query()->whereKey($code)->where('enabled', true)->first();
        $company = CompanyRail::query()->where('tenant_id', $tenantId)->where('rail_code', $code)->first();
        $connection = $rail ? ChainConnection::query()->whereKey($rail->network)->first() : null;
        if (! $rail || ! $connection || ! $rail->deposit_address || ! $company || ! in_array($operation, ['deposit', 'withdrawal'], true) || ! $company->{$operation.'_enabled'} || ($operation === 'deposit' ? $company->minimum_deposit === null : $company->withdrawal_fee_percent === null)) {
            throw new DomainException('ASSET_RAIL_UNAVAILABLE', 'This network is not available.', 403);
        }

        return [$rail, $company, $operation === 'withdrawal' ? PublicChainNodes::withdrawalConnection($rail->network) : $connection];
    }

    public function amount(string $amount, string $asset): Money
    {
        try {
            $money = Money::of($amount, $asset);
        } catch (\InvalidArgumentException) {
            throw new DomainException('AMOUNT_INVALID', 'Enter an amount within the currency precision.');
        }
        if (! $money->isPositive()) {
            throw new DomainException('AMOUNT_INVALID', 'Enter a positive amount.');
        }
        try {
            BigDecimal::of($amount)->toScale(AssetCatalog::chainScale($asset));
        } catch (\Throwable) {
            throw new DomainException('AMOUNT_INVALID', 'The amount exceeds this network precision.');
        }

        return $money;
    }
}
