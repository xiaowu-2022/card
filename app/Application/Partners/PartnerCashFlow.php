<?php

namespace App\Application\Partners;

use Illuminate\Support\Facades\DB;

final class PartnerCashFlow
{
    /** Successful external orders only; own account excluded even in a malformed referral cycle. */
    public function totals(string $tenant, string $user): array
    {
        $team = DB::query()->fromRaw('(WITH RECURSIVE team AS (
            SELECT id,user_id FROM promotion_members WHERE tenant_id=? AND user_id=?
            UNION SELECT m.id,m.user_id FROM promotion_members m JOIN team p ON m.inviter_id=p.id WHERE m.tenant_id=?
        ) SELECT user_id FROM team WHERE user_id<>?) AS team', [$tenant, $user, $tenant, $user])->select('user_id');
        $sources = [
            ['wallet_topup_orders', 'CREDITED', 'ledger_entry_id', true],
            ['asset_deposit_orders', 'CREDITED', 'ledger_entry_id', true],
            ['withdrawal_orders', 'SUCCEEDED', 'settlement_ledger_entry_id', false],
            ['asset_withdrawal_orders', 'COMPLETED', 'ledger_entry_id', false],
        ];
        $union = null;
        foreach ($sources as [$table, $status, $ledger, $incoming]) {
            $query = DB::table($table.' as o')->join('ledger_entries as e', fn ($j) => $j->on('e.id', '=', 'o.'.$ledger)->on('e.tenant_id', '=', 'o.tenant_id')->on('e.asset_code', '=', 'o.asset_code'))
                ->where('o.tenant_id', $tenant)->whereIn('o.user_id', clone $team)->where('o.status', $status)
                ->select('o.asset_code')->selectRaw($incoming ? 'o.amount AS inflow, 0::numeric AS outflow' : '0::numeric AS inflow, o.amount AS outflow');
            $union = $union ? $union->unionAll($query) : $query;
        }

        return DB::query()->fromSub($union, 'flows')->select('asset_code')->selectRaw('SUM(inflow) AS inflow, SUM(outflow) AS outflow')->groupBy('asset_code')->orderBy('asset_code')->get()->all();
    }
}
