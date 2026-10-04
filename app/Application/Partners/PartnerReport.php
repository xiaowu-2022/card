<?php

namespace App\Application\Partners;

use App\Application\Assets\MarketPrices;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class PartnerReport
{
    public function enabled(string $tenant, string $user): bool
    {
        return DB::table('partner_configurations')->where('tenant_id', $tenant)->where('user_id', $user)->where('enabled', true)->exists();
    }

    /** The version is server-owned; callers cannot select another account or version. */
    public function read(string $tenant, string $user, bool $consumer = true, int $page = 1): array
    {
        $outer = DB::transactionLevel();

        return DB::transaction(function () use ($tenant, $user, $consumer, $page, $outer) {
            if ($outer === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $report = app(LegacyStockReport::class)->read($tenant, $user, false, $page);
            $partner = $this->enabled($tenant, $user);
            $report['version'] = $partner ? 'partner' : 'standard';
            $report['cashFlow'] = null;
            if (! $partner) {
                $report['accountBalance'] = null;
                $report['share'] = null;
                // Newly eligible ordinary users receive aggregates, not private cooperation notes.
                if ($consumer) {
                    $report['journal'] = ['items' => [], 'page' => $page, 'total' => 0, 'hasMore' => false];
                }

                return $report;
            }

            $flows = app(PartnerCashFlow::class)->totals($tenant, $user);
            $rates = ['USDT' => BigDecimal::one()];
            $needsRates = collect($flows)->contains(fn ($row) => $row->asset_code !== 'USDT' && (BigDecimal::of($row->inflow)->isPositive() || BigDecimal::of($row->outflow)->isPositive()));
            $observed = null;
            if ($needsRates) {
                try {
                    $prices = app(MarketPrices::class);
                    $snapshot = $prices->quote();
                    foreach (['USDC', 'ETH', 'BTC'] as $asset) {
                        $rate = $prices->rate($snapshot, $asset);
                        if (! $rate->isPositive()) {
                            throw new \UnexpectedValueException('Invalid valuation rate');
                        }
                        $rates[$asset] = $rate;
                    }
                    $observed = $snapshot->observed_at->toIso8601String();
                } catch (\Throwable) {
                    // Missing rates never turn a partially valued total into a complete report.
                    $rates = ['USDT' => BigDecimal::one()];
                }
            }
            $inflow = BigDecimal::zero();
            $outflow = BigDecimal::zero();
            $missing = 0;
            $assets = [];
            foreach ($flows as $row) {
                $rate = $rates[$row->asset_code] ?? null;
                $incoming = BigDecimal::of($row->inflow);
                $outgoing = BigDecimal::of($row->outflow);
                $unvalued = $rate === null && (! $incoming->isZero() || ! $outgoing->isZero());
                $missing += (int) $unvalued;
                $in = $unvalued ? null : $incoming->multipliedBy($rate ?? '0');
                $out = $unvalued ? null : $outgoing->multipliedBy($rate ?? '0');
                $inflow = $inflow->plus($in ?? '0');
                $outflow = $outflow->plus($out ?? '0');
                $assets[] = ['asset' => $row->asset_code, 'inflow' => (string) $incoming, 'outflow' => (string) $outgoing,
                    'rate' => $rate ? (string) $rate : null, 'inflowUsdt' => $in ? $this->decimal($in) : null, 'outflowUsdt' => $out ? $this->decimal($out) : null];
            }
            $stock = $inflow->minus($outflow);
            $report['stock'] = $missing ? null : $this->decimal($stock);
            $report['share'] = $missing ? null : $this->decimal($stock->multipliedBy($report['sharePercent'])->withPointMovedLeft(2));
            $report['negative'] = ! $missing && $stock->isNegative();
            $report['missingRates'] = $missing;
            $report['totals'] = ['inflow' => $missing ? null : $this->decimal($inflow), 'outflow' => $missing ? null : $this->decimal($outflow), 'advances' => $report['totals']['advances']];
            $report['cashFlow'] = ['rateObservedAt' => $observed, 'assets' => $assets];
            $report['trends'] = [];
            $report['unvalued'] = ['items' => [], 'page' => $page, 'total' => 0, 'hasMore' => false];

            return $report;
        });
    }

    private function decimal(BigDecimal $amount): string
    {
        return (string) $amount->toScale(8, RoundingMode::HalfUp);
    }
}
