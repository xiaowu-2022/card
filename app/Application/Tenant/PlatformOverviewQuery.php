<?php

namespace App\Application\Tenant;

use App\Application\Assets\AssetValuation;
use App\Application\Assets\MarketPrices;
use App\Application\CardProviderDirectory\CardProviderReferenceQuery;
use App\Application\Partners\LegacyStockReport;
use App\Application\Partners\PartnerAccountBalance;
use App\Application\Partners\PartnerBusinessStock;
use App\Application\User\PlatformUserFinancialSummary;
use App\Domain\Assets\MarketSnapshot;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Full-platform read model. Only directory reporting may read providers; no financial writes. */
final class PlatformOverviewQuery
{
    public const ACTIVITY = ['deposits', 'annual', 'depositRefunds', 'activation', 'rebates', 'annualCommission'];

    public function execute(array $access): array
    {
        $at = CarbonImmutable::now();
        $today = $at->setTimezone(PlatformFundsFilters::TIMEZONE)->startOfDay();
        $snapshot = ($access['assets'] || $access['inflow'] || $access['outflow']) ? app(MarketPrices::class)->latest() : null;
        $overview = ['updatedAt' => $at->toIso8601String(), 'timezone' => PlatformFundsFilters::TIMEZONE,
            'rateAt' => $snapshot?->observed_at->toIso8601String(), 'bubble' => null];
        if ($access['inflow']) {
            $receipts = DB::table('wallet_topup_orders')->where('status', 'CREDITED')
                ->selectRaw('tenant_id, asset_code, COALESCE(actual_received_amount, amount) AS amount, credited_at AS posted_at')
                ->unionAll(DB::table('asset_deposit_orders as o')->join('ledger_entries as l', fn ($j) => $j->on('l.id', '=', 'o.ledger_entry_id')->on('l.tenant_id', '=', 'o.tenant_id')->on('l.asset_code', '=', 'o.asset_code'))
                    ->where('o.status', 'CREDITED')->selectRaw('o.tenant_id, o.asset_code, COALESCE(o.actual_received_amount,o.amount) AS amount, l.posted_at'));
            $overview['inflow'] = $this->cashPeriods($receipts, $today, $at, $snapshot);
        }
        if ($access['outflow']) {
            $withdrawals = DB::table('withdrawal_orders')->where('status', 'SUCCEEDED')
                ->selectRaw('tenant_id, asset_code, receive_amount AS amount, blockchain_confirmed_at AS posted_at')
                ->unionAll(DB::table('asset_withdrawal_orders as o')->join('ledger_entries as l', fn ($j) => $j->on('l.id', '=', 'o.ledger_entry_id')->on('l.tenant_id', '=', 'o.tenant_id')->on('l.asset_code', '=', 'o.asset_code'))
                    ->where('o.status', 'COMPLETED')->selectRaw('o.tenant_id, o.asset_code, o.amount-o.fee_amount AS amount, l.posted_at'));
            $overview['outflow'] = $this->cashPeriods($withdrawals, $today, $at, $snapshot);
        }
        if ($access['partners']) {
            $overview['partners'] = $this->partners();
            $overview['reimbursement'] = $this->decimal(DB::table('partner_journal_entries')->where('kind', 'REIMBURSEMENT')
                ->selectRaw('COALESCE(SUM(CASE WHEN reverses_id IS NULL THEN amount ELSE -amount END),0) AS amount')->value('amount'));
            $overview += $this->operations($today, $at);
        }
        if ($access['cards']) {
            // Every issued card is counted, including archived cards. Unknown is never zero.
            $overflow = DB::table('ledger_accounts')->where('account_type', 'USER_CARD_OVERFLOW')->where('asset_code', 'USDT')
                ->select('tenant_id', 'user_id', 'card_id')->selectRaw('SUM(balance) AS balance')->groupBy('tenant_id', 'user_id', 'card_id');
            $cards = DB::table('user_cards as c')->leftJoinSub($overflow, 'o', fn ($j) => $j->on('o.card_id', '=', 'c.id')->on('o.tenant_id', '=', 'c.tenant_id')->on('o.user_id', '=', 'c.user_id'))
                ->selectRaw("COALESCE(SUM(c.provider_balance+COALESCE(o.balance,0)),0) AS amount, COUNT(*) FILTER(WHERE c.provider_balance IS NULL OR c.card_currency<>'USD') AS missing")->first();
            $overview['cards'] = ['balance' => $cards->missing ? null : $this->decimal($cards->amount)];
            $funding = DB::table('card_issue_orders')->where('status', 'SUCCEEDED')
                ->selectRaw('initial_load_amount AS amount, succeeded_at AS posted_at')
                ->unionAll(DB::table('card_management_orders as o')->join('ledger_entries as l', fn ($j) => $j->on('l.id', '=', 'o.settlement_entry_id')->on('l.tenant_id', '=', 'o.tenant_id'))
                    ->where('o.status', 'SUCCEEDED')->where('o.kind', 'LOAD')
                    ->selectRaw('o.arrival_amount+o.manual_funding_amount AS amount, l.posted_at'));
            $row = DB::query()->fromSub($funding, 'funding')->where('posted_at', '<=', $at)
                ->selectRaw('COALESCE(SUM(amount),0) AS total, COALESCE(SUM(amount) FILTER(WHERE posted_at>=?),0) AS today', [$today->utc()])->first();
            $overview['cards'] += ['today' => $this->decimal($row->today), 'total' => $this->decimal($row->total)];
        }
        if ($access['providers']) {
            $overview['providerBalance'] = app(CardProviderReferenceQuery::class)->balanceTotal();
        }
        if ($access['assets']) {
            $overview['assets'] = $this->assets($snapshot);
        }

        return $overview;
    }

