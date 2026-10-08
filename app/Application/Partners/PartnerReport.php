<?php

namespace App\Application\Partners;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PartnerReport
{
    public function enabled(string $tenant, string $user): bool
    {
        return DB::table('partner_configurations')->where('tenant_id', $tenant)->where('user_id', $user)->where('enabled', true)->exists();
    }

    /** The version is server-owned; callers cannot select another account or version. */
    public function read(string $tenant, string $user, bool $consumer = true, int $page = 1, ?string $flow = null, int $flowPage = 1): array
    {
        $outer = DB::transactionLevel();

        return DB::transaction(function () use ($tenant, $user, $consumer, $page, $outer, $flow, $flowPage) {
            if ($outer === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $report = app(LegacyStockReport::class)->read($tenant, $user, false, $page);
            $partner = $this->enabled($tenant, $user);
            $report['version'] = $partner ? 'partner' : 'standard';
            $report['cashFlow'] = null;
            $report['flowDetails'] = null;
            abort_if($flow !== null && ! $partner, 403);
            abort_unless($flow === null || in_array($flow, ['inflow', 'outflow'], true), 422);
            if (! $partner) {
                $report['accountBalance'] = null;
                $report['share'] = null;
                // Newly eligible ordinary users receive aggregates, not private cooperation notes.
                if ($consumer) {
                    $report['journal'] = ['items' => [], 'page' => $page, 'total' => 0, 'hasMore' => false];
                }

                return $report;
            }

            $business = app(PartnerBusinessStock::class);
            $totals = $business->totals($tenant, $user);
            $stock = BigDecimal::of($totals['inflow'])->minus($totals['outflow']);
            $report['stock'] = $this->decimal($stock);
            $report['share'] = $this->decimal($stock->multipliedBy($report['sharePercent'])->withPointMovedLeft(2));
            $report['negative'] = $stock->isNegative();
            $report['missingRates'] = 0;
            $report['totals'] = [...$totals, 'advances' => $report['totals']['advances']];
            $report['stockBasis'] = 'BUSINESS_CONTRIBUTIONS';
            if ($flow !== null) {
                $report['flowDetails'] = $business->details($tenant, $user, $flow, max(1, $flowPage));
            }
            $report['trends'] = $business->trends($tenant, $user, CarbonImmutable::parse($report['updatedAt']), $report['timezone']);
            $report['unvalued'] = ['items' => [], 'page' => $page, 'total' => 0, 'hasMore' => false];

            return $report;
        });
    }

    private function decimal(BigDecimal $amount): string
    {
        return (string) $amount->toScale(8, RoundingMode::HalfUp);
    }
}
