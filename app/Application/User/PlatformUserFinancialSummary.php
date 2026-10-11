<?php

namespace App\Application\User;

use App\Application\Assets\AssetValuation;
use App\Application\Assets\MarketPrices;
use App\Application\Promotion\PromotionReportQuery;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Current-page batch reads. No provider calls, provisioning or history repair. */
final class PlatformUserFinancialSummary
{
    public function forUsers(array $users, array $access): array
    {
        $result = [];
        $snapshot = ($access['funds'] ?? false) ? app(MarketPrices::class)->latest() : null;
        foreach (collect($users)->groupBy('tenant_id') as $tenant => $rows) {
            $ids = $rows->pluck('id')->all();
            $accounts = ($access['funds'] ?? false) ? DB::table('ledger_accounts')->where('tenant_id', $tenant)->whereIn('user_id', $ids)->get()->groupBy('user_id') : collect();
            $cards = ($access['funds'] ?? false) ? DB::table('user_cards')->where('tenant_id', $tenant)->whereIn('user_id', $ids)->whereNull('archived_at')->get(['id', 'user_id', 'provider_balance', 'card_currency'])->groupBy('user_id') : collect();
            $income = ($access['commission'] ?? false) ? app(PromotionReportQuery::class)->income($tenant, null, true)->whereIn('beneficiary_user_id', $ids)
                ->select('beneficiary_user_id')->selectRaw("COALESCE(SUM(amount) FILTER(WHERE kind IN ('activation','legacy')),0)::text AS activation, COALESCE(SUM(amount) FILTER(WHERE kind='annual'),0)::text AS annual, COALESCE(SUM(amount) FILTER(WHERE kind='commission'),0)::text AS unclassified")->groupBy('beneficiary_user_id')->get()->keyBy('beneficiary_user_id') : collect();
            $annual = ($access['commission'] ?? false) ? DB::table('paid_promotion_orders')->where('tenant_id', $tenant)->whereIn('user_id', $ids)->where('status', 'COMPLETED')->whereNotNull('ledger_entry_id')->select('user_id')->selectRaw('SUM(settlement_total)::text AS amount')->groupBy('user_id')->pluck('amount', 'user_id') : collect();
            $transfers = [];
            if ($access['commission'] ?? false) {
                foreach (['incoming' => 'recipient_user_id', 'outgoing' => 'sender_user_id'] as $kind => $column) {
                    foreach (DB::table('wallet_transfers')->where('tenant_id', $tenant)->whereIn($column, $ids)->select($column.' as user_id', 'asset_code')->selectRaw('SUM(amount)::text AS amount')->groupBy($column, 'asset_code')->get() as $r) {
                        $transfers[$r->user_id][$kind][] = ['asset' => $r->asset_code, 'amount' => $r->amount];
                    }
                }
            }
            $withdrawals = [];
            if ($access['withdrawals'] ?? false) {
                foreach (['withdrawal_orders' => 'SUCCEEDED', 'asset_withdrawal_orders' => 'COMPLETED'] as $table => $status) {
                    foreach (DB::table($table)->where('tenant_id', $tenant)->whereIn('user_id', $ids)->where('status', $status)->select('user_id', 'asset_code')->selectRaw('SUM(amount)::text AS amount')->groupBy('user_id', 'asset_code')->get() as $r) {
                        $withdrawals[$r->user_id][$r->asset_code] = (string) BigDecimal::of($withdrawals[$r->user_id][$r->asset_code] ?? '0')->plus($r->amount);
                    }
                }
            }
            foreach ($ids as $id) {
                $s = [];
                if ($access['funds'] ?? false) {
                    $v = app(AssetValuation::class)->calculate($accounts->get($id, collect()), $cards->get($id, collect()), $snapshot);
                    $s += ['estimate' => $v['estimate'], 'cardBalance' => $v['cardBalance'], 'updatedAt' => $v['updatedAt']];
                }
                if ($access['commission'] ?? false) {
                    $s += ['annualCommission' => $income->get($id)?->annual ?? '0', 'activationCommission' => $income->get($id)?->activation ?? '0', 'unclassifiedCommission' => $income->get($id)?->unclassified ?? '0', 'annualExpense' => $annual[$id] ?? '0', 'transfers' => $transfers[$id] ?? ['incoming' => [], 'outgoing' => []]];
                }
                if ($access['withdrawals'] ?? false) {
                    $s['withdrawals'] = collect($withdrawals[$id] ?? ['USDT' => '0'])->map(fn ($amount, $asset) => ['asset' => $asset, 'amount' => $amount])->values()->all();
                }
                $result[$tenant.':'.$id] = $s;
            }
        }

        return $result;
    }

    public function funds(string $tenant, string $user): array
    {
        abort_unless(DB::table('users')->where('tenant_id', $tenant)->where('id', $user)->exists(), 404);

        return app(AssetValuation::class)->calculate(
            DB::table('ledger_accounts')->where('tenant_id', $tenant)->where('user_id', $user)->get(),
            DB::table('user_cards')->where('tenant_id', $tenant)->where('user_id', $user)->whereNull('archived_at')->get(),
            app(MarketPrices::class)->latest());
    }
}
