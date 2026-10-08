<?php

namespace App\Application\Partners;

use App\Application\Promotion\PromotionReportQuery;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only business stock. Totals and drilldown use the same scoped entries. */
final class PartnerBusinessStock
{
    public function totals(string $tenant, string $user): array
    {
        $sums = DB::query()->fromSub($this->entries($tenant, $user), 'entries')
            ->select('category')->selectRaw('SUM(amount) AS amount')->groupBy('category')->pluck('amount', 'category');
        $totals = [];
        foreach (['deposits', 'annual', 'activation', 'annualCommission', 'otherCommission', 'rebates', 'reimbursements'] as $key) {
            $totals[$key] = (string) BigDecimal::of($sums[$key] ?? '0')->toScale(8, RoundingMode::HalfUp);
        }
        $totals['inflow'] = (string) BigDecimal::of($totals['deposits'])->plus($totals['annual']);
        $totals['outflow'] = (string) BigDecimal::of($totals['activation'])->plus($totals['annualCommission'])->plus($totals['otherCommission'])->plus($totals['rebates'])->plus($totals['reimbursements']);

        return $totals;
    }

    /** Daily activity, not historical snapshots of the current deposit balance. */
    public function trends(string $tenant, string $user, CarbonImmutable $at, string $timezone): array
    {
        $today = $at->setTimezone($timezone)->startOfDay();
        $team = app(PartnerStockTeam::class)->members($tenant, $user)->select('user_id');
        $firstDeposits = DB::table('account_activations as x')
            ->join('ledger_postings as p', fn ($j) => $j->on('p.ledger_entry_id', '=', 'x.ledger_entry_id')->on('p.tenant_id', '=', 'x.tenant_id'))
            ->join('ledger_accounts as a', fn ($j) => $j->on('a.id', '=', 'p.ledger_account_id')->on('a.tenant_id', '=', 'p.tenant_id')->on('a.user_id', '=', 'x.user_id'))
            ->where('x.tenant_id', $tenant)->whereIn('x.user_id', $team)->where('x.source_type', 'DEPOSIT')
            ->where('a.account_type', 'USER_SECURITY_DEPOSIT')->where('a.asset_code', 'USDT')->where('p.delta', '>', 0)
            ->selectRaw("'deposits'::text AS category, p.delta AS amount, x.activated_at AS posted_at");
        $activity = DB::query()->fromSub($this->entries($tenant, $user), 'entries')
            ->where('category', '<>', 'deposits')->select('category', 'amount', 'posted_at')->unionAll($firstDeposits);
        $sql = 'COALESCE(SUM(amount) FILTER(WHERE posted_at>=?),0) AS today';
        $bindings = [$today->utc()];
        foreach ([3, 7, 15, 30] as $days) {
            $sql .= ", COALESCE(SUM(amount) FILTER(WHERE posted_at>=? AND posted_at<?),0) AS d$days";
            array_push($bindings, $today->subDays($days)->utc(), $today->utc());
        }
        $rows = DB::query()->fromSub($activity, 'activity')->where('posted_at', '>=', $today->subDays(30)->utc())
            ->where('posted_at', '<=', $at)->select('category')->selectRaw($sql, $bindings)->groupBy('category')->get()->keyBy('category');
        $trends = [];
        foreach (['deposits', 'annual', 'activation', 'annualCommission', 'otherCommission', 'rebates', 'reimbursements'] as $category) {
            $row = $rows->get($category);
            $trends[$category] = ['today' => (string) BigDecimal::of($row->today ?? '0')->toScale(8, RoundingMode::HalfUp)];
            foreach ([3, 7, 15, 30] as $days) {
                $trends[$category][(string) $days] = (string) BigDecimal::of($row->{'d'.$days} ?? '0')->dividedBy($days, 8, RoundingMode::HalfUp);
            }
        }

        return $trends;
    }

    public function details(string $tenant, string $user, string $direction, int $page): array
    {
        abort_unless(in_array($direction, ['inflow', 'outflow'], true), 422);
        $query = DB::query()->fromSub($this->entries($tenant, $user), 'f')
            ->join('users as u', fn ($j) => $j->on('u.id', '=', 'f.user_id')->where('u.tenant_id', $tenant))
            ->leftJoin('users as d', fn ($j) => $j->on('d.id', '=', 'f.direct_user_id')->where('d.tenant_id', $tenant))
            ->where('f.direction', $direction);
        $total = (clone $query)->count();
        $items = $query->orderByRaw('f.posted_at DESC NULLS FIRST')->orderBy('f.category')->orderBy('f.id')
            ->offset(($page - 1) * 20)->limit(20)
            ->get(['f.id', 'f.category as source', 'f.amount', 'f.posted_at', 'u.account_id', 'u.email',
                'd.account_id as direct_account_id', 'd.email as direct_email'])
            ->map(fn ($row) => [...(array) $row, 'asset_code' => 'USDT', 'amountUsdt' => (string) BigDecimal::of($row->amount)->toScale(8),
                'posted_at' => $row->posted_at === null ? null : CarbonImmutable::parse($row->posted_at)->toIso8601String()])->all();

        return ['direction' => $direction, 'items' => $items, 'page' => $page, 'total' => $total, 'hasMore' => $page * 20 < $total];
    }

