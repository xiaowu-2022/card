<?php

namespace App\Application\Partners;

use App\Application\Promotion\PromotionReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Disabled partner rows retain the standard report's historical formula. */
final class LegacyListStock
{
    public function query(string $tenant, string $user, CarbonImmutable $at): Builder
    {
        $team = app(LegacyStockReport::class)->team($tenant, $user);
        $deposits = DB::table('ledger_accounts as a')->where('a.tenant_id', $tenant)->whereIn('a.user_id', clone $team)->where('a.asset_code', 'USDT')->where('a.account_type', 'USER_SECURITY_DEPOSIT')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('paid_promotion_cycles as c')->whereColumn('c.tenant_id', 'a.tenant_id')->whereColumn('c.user_id', 'a.user_id')->where('c.starts_at', '<=', $at)->where('c.ends_at', '>', $at))
            ->selectRaw('a.balance AS amount, false AS missing');
        $annual = DB::table('paid_promotion_orders')->where('tenant_id', $tenant)->whereIn('user_id', clone $team)->where('status', 'COMPLETED')->whereNotNull('ledger_entry_id')->selectRaw('settlement_total AS amount, false AS missing');
        $income = app(PromotionReportQuery::class)->income($tenant, null)->whereIn('source_user_id', clone $team)->whereIn('kind', ['activation', 'legacy', 'annual'])->selectRaw('-amount AS amount, false AS missing');
        $rebates = DB::table('paid_promotion_rebates')->where('tenant_id', $tenant)->whereIn('user_id', clone $team)->where('status', 'APPROVED')->whereNotNull('ledger_entry_id')->selectRaw('-amount AS amount, false AS missing');
        $journals = DB::table('partner_journal_entries as j')->join('partner_configurations as p', fn ($j) => $j->on('p.id', '=', 'j.partner_id')->on('p.tenant_id', '=', 'j.tenant_id'))->where('j.tenant_id', $tenant)->whereIn('p.user_id', clone $team)->where('j.kind', 'REIMBURSEMENT')->selectRaw('CASE WHEN j.reverses_id IS NULL THEN -j.amount ELSE j.amount END AS amount, false AS missing');
        $fees = DB::table('asset_withdrawal_orders as w')->leftJoin('withdrawal_fee_valuations as v', fn ($j) => $j->on('v.withdrawal_id', '=', 'w.id')->on('v.tenant_id', '=', 'w.tenant_id'))->where('w.tenant_id', $tenant)->whereIn('w.user_id', clone $team)->where('w.status', 'COMPLETED')->whereNotNull('w.ledger_entry_id')->selectRaw("CASE WHEN w.asset_code='USDT' THEN w.fee_amount ELSE v.usdt_amount END AS amount, (w.asset_code<>'USDT' AND w.fee_amount>0 AND v.usdt_amount IS NULL) AS missing");
        $tron = DB::table('withdrawal_orders')->where('tenant_id', $tenant)->whereIn('user_id', clone $team)->where('status', 'SUCCEEDED')->whereNotNull('settlement_ledger_entry_id')->selectRaw('fee_amount AS amount, false AS missing');

        return $deposits->unionAll($annual)->unionAll($income)->unionAll($rebates)->unionAll($journals)->unionAll($fees)->unionAll($tron);
    }
}
