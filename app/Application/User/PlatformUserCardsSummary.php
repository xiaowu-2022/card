<?php

namespace App\Application\User;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PlatformUserCardsSummary
{
    /** Aggregate unique business orders before joining: provider transactions cannot duplicate loads. */
    public function query(string $tenant, string $user, bool $archived): Builder
    {
        $loads = DB::table('card_management_orders')->where('tenant_id', $tenant)->where('user_id', $user)->where('status', 'SUCCEEDED')
            ->select('card_id')->selectRaw("COALESCE(SUM(arrival_amount+manual_funding_amount) FILTER(WHERE kind='LOAD'),0) AS funded, COALESCE(SUM(arrival_amount) FILTER(WHERE kind IN ('RETURN','CANCEL_RETURN')),0) AS returned")->groupBy('card_id');
        $overflow = DB::table('ledger_accounts')->where('tenant_id', $tenant)->where('user_id', $user)->where('account_type', 'USER_CARD_OVERFLOW')->where('asset_code', 'USDT')->select('card_id')->selectRaw('SUM(balance) AS balance')->groupBy('card_id');
        $overflowReturns = DB::table('card_overflow_movements')->where('tenant_id', $tenant)->where('user_id', $user)->where('kind', 'RETURN')
            ->select('card_id')->selectRaw('SUM(amount) AS returned')->groupBy('card_id');
        $native = DB::table('user_cards as c')->join('card_products as p', 'p.id', '=', 'c.card_product_id')
            ->leftJoin('tenant_card_product_configs as config', fn ($j) => $j->on('config.card_product_id', '=', 'c.card_product_id')->on('config.tenant_id', '=', 'c.tenant_id'))
            ->leftJoin('card_issue_orders as i', fn ($j) => $j->on('i.id', '=', 'c.card_issue_order_id')->on('i.tenant_id', '=', 'c.tenant_id')->on('i.user_id', '=', 'c.user_id')->where('i.status', 'SUCCEEDED'))
            ->leftJoinSub($loads, 'l', 'l.card_id', '=', 'c.id')->leftJoinSub($overflow, 'o', 'o.card_id', '=', 'c.id')->leftJoinSub($overflowReturns, 'r', 'r.card_id', '=', 'c.id')
            ->where('c.tenant_id', $tenant)->where('c.user_id', $user)->when($archived, fn ($q) => $q->whereNotNull('c.archived_at'), fn ($q) => $q->whereNull('c.archived_at'))
            ->selectRaw("c.id, COALESCE(config.display_name,p.name) AS name, c.last4, c.provider_balance_synced_at AS updated_at, c.created_at,
                (COALESCE(i.initial_load_amount,0)+COALESCE(l.funded,0)) AS funded, COALESCE(l.returned,0)+COALESCE(r.returned,0) AS returned,
                CASE WHEN c.card_currency='USD' THEN c.provider_balance+COALESCE(o.balance,0) ELSE NULL END AS balance");

        return DB::query()->fromSub($native, 'cards')->select('id', 'name', 'last4', 'updated_at', 'created_at')->selectRaw('funded::text AS funded, returned::text AS returned, balance::numeric(38,8)::text AS balance, (funded-returned-balance)::numeric(38,8)::text AS consumed');
    }

    public function read(string $tenant, string $user, bool $archived, int $page): array
    {
        abort_unless(DB::table('users')->where('tenant_id', $tenant)->where('id', $user)->exists(), 404);
        $query = $this->query($tenant, $user, $archived);
        $sum = DB::query()->fromSub(clone $query, 'summary')->selectRaw('COUNT(*) AS count, COUNT(*) FILTER(WHERE balance IS NULL) AS missing, COALESCE(SUM(funded::numeric),0)::text AS funded, COALESCE(SUM(returned::numeric),0)::text AS returned, COALESCE(SUM(balance::numeric),0)::text AS balance, COALESCE(SUM(consumed::numeric),0)::text AS consumed')->first();

        return ['items' => $query->orderByDesc('created_at')->orderBy('id')->forPage($page, 20)->get(), 'page' => $page, 'total' => (int) $sum->count, 'hasMore' => $page * 20 < $sum->count, 'archived' => $archived,
            'totals' => ['funded' => $sum->funded, 'returned' => $sum->returned, 'balance' => $sum->missing ? null : $sum->balance, 'consumed' => $sum->missing ? null : $sum->consumed], 'readAt' => now()->toIso8601String()];
    }
}
