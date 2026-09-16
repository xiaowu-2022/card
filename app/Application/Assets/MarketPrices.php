<?php

namespace App\Application\Assets;

use App\Domain\Assets\MarketSettings;
use App\Domain\Assets\MarketSnapshot;
use App\Infrastructure\Assets\ExactJson;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class MarketPrices
{
    public function refresh(): MarketSnapshot
    {
        // Shared across web workers and scheduler hosts. User reads never call refresh().
        $cache = Cache::store();
        $lock = $cache->lock('assets:market-refresh', 60);
        if (! $lock->get()) {
            return $this->latest() ?? throw new DomainException('ASSET_PRICES_UNAVAILABLE', 'Market prices are unavailable.', 503);
        }
        try {
            $settings = MarketSettings::query()->find(1);
            if (! $settings?->enabled) {
                throw new DomainException('ASSET_PRICES_UNAVAILABLE', 'Market prices are unavailable.', 503);
            }
            $latest = $this->latest();
            if ($latest && $latest->created_at->greaterThan(now()->subSeconds(60))) {
                return $latest;
            }
            // Includes failed attempts: repeated clicks cannot hammer the upstream service.
            if (! $cache->add('assets:market-refresh-attempt', true, 60)) {
                return $latest ?? throw new DomainException('ASSET_PRICES_UNAVAILABLE', 'Market prices are unavailable.', 503);
            }

            return $this->fetch($settings);
        } finally {
            $lock->release();
        }
    }

    private function fetch(MarketSettings $settings): MarketSnapshot
    {
        try {
            $http = Http::connectTimeout(5)->timeout(15)->withoutRedirecting();
            $url = 'https://api.coingecko.com/api/v3/simple/price';
            if ($settings->api_key) {
                $http = $http->withHeaders(['x-cg-pro-api-key' => $settings->api_key]);
                $url = 'https://pro-api.coingecko.com/api/v3/simple/price';
            }
            $response = $http->get($url, [
                'ids' => 'tether,usd-coin,ethereum,bitcoin', 'vs_currencies' => 'usd',
                'include_last_updated_at' => 'true', 'precision' => 'full',
            ]);
            if (! $response->successful()) {
                throw new \UnexpectedValueException;
            }
            $data = ExactJson::decode($response->body());
            $prices = [];
            $time = now()->timestamp;
            foreach (['USDT' => 'tether', 'USDC' => 'usd-coin', 'ETH' => 'ethereum', 'BTC' => 'bitcoin'] as $asset => $key) {
                $raw = $data[$key]['usd'] ?? null;
                $updated = $data[$key]['last_updated_at'] ?? null;
                if (! is_string($raw) || ! is_string($updated) || ! ctype_digit($updated)) {
                    throw new \UnexpectedValueException;
                }
                $decimal = BigDecimal::of($raw);
                if (! $decimal->isPositive() || $decimal->isGreaterThan('999999999999') || (int) $updated > now()->timestamp + 5 || (int) $updated < now()->timestamp - 120) {
                    throw new \UnexpectedValueException;
                }
                $prices[$asset] = (string) $decimal;
                $time = min($time, (int) $updated);
            }

            return MarketSnapshot::query()->create(['provider' => 'COINGECKO', 'usd_prices' => $prices, 'observed_at' => CarbonImmutable::createFromTimestampUTC($time)]);
        } catch (\Throwable) {
            throw new DomainException('ASSET_PRICES_UNAVAILABLE', 'Market prices are unavailable.', 503);
        }
    }

    public function latest(): ?MarketSnapshot
    {
        if (! MarketSettings::query()->whereKey(1)->where('enabled', true)->exists()) {
            return null;
        }

        return MarketSnapshot::query()->where('observed_at', '>=', now()->subSeconds(120))->where('observed_at', '<=', now()->addSeconds(5))->latest('observed_at')->first();
    }

    public function rate(MarketSnapshot $snapshot, string $asset): BigDecimal
    {
        return BigDecimal::of($snapshot->usd_prices[$asset])->dividedBy($snapshot->usd_prices['USDT'], 18, RoundingMode::Down);
    }
}