    private function partners(): array
    {
        $totals = array_fill_keys(['stock', 'theoretical', 'actual', 'difference'], '0.00000000');
        // Sum each enabled report's stock as requested, even when teams overlap.
        foreach (DB::table('partner_configurations')->where('enabled', true)->get(['id', 'tenant_id', 'user_id'])->groupBy('tenant_id') as $tenant => $partners) {
            foreach ($partners->chunk(100) as $rows) {
                $ids = $rows->pluck('id');
                $users = $rows->map(fn ($p) => (object) ['id' => $p->user_id, 'tenant_id' => $tenant])->all();
                $income = app(PlatformUserFinancialSummary::class)->forUsers($users, ['commission' => true]);
                $actual = DB::table('ledger_accounts')->where('tenant_id', $tenant)->whereIn('user_id', $rows->pluck('user_id'))
                    ->where('asset_code', 'USDT')->where('account_type', 'USER_AVAILABLE')->select('user_id')->selectRaw('SUM(balance)::text AS amount')->groupBy('user_id')->pluck('amount', 'user_id');
                $journals = DB::table('partner_journal_entries')->where('tenant_id', $tenant)->whereIn('partner_id', $ids)->select('partner_id')
                    ->selectRaw("COALESCE(SUM(CASE WHEN reverses_id IS NULL THEN amount ELSE -amount END) FILTER(WHERE kind='ADVANCE'),0)::text AS advance,
                        COALESCE(SUM(CASE WHEN reverses_id IS NULL THEN amount ELSE -amount END) FILTER(WHERE kind='REIMBURSEMENT'),0)::text AS reimbursement,
                        COALESCE(SUM((CASE WHEN reverses_id IS NULL THEN amount ELSE -amount END)*(CASE WHEN kind='ADJUSTMENT_DECREASE' THEN -1 ELSE 1 END)) FILTER(WHERE kind IN ('ADJUSTMENT_INCREASE','ADJUSTMENT_DECREASE')),0)::text AS adjustment")
                    ->groupBy('partner_id')->get()->keyBy('partner_id');
                $entries = null;
                foreach ($rows as $p) {
                    $query = app(PartnerBusinessStock::class)->entries($tenant, $p->user_id);
                    $entries === null ? $entries = $query : $entries->unionAll($query);
                    $f = $income[$tenant.':'.$p->user_id];
                    $j = $journals->get($p->id);
                    $balance = PartnerAccountBalance::calculate($j->advance ?? '0', $f['annualExpense'], $f['activationCommission'], $f['annualCommission'], $f['unclassifiedCommission'], $j->reimbursement ?? '0', $j->adjustment ?? '0', $actual[$p->user_id] ?? '0', false);
                    foreach (['theoretical', 'actual', 'difference'] as $key) {
                        $totals[$key] = $this->decimal(BigDecimal::of($totals[$key])->plus($balance[$key]));
                    }
                }
                $stock = DB::query()->fromSub($entries, 'entries')->selectRaw("COALESCE(SUM(CASE WHEN category IN ('deposits','annual') THEN amount ELSE -amount END),0) AS amount")->value('amount');
                $totals['stock'] = $this->decimal(BigDecimal::of($totals['stock'])->plus($stock));
            }
        }

        return $totals;
    }

    private function members(string $tenant, bool $ordinary = false): Builder
    {
        $members = DB::table('users as u')->where('u.tenant_id', $tenant)->selectRaw('u.id AS user_id, NULL::uuid AS direct_user_id')
            ->when($ordinary, fn ($q) => $q->whereNotExists(fn ($p) => $p->selectRaw('1')->from('partner_configurations as p')
                ->where('p.tenant_id', $tenant)->whereColumn('p.user_id', 'u.id')->where('p.enabled', true)));

        return DB::query()->fromSub($members, 'members')->select('user_id', 'direct_user_id');
    }

    private function operations(CarbonImmutable $today, CarbonImmutable $at): array
    {
        $daily = array_fill_keys(self::ACTIVITY, $this->emptyPeriods(false));
        $alerts = array_fill_keys(['remaining', 'refund10', 'refund3'], '0.00000000');
        foreach (DB::table('tenants')->pluck('id') as $tenant) {
            $all = $this->members($tenant);
            $activity = app(PartnerBusinessStock::class)->activityForMembers($tenant, $all, $all, depositMembers: $this->members($tenant, true));
            $rows = DB::query()->fromSub($activity, 'activity')->where('posted_at', '>=', $today->subDays(29)->utc())->where('posted_at', '<=', $at)
                ->whereIn('category', self::ACTIVITY)->select('category')->selectRaw($this->periodSql($today, false)[0], $this->periodSql($today, false)[1])->groupBy('category')->get();
            foreach ($rows as $row) {
                foreach (array_keys($daily[$row->category]) as $key) {
                    $daily[$row->category][$key] = $this->decimal(BigDecimal::of($daily[$row->category][$key])->plus($row->$key));
                }
            }
            $risk = app(LegacyStockReport::class)->activeRisks($tenant, (clone $all)->select('user_id'), $at)
                ->selectRaw('COALESCE(SUM(p.paid-COALESCE(r.returned,0)),0) AS amount')->value('amount');
            $alerts['remaining'] = $this->decimal(BigDecimal::of($alerts['remaining'])->plus($risk));
            $refund = DB::table('security_deposit_refund_requests')->where('tenant_id', $tenant)->where('status', 'CHECKING')->whereNull('cancel_requested_at')
                ->where('refund_eligible_at', '>=', $at)->where('refund_eligible_at', '<=', $at->addDays(10))
                ->selectRaw('COALESCE(SUM(amount),0) AS d10, COALESCE(SUM(amount) FILTER(WHERE refund_eligible_at<=?),0) AS d3', [$at->addDays(3)])->first();
            foreach ([10, 3] as $d) {
                $alerts['refund'.$d] = $this->decimal(BigDecimal::of($alerts['refund'.$d])->plus($refund->{'d'.$d}));
            }
        }

        return ['daily' => $daily, 'alerts' => $alerts];
    }

    private function assets(?MarketSnapshot $snapshot): array
    {
        $join = fn ($j) => $j->on('p.tenant_id', '=', 'u.tenant_id')->on('p.user_id', '=', 'u.id')->where('p.enabled', true);
        // Aggregate native components first. Portfolio card IDs link only the matching overflow.
        $accounts = DB::table('ledger_accounts as a')->join('users as u', fn ($j) => $j->on('u.id', '=', 'a.user_id')->on('u.tenant_id', '=', 'a.tenant_id'))
            ->leftJoin('partner_configurations as p', $join)
            ->where(fn ($q) => $q->where('a.account_type', '<>', 'USER_CARD_OVERFLOW')->orWhereExists(fn ($c) => $c->selectRaw('1')->from('user_cards as c')->whereColumn('c.id', 'a.card_id')->whereColumn('c.tenant_id', 'a.tenant_id')->whereColumn('c.user_id', 'a.user_id')->whereNull('c.archived_at')))
            ->selectRaw("CASE WHEN p.id IS NULL THEN 'ordinary' ELSE 'partner' END AS scope, a.asset_code, a.account_type, SUM(a.balance)::text AS balance, 'portfolio'::text AS card_id")
            ->groupByRaw('1,2,3')->get()->groupBy('scope');
        $cards = DB::table('user_cards as c')->join('users as u', fn ($j) => $j->on('u.id', '=', 'c.user_id')->on('u.tenant_id', '=', 'c.tenant_id'))
            ->leftJoin('partner_configurations as p', $join)->whereNull('c.archived_at')
            ->selectRaw("CASE WHEN p.id IS NULL THEN 'ordinary' ELSE 'partner' END AS scope, 'portfolio'::text AS id, 'USD'::text AS card_currency,
                CASE WHEN COUNT(*) FILTER(WHERE c.provider_balance IS NULL OR c.card_currency<>'USD')>0 THEN NULL ELSE SUM(c.provider_balance)::text END AS provider_balance")
            ->groupByRaw('1')->get()->groupBy('scope');
        $result = [];
        foreach (['partner', 'ordinary'] as $scope) {
            $result[$scope] = app(AssetValuation::class)->calculate($accounts->get($scope, collect()), $cards->get($scope, collect()), $snapshot)['estimate'];
        }

        return $result;
    }

    private function cashPeriods(Builder $query, CarbonImmutable $today, CarbonImmutable $at, ?MarketSnapshot $snapshot): array
    {
        [$sql, $bindings] = $this->periodSql($today, true);
        $rows = DB::query()->fromSub($query, 'cash')->join('tenants as t', 't.id', '=', 'cash.tenant_id')->where('posted_at', '<=', $at)
            ->select('asset_code')->selectRaw($sql, $bindings)->groupBy('asset_code')->get();
        $totals = $this->emptyPeriods(true);
        $native = [];
        foreach ($rows as $row) {
            $native[$row->asset_code] = [];
            foreach (array_keys($totals) as $key) {
                $amount = BigDecimal::of($row->$key);
                $native[$row->asset_code][$key] = (string) $amount;
                $value = $amount;
                if ($row->asset_code !== 'USDT' && ! $amount->isZero()) {
                    try {
                        $rate = $snapshot ? app(MarketPrices::class)->rate($snapshot, $row->asset_code) : null;
                        $value = $rate?->isPositive() ? $amount->multipliedBy($rate) : null;
                    } catch (\Throwable) {
                        $value = null;
                    }
                }
                $totals[$key] = $totals[$key] === null || $value === null ? null : (string) BigDecimal::of($totals[$key])->plus($value);
            }
        }

        return ['totals' => array_map(fn ($v) => $v === null ? null : $this->decimal($v), $totals), 'native' => $native];
    }

    private function periodSql(CarbonImmutable $today, bool $lifetime): array
    {
        $sql = 'COALESCE(SUM(amount) FILTER(WHERE posted_at>=?),0)::text AS today';
        $bindings = [$today->utc()];
        foreach ([3, 7, 15, 30] as $d) {
            $sql .= ", COALESCE(SUM(amount) FILTER(WHERE posted_at>=?),0)::text AS d$d";
            $bindings[] = $today->subDays($d - 1)->utc();
        }
        if ($lifetime) {
            $sql .= ', COALESCE(SUM(amount),0)::text AS total';
        }

        return [$sql, $bindings];
    }

    private function emptyPeriods(bool $lifetime): array
    {
        return array_fill_keys(['today', 'd3', 'd7', 'd15', 'd30', ...($lifetime ? ['total'] : [])], '0.00000000');
    }

    private function decimal(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(8, RoundingMode::HalfUp);
    }
}
