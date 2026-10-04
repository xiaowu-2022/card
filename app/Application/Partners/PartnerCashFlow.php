<?php

namespace App\Application\Partners;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PartnerCashFlow
{
    /** Successful external orders only; totals and details share exactly the same evidence. */
    public function totals(string $tenant, string $user): array
    {
        return DB::query()->fromSub($this->flows($tenant, $user), 'flows')->select('asset_code')
            ->selectRaw("SUM(CASE WHEN direction='inflow' THEN amount ELSE 0 END) AS inflow, SUM(CASE WHEN direction='outflow' THEN amount ELSE 0 END) AS outflow")
            ->groupBy('asset_code')->orderBy('asset_code')->get()->all();
    }

    public function details(string $tenant, string $user, string $direction, int $page): array
    {
        abort_unless(in_array($direction, ['inflow', 'outflow'], true), 422);
        $query = DB::query()->fromSub($this->flows($tenant, $user), 'f')
            ->join('users as u', fn ($j) => $j->on('u.id', '=', 'f.user_id')->where('u.tenant_id', $tenant))
            ->join('users as d', fn ($j) => $j->on('d.id', '=', 'f.direct_user_id')->where('d.tenant_id', $tenant))
            ->where('f.direction', $direction);
        $total = (clone $query)->count();
        $items = $query->orderByDesc('f.posted_at')->orderBy('f.source')->orderBy('f.id')
            ->offset(($page - 1) * 20)->limit(20)
            ->get(['f.id', 'f.source', 'f.asset_code', 'f.amount', 'f.posted_at', 'u.account_id', 'u.email',
                'd.account_id as direct_account_id', 'd.email as direct_email'])
            ->map(fn ($row) => (array) $row)->all();

        return ['direction' => $direction, 'items' => $items, 'page' => $page, 'total' => $total, 'hasMore' => $page * 20 < $total];
    }

    private function flows(string $tenant, string $user): Builder
    {
        // Carry the owner's first-level branch through every depth. UNION terminates cycles;
        // excluding the owner prevents looping back into another branch or counting own money.
        $team = DB::query()->fromRaw('(WITH RECURSIVE team AS (
            SELECT m.id,m.user_id,m.user_id AS direct_user_id FROM promotion_members m
            JOIN promotion_members owner ON owner.id=m.inviter_id AND owner.tenant_id=m.tenant_id
            WHERE m.tenant_id=? AND owner.user_id=? AND m.user_id<>?
            UNION SELECT m.id,m.user_id,p.direct_user_id FROM promotion_members m JOIN team p ON m.inviter_id=p.id
            WHERE m.tenant_id=? AND m.user_id<>?
        ) SELECT DISTINCT ON (user_id) user_id,direct_user_id FROM team ORDER BY user_id,direct_user_id) AS team',
            [$tenant, $user, $user, $tenant, $user])->select('user_id', 'direct_user_id')
            // Filter after traversal: an enabled partner is excluded personally,
            // but their non-partner descendants and original branch remain eligible.
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('partner_configurations as partner')
                ->where('partner.tenant_id', $tenant)
                ->whereColumn('partner.user_id', 'team.user_id')
                ->where('partner.enabled', true));
        $sources = [
            ['wallet_topup_orders', 'CREDITED', 'ledger_entry_id', 'inflow'],
            ['asset_deposit_orders', 'CREDITED', 'ledger_entry_id', 'inflow'],
            ['withdrawal_orders', 'SUCCEEDED', 'settlement_ledger_entry_id', 'outflow'],
            ['asset_withdrawal_orders', 'COMPLETED', 'ledger_entry_id', 'outflow'],
        ];
        $union = null;
        foreach ($sources as [$table, $status, $ledger, $direction]) {
            $query = DB::table($table.' as o')
                ->join('ledger_entries as e', fn ($j) => $j->on('e.id', '=', 'o.'.$ledger)->on('e.tenant_id', '=', 'o.tenant_id')->on('e.asset_code', '=', 'o.asset_code'))
                ->joinSub(clone $team, 'team', fn ($j) => $j->on('team.user_id', '=', 'o.user_id'))
                ->where('o.tenant_id', $tenant)->where('o.status', $status)
                ->select('o.id', 'o.user_id', 'team.direct_user_id', 'o.asset_code', 'o.amount', 'e.created_at as posted_at')
                ->selectRaw('?::text AS source, ?::text AS direction', [$table, $direction]);
            $union = $union ? $union->unionAll($query) : $query;
        }

        return $union;
    }
}
