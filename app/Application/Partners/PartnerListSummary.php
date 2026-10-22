<?php

namespace App\Application\Partners;

use App\Application\Tenant\PlatformFundsFilters;
use App\Application\User\PlatformUserFinancialSummary;
use App\Application\User\PlatformUserListIdentity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** One set of aggregate queries per company/current page; never load full row reports. */
final class PartnerListSummary
{
    public function forRows(array $partners): array
    {
        $at = CarbonImmutable::now();
        $result = [];
        foreach (collect($partners)->groupBy('tenant_id') as $tenant => $rows) {
            $timezone = PlatformFundsFilters::TIMEZONE;
            $today = $at->setTimezone($timezone)->startOfDay();
            $users = $rows->map(fn ($p) => (object) ['id' => $p->user_id, 'tenant_id' => $tenant])->all();
            $identity = app(PlatformUserListIdentity::class)->forUsers($users);
            $financial = app(PlatformUserFinancialSummary::class)->forUsers($users, ['commission' => true]);
            $ids = $rows->pluck('id')->all();
            $ownAdvances = app(UserAdvances::class)->ownTotals($tenant, $rows->pluck('user_id')->all());
            $annualSubsidies = app(PartnerAnnualSubsidies::class)->totals($tenant, $rows->pluck('id')->all());
            $subordinateAdvances = app(UserAdvances::class)->subordinateTotals($tenant, $rows->pluck('user_id')->all());
            $journals = DB::table('partner_journal_entries')->where('tenant_id', $tenant)->whereIn('partner_id', $ids)->select('partner_id')->selectRaw("COALESCE(SUM(CASE WHEN reverses_id IS NULL THEN amount ELSE -amount END) FILTER(WHERE kind='ADVANCE'),0)::text AS advance, COALESCE(SUM(CASE WHEN reverses_id IS NULL THEN amount ELSE -amount END) FILTER(WHERE kind='REIMBURSEMENT'),0)::text AS reimbursement, COALESCE(SUM((CASE WHEN reverses_id IS NULL THEN amount ELSE -amount END)*(CASE WHEN kind='ADJUSTMENT_DECREASE' THEN -1 ELSE 1 END)) FILTER(WHERE kind IN ('ADJUSTMENT_INCREASE','ADJUSTMENT_DECREASE')),0)::text AS adjustment, COUNT(*) FILTER(WHERE kind IN ('ADJUSTMENT_INCREASE','ADJUSTMENT_DECREASE')) AS adjustment_count")->groupBy('partner_id')->get()->keyBy('partner_id');
            $actual = DB::table('ledger_accounts')->where('tenant_id', $tenant)->whereIn('user_id', $rows->pluck('user_id'))->where('asset_code', 'USDT')->where('account_type', 'USER_AVAILABLE')->select('user_id')->selectRaw('SUM(balance)::text AS balance')->groupBy('user_id')->pluck('balance', 'user_id');
            $entries = $activity = $risks = $refunds = $legacy = null;
            foreach ($rows as $p) {
                $this->append($entries, DB::query()->fromSub(app(PartnerBusinessStock::class)->entries($tenant, $p->user_id), 'e')->select('category', 'amount')->selectRaw('?::uuid AS root', [$p->id]));
                if (! $p->enabled) {
                    $this->append($legacy, DB::query()->fromSub(app(LegacyListStock::class)->query($tenant, $p->user_id, $at), 'legacy')->select('amount', 'missing')->selectRaw('?::uuid AS root', [$p->id]));
                }
                $this->append($activity, DB::query()->fromSub(app(PartnerBusinessStock::class)->activity($tenant, $p->user_id), 'a')->select('category', 'amount', 'posted_at')->selectRaw('?::uuid AS root', [$p->id]));
                $team = app(LegacyStockReport::class)->team($tenant, $p->user_id);
                $this->append($risks, app(LegacyStockReport::class)->activeRisks($tenant, clone $team, $at)->selectRaw('?::uuid AS root, (p.paid-COALESCE(r.returned,0)) AS amount', [$p->id]));
                $this->append($refunds, DB::table('security_deposit_refund_requests')->where('tenant_id', $tenant)->whereIn('user_id', $team)->where('status', 'CHECKING')->whereNull('cancel_requested_at')->where('refund_eligible_at', '>=', $at->toIso8601String())->where('refund_eligible_at', '<=', $at->addDays(10)->toIso8601String())
                    ->selectRaw('?::uuid AS root, amount, refund_eligible_at', [$p->id]));
            }
            if (! $entries) {
                continue;
            }
            $totals = DB::query()->fromSub($entries, 'entries')->select('root')->selectRaw("COALESCE(SUM(CASE WHEN category IN ('deposits','annual') THEN amount ELSE -amount END),0)::text AS stock")->groupBy('root')->pluck('stock', 'root');
            $legacyTotals = $legacy ? DB::query()->fromSub($legacy, 'legacy')->select('root')->selectRaw('COALESCE(SUM(amount),0)::text AS stock, COUNT(*) FILTER(WHERE missing) AS missing')->groupBy('root')->get()->keyBy('root') : collect();
            $sql = 'COALESCE(SUM(amount) FILTER(WHERE posted_at>=?),0)::text AS today';
            $bindings = [$today->utc()->toIso8601String()];
            foreach ([3, 7, 15, 30] as $days) {
                $sql .= ", COALESCE(SUM(amount) FILTER(WHERE posted_at>=? AND posted_at<?),0)::text AS d$days";
                array_push($bindings, $today->subDays($days)->utc()->toIso8601String(), $today->utc()->toIso8601String());
            }
            $trends = DB::query()->fromSub($activity, 'activity')->where('posted_at', '>=', $today->subDays(30)->utc()->toIso8601String())->where('posted_at', '<=', $at->toIso8601String())->select('root', 'category')->selectRaw($sql, $bindings)->groupBy('root', 'category')->get()->groupBy('root');
            $remaining = DB::query()->fromSub($risks, 'risks')->select('root')->selectRaw('COALESCE(SUM(amount),0)::text AS amount')->groupBy('root')->pluck('amount', 'root');
            $pending = DB::query()->fromSub($refunds, 'refunds')->select('root')->selectRaw('COALESCE(SUM(amount),0)::text AS d10, COALESCE(SUM(amount) FILTER(WHERE refund_eligible_at<=?),0)::text AS d3', [$at->addDays(3)->toIso8601String()])->groupBy('root')->get()->keyBy('root');
            foreach ($rows as $p) {
                $j = $journals->get($p->id);
                $f = $financial[$tenant.':'.$p->user_id];
                $balance = PartnerAccountBalance::calculate($ownAdvances[$p->user_id] ?? '0', $f['annualExpense'], $f['activationCommission'], $f['annualCommission'], $f['unclassifiedCommission'], $j->reimbursement ?? '0', $j->adjustment ?? '0', $actual[$p->user_id] ?? '0', ($j->adjustment_count ?? 0) > 0);
                $daily = [];
                $byCategory = $trends->get($p->id, collect())->keyBy('category');
                foreach (['deposits', 'annual', 'depositRefunds', 'activation', 'rebates', 'annualCommission'] as $category) {
                    $r = $byCategory->get($category);
                    $daily[$category] = ['today' => (string) BigDecimal::of($r->today ?? '0')->toScale(8, RoundingMode::HalfUp)];
                    foreach ([3, 7, 15, 30] as $d) {
                        $daily[$category][(string) $d] = (string) BigDecimal::of($r->{'d'.$d} ?? '0')->dividedBy($d, 8, RoundingMode::HalfUp);
                    }
                }
                $result[$p->id] = $identity[$tenant.':'.$p->user_id] + ['summary' => ['advance' => (string) BigDecimal::of($ownAdvances[$p->user_id] ?? '0')->toScale(8), 'annualSubsidy' => $annualSubsidies[$p->id] ?? '0.00000000', 'subordinateAdvances' => (string) BigDecimal::of($subordinateAdvances[$p->user_id] ?? '0')->toScale(8), 'stock' => $p->enabled ? PartnerStockValue::afterDifference($totals[$p->id] ?? '0', $balance['difference'], $subordinateAdvances[$p->user_id] ?? '0', $annualSubsidies[$p->id] ?? '0') : ($legacyTotals->get($p->id)?->missing ? null : ($legacyTotals->get($p->id)?->stock ?? '0')), 'theoretical' => $p->enabled ? $balance['theoretical'] : null, 'actual' => $p->enabled ? $balance['actual'] : null, 'difference' => $p->enabled ? $balance['difference'] : null, 'reimbursement' => $j->reimbursement ?? '0', 'daily' => $daily, 'alerts' => ['remaining' => $remaining[$p->id] ?? '0', 'refund10' => $pending->get($p->id)?->d10 ?? '0', 'refund3' => $pending->get($p->id)?->d3 ?? '0'], 'timezone' => $timezone, 'updatedAt' => $at->toIso8601String()]];
            }
        }

        return $result;
    }

    private function append(?Builder &$union, Builder $query): void
    {
        if ($union === null) {
            $union = $query;
        } else {
            $union->unionAll($query);
        }
    }
}