    private function entries(string $tenant, string $user): Builder
    {
        $team = app(PartnerStockTeam::class)->members($tenant, $user);
        // The remaining guarantee balance, not lifetime positive funding: conversion to
        // annual fees and completed refunds already leave this account. Never count twice.
        $deposits = DB::table('ledger_accounts as a')->joinSub(clone $team, 'team', 'team.user_id', '=', 'a.user_id')
            ->where('a.tenant_id', $tenant)->where('a.asset_code', 'USDT')->where('a.account_type', 'USER_SECURITY_DEPOSIT')->where('a.balance', '<>', 0)
            ->selectRaw("a.id, a.user_id, team.direct_user_id, a.balance AS amount, NULL::timestamptz AS posted_at, 'deposits'::text AS category, 'inflow'::text AS direction");
        $annual = DB::table('paid_promotion_orders as o')->joinSub(clone $team, 'team', 'team.user_id', '=', 'o.user_id')
            ->join('ledger_entries as l', fn ($j) => $j->on('l.id', '=', 'o.ledger_entry_id')->on('l.tenant_id', '=', 'o.tenant_id')->where('l.asset_code', 'USDT'))
            ->where('o.tenant_id', $tenant)->where('o.status', 'COMPLETED')
            ->selectRaw("o.id, o.user_id, team.direct_user_id, o.settlement_total AS amount, l.posted_at, 'annual'::text AS category, 'inflow'::text AS direction");
        // Deduct income received by current non-partner descendants, regardless of
        // source payer. Include signed manual income, retaining legacy deduplication.
        $commissions = app(PromotionReportQuery::class)->income($tenant, null, true)
            ->joinSub(clone $team, 'team', 'team.user_id', '=', 'income.beneficiary_user_id')
            ->selectRaw("income.id, income.beneficiary_user_id AS user_id, team.direct_user_id, income.amount, income.occurred_at AS posted_at,
                CASE WHEN income.kind='annual' THEN 'annualCommission'
                    WHEN income.kind IN ('activation','legacy') THEN 'activation'
                    ELSE 'otherCommission' END AS category, 'outflow'::text AS direction");
        $rebates = DB::table('paid_promotion_rebates as r')->joinSub(clone $team, 'team', 'team.user_id', '=', 'r.user_id')
            ->join('ledger_entries as l', fn ($j) => $j->on('l.id', '=', 'r.ledger_entry_id')->on('l.tenant_id', '=', 'r.tenant_id')->where('l.asset_code', 'USDT'))
            ->where('r.tenant_id', $tenant)->where('r.status', 'APPROVED')
            ->selectRaw("r.id, r.user_id, team.direct_user_id, r.amount, l.posted_at, 'rebates'::text AS category, 'outflow'::text AS direction");
        // Cooperation reimbursements belong to partner journals, including the owner.
        // Do not filter them with the non-partner contribution scope.
        $all = app(PartnerStockTeam::class)->members($tenant, $user, false);
        $journal = DB::table('partner_journal_entries as j')
            ->join('partner_configurations as p', fn ($j) => $j->on('p.id', '=', 'j.partner_id')->on('p.tenant_id', '=', 'j.tenant_id'))
            ->leftJoinSub($all, 'team', 'team.user_id', '=', 'p.user_id')
            ->where('j.tenant_id', $tenant)->where('j.kind', 'REIMBURSEMENT')
            ->where(fn ($q) => $q->where('p.user_id', $user)->orWhereNotNull('team.user_id'))
            ->selectRaw("j.id, p.user_id, team.direct_user_id, CASE WHEN j.reverses_id IS NULL THEN j.amount ELSE -j.amount END AS amount,
                j.created_at AS posted_at, 'reimbursements'::text AS category, 'outflow'::text AS direction");

        return $deposits->unionAll($annual)->unionAll($commissions)->unionAll($rebates)->unionAll($journal);
    }
}
