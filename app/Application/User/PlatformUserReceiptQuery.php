<?php

namespace App\Application\User;

use App\Application\Payment\DepositReceiptClassification;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class PlatformUserReceiptQuery
{
    public function forUsers(array $users): array
    {
        if ($users === []) {
            return [];
        }
        $totals = [];
        foreach (['wallet_topup_orders', 'asset_deposit_orders'] as $table) {
            $type = DepositReceiptClassification::effectiveType($table);
            $rows = DepositReceiptClassification::join(DB::table($table), $table, $table === 'wallet_topup_orders' ? 'primary' : 'asset')->where($table.'.status', 'CREDITED')
                ->where(function ($query) use ($users, $table) {
                    foreach ($users as $user) {
                        $query->orWhere(fn ($q) => $q->where($table.'.tenant_id', $user->tenant_id)->where($table.'.user_id', $user->id));
                    }
                })
                ->select($table.'.tenant_id', $table.'.user_id', 'asset_code')
                ->selectRaw("COALESCE(SUM(COALESCE(actual_received_amount, amount)) FILTER (WHERE {$type} <> 'ADVANCE'), 0)::text AS actual")
                ->selectRaw("COALESCE(SUM(COALESCE(actual_received_amount, amount)) FILTER (WHERE {$type} = 'ADVANCE'), 0)::text AS advance")
                ->groupBy($table.'.tenant_id', $table.'.user_id', 'asset_code')->get();
            foreach ($rows as $row) {
                $key = $row->tenant_id.':'.$row->user_id;
                foreach (['actual', 'advance'] as $kind) {
                    $totals[$key][$row->asset_code][$kind] = (string) BigDecimal::of($totals[$key][$row->asset_code][$kind] ?? '0')->plus($row->$kind);
                }
            }
        }
        $result = [];
        foreach ($users as $user) {
            $key = $user->tenant_id.':'.$user->id;
            $assets = $totals[$key] ?? ['USDT' => ['actual' => '0', 'advance' => '0']];
            uksort($assets, fn ($a, $b) => ($a === 'USDT' ? -1 : ($b === 'USDT' ? 1 : strcmp($a, $b))));
            foreach ($assets as $asset => $amounts) {
                $result[$key][] = ['asset' => $asset, ...$amounts];
            }
        }

        return $result;
    }
}
