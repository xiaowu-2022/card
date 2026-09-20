<?php

namespace App\Application\Tenant;

use App\Domain\Tenant\Models\Tenant;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PlatformDailyFundsQuery
{
    public function execute(array $filters, array $access): array
    {
        $start = CarbonImmutable::parse($filters['start'], PlatformFundsFilters::TIMEZONE)->startOfDay();
        $end = CarbonImmutable::parse($filters['end'], PlatformFundsFilters::TIMEZONE)->startOfDay();
        $daily = [];
        $totals = [];
        foreach (['inflow' => ['wallet_topup_orders', 'CREDITED', 'credited_at'], 'outflow' => ['withdrawal_orders', 'SUCCEEDED', 'blockchain_confirmed_at']] as $key => [$table, $state, $timestamp]) {
            if (! ($access[$key] ?? false)) {
                continue;
            }
            // Platform-only reporting: persisted company IDs, independent order aggregates,
            // half-open UTC boundaries, and completion date rather than order creation date.
            $companies = Tenant::query()->select('id')->when($filters['scope'] === 'selected', fn ($q) => $q->whereIn('id', $filters['companies']));
            $daily[$key] = DB::table($table)->whereIn('tenant_id', $companies)
                ->where('asset_code', 'USDT')->where('status', $state)
                ->where($timestamp, '>=', $start->utc())->where($timestamp, '<', $end->addDay()->utc())
                ->selectRaw("({$timestamp} AT TIME ZONE ?)::date::text AS day, SUM(amount)::text AS amount", [PlatformFundsFilters::TIMEZONE])
                ->groupByRaw('1')->pluck('amount', 'day');
            $totals[$key] = BigDecimal::zero()->toScale(8);
        }
        if ($access['overflow'] ?? false) {
            $companies = Tenant::query()->select('id')->when($filters['scope'] === 'selected', fn ($q) => $q->whereIn('id', $filters['companies']));
            // Use immutable settlement time, not mutable order update time. Overflow is
            // an uncharged amount and must never be included in inflow/outflow/net.
            $daily['overflow'] = DB::table('card_management_orders as o')
                ->join('ledger_entries as e', fn ($j) => $j->on('e.id', '=', 'o.settlement_entry_id')->on('e.tenant_id', '=', 'o.tenant_id'))
                ->whereIn('o.tenant_id', $companies)->where('o.kind', 'LOAD')->where('o.status', 'SUCCEEDED')
                ->where('e.created_at', '>=', $start->utc())->where('e.created_at', '<', $end->addDay()->utc())
                ->selectRaw('(e.created_at AT TIME ZONE ?)::date::text AS day, SUM(COALESCE(o.overflow_amount, 0))::text AS amount', [PlatformFundsFilters::TIMEZONE])
                ->groupByRaw('1')->pluck('amount', 'day');
            $totals['overflow'] = BigDecimal::zero()->toScale(8);
        }
        $rows = [];
        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $row = ['date' => $day->toDateString()];
            foreach ($daily as $key => $amounts) {
                $amount = BigDecimal::of($amounts[$row['date']] ?? '0')->toScale(8);
                $row[$key] = (string) $amount;
                $totals[$key] = $totals[$key]->plus($amount);
            }
            if (isset($row['inflow'], $row['outflow'])) {
                $row['net'] = (string) BigDecimal::of($row['inflow'])->minus($row['outflow']);
            }
            $rows[] = $row;
        }
        if (isset($totals['inflow'], $totals['outflow'])) {
            $totals['net'] = $totals['inflow']->minus($totals['outflow']);
        }

        return ['days' => $rows, 'totals' => array_map(fn (BigDecimal $amount): string => (string) $amount, $totals)];
    }
}
