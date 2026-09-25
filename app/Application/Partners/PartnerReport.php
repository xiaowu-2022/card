<?php

namespace App\Application\Partners;

use App\Application\Promotion\PromotionReportQuery;
use App\Domain\Tenant\Models\Tenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PartnerReport
{
    public function enabled(string $tenant, string $user): bool
    {
        return DB::table('partner_configurations')->where('tenant_id', $tenant)->where('user_id', $user)->where('enabled', true)->exists();
    }

    /** UNION deduplicates nodes and also makes corrupt invitation cycles terminate safely. */
    private function team(string $tenant, string $user): Builder
    {
        return DB::query()->fromRaw('(WITH RECURSIVE team AS (
            SELECT id,user_id FROM promotion_members WHERE tenant_id=? AND user_id=?
            UNION SELECT m.id,m.user_id FROM promotion_members m JOIN team p ON m.inviter_id=p.id WHERE m.tenant_id=?
        ) SELECT user_id FROM team UNION SELECT id AS user_id FROM users WHERE tenant_id=? AND id=?) AS team', [$tenant, $user, $tenant, $tenant, $user])->select('user_id');
    }

    public function read(string $tenant, string $user, bool $requireEnabled = true, int $page = 1): array
    {
        $outer = DB::transactionLevel();

        return DB::transaction(function () use ($tenant, $user, $requireEnabled, $page, $outer) {
            if ($outer === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $partner = DB::table('partner_configurations')->where('tenant_id', $tenant)->where('user_id', $user)->when($requireEnabled, fn ($q) => $q->where('enabled', true))->first();
            abort_unless($partner, 404);
            $company = Tenant::findOrFail($tenant);
            $at = CarbonImmutable::now();
            $today = $at->setTimezone($company->timezone)->startOfDay();
            $team = $this->team($tenant, $user);
            $annual = DB::table('paid_promotion_orders')->where('tenant_id', $tenant)->whereIn('user_id', clone $team)->where('status', 'COMPLETED')->whereNotNull('ledger_entry_id');
            $costs = app(PromotionReportQuery::class)->income($tenant, null)->whereIn('source_user_id', clone $team)->whereIn('kind', ['activation', 'legacy']);
            $deposits = DB::table('ledger_accounts as a')->where('a.tenant_id', $tenant)->whereIn('a.user_id', clone $team)->where('a.asset_code', 'USDT')->where('a.account_type', 'USER_SECURITY_DEPOSIT')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('paid_promotion_cycles as c')->whereColumn('c.tenant_id', 'a.tenant_id')->whereColumn('c.user_id', 'a.user_id')->where('c.starts_at', '<=', $at)->where('c.ends_at', '>', $at));
            $rebates = DB::table('paid_promotion_rebates')->where('tenant_id', $tenant)->whereIn('user_id', clone $team)->where('status', 'APPROVED')->whereNotNull('ledger_entry_id');
            $journal = DB::table('partner_journal_entries as j')->join('partner_configurations as p', fn ($j) => $j->on('p.id', '=', 'j.partner_id')->on('p.tenant_id', '=', 'j.tenant_id'))->where('j.tenant_id', $tenant)->whereIn('p.user_id', clone $team);
            $journalTotals = (clone $journal)->selectRaw("COALESCE(SUM(CASE WHEN j.reverses_id IS NULL THEN j.amount ELSE -j.amount END) FILTER(WHERE j.kind='REIMBURSEMENT'),0) AS reimbursements, COALESCE(SUM(CASE WHEN j.reverses_id IS NULL THEN j.amount ELSE -j.amount END) FILTER(WHERE j.kind='ADVANCE'),0) AS advances")->first();
            $fees = DB::table('asset_withdrawal_orders as w')->leftJoin('withdrawal_fee_valuations as v', fn ($j) => $j->on('v.withdrawal_id', '=', 'w.id')->on('v.tenant_id', '=', 'w.tenant_id'))->where('w.tenant_id', $tenant)->whereIn('w.user_id', clone $team)->where('w.status', 'COMPLETED')->whereNotNull('w.ledger_entry_id');
            $feeTotals = (clone $fees)->selectRaw("COALESCE(SUM(CASE WHEN w.asset_code='USDT' THEN w.fee_amount ELSE v.usdt_amount END),0) AS valued, COUNT(*) FILTER(WHERE w.asset_code<>'USDT' AND w.fee_amount>0 AND v.usdt_amount IS NULL) AS missing")->first();
            $tron = DB::table('withdrawal_orders')->where('tenant_id', $tenant)->whereIn('user_id', clone $team)->where('status', 'SUCCEEDED')->whereNotNull('settlement_ledger_entry_id')->sum('fee_amount');
            $totals = ['annual' => $this->decimal((clone $annual)->sum('settlement_total')), 'deposits' => $this->decimal($deposits->sum('balance')), 'fees' => $this->decimal(BigDecimal::of($feeTotals->valued)->plus((string) $tron)), 'activation' => $this->decimal((clone $costs)->sum('amount')), 'rebates' => $this->decimal($rebates->sum('amount')), 'reimbursements' => $this->decimal($journalTotals->reimbursements), 'advances' => $this->decimal($journalTotals->advances)];
            $stock = BigDecimal::of($totals['annual'])->plus($totals['deposits'])->plus($totals['fees'])->minus($totals['activation'])->minus($totals['rebates'])->minus($totals['reimbursements']);
            $firstDeposits = DB::table('account_activations as x')->join('ledger_postings as p', fn ($j) => $j->on('p.ledger_entry_id', '=', 'x.ledger_entry_id')->on('p.tenant_id', '=', 'x.tenant_id'))->join('ledger_accounts as a', fn ($j) => $j->on('a.id', '=', 'p.ledger_account_id')->on('a.tenant_id', '=', 'p.tenant_id')->on('a.user_id', '=', 'x.user_id'))
                ->where('x.tenant_id', $tenant)->whereIn('x.user_id', clone $team)->where('x.source_type', 'DEPOSIT')->where('a.account_type', 'USER_SECURITY_DEPOSIT')->where('a.asset_code', 'USDT')->where('p.delta', '>', 0);
            $trends = [
                'activation' => $this->trend(clone $costs, 'occurred_at', 'amount', $today, $at),
                'deposits' => $this->trend($firstDeposits, 'x.activated_at', 'p.delta', $today, $at),
                'annual' => $this->trend(clone $annual, 'completed_at', 'settlement_total', $today, $at),
            ];
            $risks = $this->risks($tenant, $team, $at, $page);
            $journalPage = (clone $journal)->join('users as u', fn ($j) => $j->on('u.id', '=', 'p.user_id')->on('u.tenant_id', '=', 'p.tenant_id'))->orderByDesc('j.created_at')->orderBy('j.id')->paginate(20, ['j.id', 'j.partner_id', 'j.kind', 'j.amount', 'j.business_date', 'j.note', 'j.reverses_id', 'j.actor_id', 'j.created_at', 'u.account_id', DB::raw('EXISTS(SELECT 1 FROM partner_journal_entries reversal WHERE reversal.tenant_id=j.tenant_id AND reversal.reverses_id=j.id) AS reversed')], 'page', $page);
            $missing = (clone $fees)->where('w.asset_code', '<>', 'USDT')->where('w.fee_amount', '>', 0)->whereNull('v.usdt_amount')->orderBy('w.id')->paginate(20, ['w.id', 'w.asset_code', 'w.fee_amount', 'v.id as valuation_id'], 'page', $page);

            return ['updatedAt' => $at->toIso8601String(), 'timezone' => $company->timezone, 'partnerId' => $partner->id, 'accountId' => DB::table('users')->where('id', $user)->value('account_id'), 'sharePercent' => $partner->share_percent, 'totals' => $totals,
                'stock' => $feeTotals->missing ? null : $this->decimal($stock), 'share' => $feeTotals->missing ? null : $this->decimal($stock->multipliedBy($partner->share_percent)->dividedBy(100, 8, RoundingMode::HalfUp)), 'negative' => ! $feeTotals->missing && $stock->isNegative(), 'missingRates' => (int) $feeTotals->missing,
                'trends' => $trends, 'risks' => $risks, 'journal' => $this->page($journalPage), 'unvalued' => $this->page($missing)];
        });
    }

    private function trend(Builder $query, string $time, string $amount, CarbonImmutable $today, CarbonImmutable $at): array
    {
        $query->where($time, '>=', $today->subDays(30)->utc())->where($time, '<=', $at);
        $sql = "COALESCE(SUM($amount) FILTER(WHERE $time>=?),0) AS today";
        $bindings = [$today->utc()];
        foreach ([3, 7, 15, 30] as $days) {
            $sql .= ", COALESCE(SUM($amount) FILTER(WHERE $time>=? AND $time<?),0) AS d$days";
            array_push($bindings, $today->subDays($days)->utc(), $today->utc());
        }
        $row = $query->selectRaw($sql, $bindings)->first();
        $result = ['today' => $this->decimal($row->today)];
        foreach ([3, 7, 15, 30] as $days) {
            $result[(string) $days] = $this->decimal(BigDecimal::of($row->{'d'.$days})->dividedBy($days, 8, RoundingMode::HalfUp));
        }

        return $result;
    }

    private function risks(string $tenant, Builder $team, CarbonImmutable $at, int $page): array
    {
        $paid = DB::table('paid_promotion_orders')->where('tenant_id', $tenant)->whereIn('user_id', clone $team)->where('status', 'COMPLETED')->selectRaw('cycle_id, SUM(settlement_total) AS paid')->groupBy('cycle_id');
        $returned = DB::table('paid_promotion_rebates')->where('tenant_id', $tenant)->whereIn('user_id', clone $team)->selectRaw("cycle_id, COALESCE(SUM(amount) FILTER(WHERE status='APPROVED' AND ledger_entry_id IS NOT NULL),0) AS returned, COALESCE(SUM(amount) FILTER(WHERE status='PENDING'),0) AS pending")->groupBy('cycle_id');
        $counts = DB::table('paid_promotion_cycles as c')->join('account_activation_relations as s', fn ($j) => $j->on('s.ancestor_user_id', '=', 'c.user_id')->on('s.tenant_id', '=', 'c.tenant_id'))
            ->join('account_activations as e', fn ($j) => $j->on('e.id', '=', 's.activation_id')->on('e.tenant_id', '=', 's.tenant_id')->on('e.activated_at', '>=', 'c.starts_at')->on('e.activated_at', '<', 'c.ends_at'))
            ->leftJoin('activation_count_snapshots as k', fn ($j) => $j->on('k.activation_id', '=', 's.activation_id')->on('k.tenant_id', '=', 's.tenant_id')->on('k.ancestor_user_id', '=', 's.ancestor_user_id'))
            ->where('c.tenant_id', $tenant)->whereIn('c.user_id', clone $team)->where(fn ($q) => $q->where('e.counting_policy', 'LEGACY')->orWhere('k.eligible', true))->selectRaw('c.id, SUM(CASE WHEN s.depth=1 THEN 1 ELSE 0.5 END) AS weighted')->groupBy('c.id');
        $base = DB::table('paid_promotion_cycles as c')->join('users as u', fn ($j) => $j->on('u.id', '=', 'c.user_id')->on('u.tenant_id', '=', 'c.tenant_id'))
            ->leftJoinSub($paid, 'p', 'p.cycle_id', '=', 'c.id')->leftJoinSub($returned, 'r', 'r.cycle_id', '=', 'c.id')->leftJoinSub($counts, 'n', 'n.id', '=', 'c.id')
            ->where('c.tenant_id', $tenant)->whereIn('c.user_id', clone $team);
        $active = (clone $base)->where('c.starts_at', '<=', $at)->where('c.ends_at', '>', $at)->whereRaw('COALESCE(n.weighted,0)*10 >= c.target*7')->whereRaw('COALESCE(p.paid,0)>COALESCE(r.returned,0)');
        $expired = (clone $base)->where('c.ends_at', '<=', $at)->whereRaw('COALESCE(r.pending,0)>0');
        $sum = (clone $active)->selectRaw('COUNT(*) AS count, COALESCE(SUM(p.paid-COALESCE(r.returned,0)),0) AS remaining')->first();
        $pending = (clone $expired)->selectRaw('COUNT(*) AS count, COALESCE(SUM(r.pending),0) AS amount')->first();
        $columns = ['c.id', 'u.account_id', 'c.rank', 'c.target', 'c.ends_at', DB::raw('COALESCE(n.weighted,0) AS weighted'), DB::raw('COALESCE(p.paid,0)-COALESCE(r.returned,0) AS remaining'), DB::raw('COALESCE(r.pending,0) AS pending')];

        return ['activeCount' => (int) $sum->count, 'remaining' => $this->decimal($sum->remaining), 'expiredCount' => (int) $pending->count, 'expiredAmount' => $this->decimal($pending->amount),
            'active' => $this->page($active->orderBy('c.id')->paginate(20, $columns, 'page', $page)), 'expired' => $this->page($expired->orderBy('c.id')->paginate(20, $columns, 'page', $page))];
    }

    private function page($p): array
    {
        return ['items' => $p->items(), 'page' => $p->currentPage(), 'total' => $p->total(), 'hasMore' => $p->hasMorePages()];
    }

    private function decimal($value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(8, RoundingMode::HalfUp);
    }
}
