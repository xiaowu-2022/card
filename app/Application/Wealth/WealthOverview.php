<?php

namespace App\Application\Wealth;

use App\Application\Assets\MarketPrices;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Tenant\Models\Tenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class WealthOverview
{
    public function get(string $tenantId, string $userId): array
    {
        $timezone = Tenant::whereKey($tenantId)->firstOrFail()->timezone;
        $start = CarbonImmutable::now($timezone)->startOfMonth()->subMonths(11);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[$start->addMonths($i)->format('Y-m')] = ['paid' => '0', 'recovered' => '0'];
        }
        $settings = collect(app(WealthConfiguration::class)->get($tenantId))->keyBy('asset');
        $accounts = LedgerAccount::where('tenant_id', $tenantId)->where('user_id', $userId)->get();
        $orders = DB::table('wealth_orders')->where('tenant_id', $tenantId)->where('user_id', $userId);
        $paid = DB::table('wealth_installments as i')->join('wealth_orders as o', 'o.id', '=', 'i.order_id')
            ->where('i.tenant_id', $tenantId)->where('o.tenant_id', $tenantId)->where('o.user_id', $userId)->whereNotNull('i.settled_at');
        $paidTotals = (clone $paid)->selectRaw('o.asset_code, SUM(i.amount) AS amount')->groupBy('o.asset_code')->pluck('amount', 'asset_code');
        $recoveredTotals = (clone $orders)->where('status', 'CANCELLED')->selectRaw('asset_code, SUM(clawback) AS amount')->groupBy('asset_code')->pluck('amount', 'asset_code');
        $counts = (clone $orders)->where('status', 'ACTIVE')->selectRaw('asset_code, COUNT(*) AS count')->groupBy('asset_code')->pluck('count', 'asset_code');
        $monthlyPaid = (clone $paid)->where('i.settled_at', '>=', $start->utc())->selectRaw("o.asset_code, to_char(i.settled_at AT TIME ZONE ?, 'YYYY-MM') AS month, SUM(i.amount) AS amount", [$timezone])->groupByRaw('1, 2')->get();
        $monthlyRecovered = (clone $orders)->where('status', 'CANCELLED')->where('closed_at', '>=', $start->utc())->selectRaw("asset_code, to_char(closed_at AT TIME ZONE ?, 'YYYY-MM') AS month, SUM(clawback) AS amount", [$timezone])->groupByRaw('1, 2')->get();
        $pending = DB::table('wealth_installments as i')->join('wealth_orders as o', 'o.id', '=', 'i.order_id')
            ->where('i.tenant_id', $tenantId)->where('o.tenant_id', $tenantId)->where('o.user_id', $userId)
            ->where('o.status', 'ACTIVE')->whereNull('i.settled_at')->where('i.amount', '>', '0');
        $nextAt = (clone $pending)->min('i.due_at');
        $nextInterest = $nextAt === null ? null : [
            'dueAt' => CarbonImmutable::parse($nextAt)->toIso8601String(),
            'overdue' => CarbonImmutable::parse($nextAt)->lessThanOrEqualTo(now()),
            'amounts' => (clone $pending)->where('i.due_at', $nextAt)->selectRaw('o.asset_code AS asset, SUM(i.amount) AS amount')->groupBy('o.asset_code')->orderBy('o.asset_code')->get()->map(fn ($row) => ['asset' => $row->asset, 'amount' => (string) BigDecimal::of($row->amount)])->all(),
        ];
        $prices = app(MarketPrices::class);
        $snapshot = $prices->latest();
        $assets = [];
        $totalPrincipal = BigDecimal::of('0');
        $totalNet = BigDecimal::of('0');
        $valid = true;
        $usedPrices = false;
        foreach (['USDT', 'USDC', 'ETH', 'BTC'] as $asset) {
            $balance = fn ($type) => (string) BigDecimal::of($accounts->first(fn ($a) => $a->asset_code === $asset && $a->account_type->value === $type)?->balance ?? '0');
            $principal = $balance('USER_WEALTH_PRINCIPAL');
            $interest = (string) ($paidTotals[$asset] ?? '0');
            $recovered = (string) ($recoveredTotals[$asset] ?? '0');
            $net = BigDecimal::of($interest)->minus($recovered);
            $series = $months;
            foreach ($monthlyPaid->where('asset_code', $asset) as $row) {
                if (isset($series[$row->month])) {
                    $series[$row->month]['paid'] = $row->amount;
                }
            }
            foreach ($monthlyRecovered->where('asset_code', $asset) as $row) {
                if (isset($series[$row->month])) {
                    $series[$row->month]['recovered'] = $row->amount;
                }
            }
            $max = BigDecimal::of('0');
            foreach ($series as &$month) {
                $month['net'] = (string) BigDecimal::of($month['paid'])->minus($month['recovered']);
                foreach (['paid', 'recovered', 'net'] as $key) {
                    $max = BigDecimal::max($max, BigDecimal::of($month[$key])->abs());
                }
            }
            unset($month);
            foreach ($series as &$month) {
                foreach (['paid', 'recovered', 'net'] as $key) {
                    $month[$key.'Ratio'] = $max->isZero() ? '0' : (string) BigDecimal::of($month[$key])->dividedBy($max, 8, RoundingMode::Down);
                }
            }
            unset($month);
            $rates = collect($settings[$asset]['products'])->where('enabled', true)->pluck('rate')->all();
            $charts = [];
            foreach ([6, 12] as $length) {
                $visible = array_slice(array_values($series), -$length);
                $peak = BigDecimal::of('0');
                foreach ($visible as $month) {
                    $peak = BigDecimal::max($peak, BigDecimal::of($month['net'])->abs());
                }
                $charts[$length] = ['maximum' => (string) $peak, 'ratios' => array_map(fn ($month) => $peak->isZero() ? '0' : (string) BigDecimal::of($month['net'])->dividedBy($peak, 12, RoundingMode::Down), $visible)];
            }
            $value = BigDecimal::of('0');
            if ($asset === 'USDT') {
                $value = BigDecimal::of($principal);
                $totalNet = $totalNet->plus($net);
            } elseif (! BigDecimal::of($principal)->isZero() || ! $net->isZero()) {
                if ($snapshot === null) {
                    $valid = false;
                } else {
                    $rate = $prices->rate($snapshot, $asset);
                    $value = BigDecimal::of($principal)->multipliedBy($rate);
                    $totalNet = $totalNet->plus($net->multipliedBy($rate));
                    $usedPrices = true;
                }
            }
            $totalPrincipal = $totalPrincipal->plus($value);
            $assets[] = ['asset' => $asset, 'annualRateMin' => $rates ? (string) BigDecimal::min(...$rates) : null, 'annualRateMax' => $rates ? (string) BigDecimal::max(...$rates) : null, 'charts' => $charts, 'available' => $balance('USER_AVAILABLE'), 'principal' => $principal, 'paid' => $interest, 'recovered' => $recovered, 'net' => (string) $net, 'count' => (int) ($counts[$asset] ?? 0), 'value' => (string) $value, 'months' => array_map(fn ($key, $values) => ['month' => $key] + $values, array_keys($series), array_values($series))];
        }
        foreach ($assets as &$asset) {
            $asset['share'] = ! $valid ? null : ($totalPrincipal->isZero() ? '0' : (string) BigDecimal::of($asset['value'])->multipliedBy('100')->dividedBy($totalPrincipal, 8, RoundingMode::Down));
            if (! $valid) {
                $asset['value'] = null;
            }
        }
        unset($asset);

        return ['nextInterest' => $nextInterest, 'assets' => $assets, 'principalEstimate' => $valid ? (string) $totalPrincipal : null, 'netEstimate' => $valid ? (string) $totalNet : null, 'updatedAt' => $valid && $usedPrices ? $snapshot->observed_at->toIso8601String() : null, 'timezone' => $timezone];
    }
}
