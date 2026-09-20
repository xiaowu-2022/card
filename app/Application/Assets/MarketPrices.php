<?php

namespace App\Application\Assets;

use App\Domain\Assets\MarketSnapshot;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class MarketPrices
{
    /** Fetch a fresh public spot snapshot for a new exchange quote; never called by page reads. */
    public function refresh(): MarketSnapshot
    {
        try {
            $response = Http::connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->withUserAgent('ApertureCards/1.0 (exchange quotes)')->acceptJson()
                ->get('https://www.okx.com/api/v5/market/tickers', ['instType' => 'SPOT']);
            if (! $response->successful()) {
                throw new DomainException('ASSET_PRICES_UNAVAILABLE', 'Market prices are unavailable. Please try again later.', 503);
            }
            $data = $response->json();
            if (($data['code'] ?? null) !== '0' || ! is_array($data['data'] ?? null)) {
                throw new \UnexpectedValueException;
            }
            $rates = [];
            $time = now()->getTimestampMs();
            foreach ($data['data'] as $ticker) {
                $asset = match ($ticker['instId'] ?? '') {
                    'USDC-USDT' => 'USDC', 'ETH-USDT' => 'ETH', 'BTC-USDT' => 'BTC', default => null,
                };
                if ($asset === null) {
                    continue;
                }
                $raw = $ticker['last'] ?? null;
                $stamp = $ticker['ts'] ?? null;
                if (isset($rates[$asset]) || ($ticker['instType'] ?? null) !== 'SPOT' || ! is_string($raw)
                    || ! preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $raw) || ! is_string($stamp)
                    || ! preg_match('/^[0-9]{13}$/', $stamp)) {
                    throw new \UnexpectedValueException;
                }
                $price = BigDecimal::of($raw);
                if (! $price->isPositive() || $price->isGreaterThan('999999999999')
                    || (int) $stamp > now()->getTimestampMs() + 5000 || (int) $stamp < now()->getTimestampMs() - 120000) {
                    throw new \UnexpectedValueException;
                }
                $rates[$asset] = (string) $price;
                $time = min($time, (int) $stamp);
            }
            if (count($rates) !== 3) {
                throw new \UnexpectedValueException;
            }

            return MarketSnapshot::query()->create(['provider' => 'OKX', 'usd_prices' => [],
                'usdt_rates' => $rates, 'observed_at' => CarbonImmutable::createFromTimestampMsUTC($time)]);
        } catch (DomainException $e) {
            throw $e;
        } catch (\UnexpectedValueException|MathException $e) {
            Log::warning('assets.market.invalid_data', ['service' => 'OKX_PUBLIC']);
            throw new DomainException('ASSET_PRICES_UNAVAILABLE', 'The price service returned incomplete or outdated rates. No rates were published.', 503);
        } catch (\Throwable) {
            Log::warning('assets.market.connection_failed', ['service' => 'OKX_PUBLIC']);
            throw new DomainException('ASSET_PRICES_UNAVAILABLE', 'Unable to connect to the price service. Check server network access and retry.', 503);
        }
    }

    public function latest(): ?MarketSnapshot
    {
        return MarketSnapshot::query()->where('observed_at', '>=', now()->subSeconds(120))->where('observed_at', '<=', now()->addSeconds(5))->latest('observed_at')->first();
    }

    /** Administrative visibility retains saved rates; new quotes always fetch their own snapshot. */
    public function configuration(): array
    {
        $snapshot = MarketSnapshot::query()->latest('observed_at')->first();
        // Match the second-resolution timestamps bound by latest()'s database query.
        $now = CarbonImmutable::now()->startOfSecond();

        return [
            'enabled' => true,
            'configured' => false,
            'snapshot' => $snapshot ? [
                'observed_at' => $snapshot->observed_at->toIso8601String(),
                'fresh' => $snapshot->observed_at->greaterThanOrEqualTo($now->subSeconds(120))
                    && $snapshot->observed_at->lessThanOrEqualTo($now->addSeconds(5)),
                'rates' => collect(['USDC', 'ETH', 'BTC'])->mapWithKeys(fn ($asset) => [$asset => (string) $this->rate($snapshot, $asset)])->all(),
            ] : null,
        ];
    }

    public function rate(MarketSnapshot $snapshot, string $asset): BigDecimal
    {
        if ($snapshot->provider === 'OKX') {
            return BigDecimal::of($snapshot->usdt_rates[$asset])->toScale(18, RoundingMode::Down);
        }

        return BigDecimal::of($snapshot->usd_prices[$asset])->dividedBy($snapshot->usd_prices['USDT'], 18, RoundingMode::Down);
    }
}
