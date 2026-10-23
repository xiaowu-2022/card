<?php

namespace App\Application\Tenant;

use App\Application\Assets\MarketPrices;
use App\Application\CardProviderDirectory\CardProviderReferenceQuery;
use App\Application\Partners\LegacyStockReport;
use App\Application\Partners\PartnerBusinessStock;
use App\Application\Partners\PartnerListSummary;
use App\Application\Partners\PlatformPartnerAncestors;
use App\Application\User\PlatformUserSummary;
use App\Domain\Assets\MarketSnapshot;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class PlatformOverviewSources
{
    public const METRICS = ['pendingWithdrawals', 'pendingDepositRefunds', 'stock', 'theoretical', 'actual', 'difference', 'reimbursement', 'inflow', 'outflow', 'cardBalance', 'cardFunding', 'providerBalance', 'partnerAssets', 'ordinaryAssets', 'deposits', 'annual', 'depositRefunds', 'activation', 'rebates', 'annualCommission', 'remaining', 'refund10', 'refund3'];

    public static function gate(string $metric): string
    {
        return match ($metric) {
            'inflow' => 'inflow', 'outflow', 'pendingWithdrawals' => 'outflow', 'cardBalance', 'cardFunding' => 'cards',
            'providerBalance' => 'providers', 'partnerAssets', 'ordinaryAssets' => 'assets', default => 'partners',
        };
    }

    public function read(string $metric, string $period, int $page, ?string $asset, int $adjustmentPage = 1): array
    {
        $outer = DB::transactionLevel();

        return DB::transaction(function () use ($metric, $period, $page, $asset, $outer, $adjustmentPage) {
            if ($outer === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $at = CarbonImmutable::now();
            $today = $at->setTimezone(PlatformFundsFilters::TIMEZONE)->startOfDay();
            $overviewQuery = app(PlatformOverviewQuery::class);
            $access = array_fill_keys(['partners', 'inflow', 'outflow', 'cards', 'providers', 'assets'], false);
            $access[self::gate($metric)] = true;
            $overview = $overviewQuery->execute($access, $at);
            $total = match ($metric) {
                'stock', 'theoretical', 'actual', 'difference' => $overview['partners'][$metric],
                'pendingDepositRefunds' => $overview['pendingDepositRefunds'],
                'pendingWithdrawals' => $overview['pendingWithdrawals'],
                'reimbursement' => $overview['reimbursement'], 'inflow', 'outflow' => $overview[$metric]['totals'][$period],
                'cardBalance' => $overview['cards']['balance'], 'cardFunding' => $overview['cards'][$period],
                'providerBalance' => $overview['providerBalance'], 'partnerAssets' => $overview['assets']['partner'],
                'ordinaryAssets' => $overview['assets']['ordinary'], 'remaining', 'refund10', 'refund3' => $overview['alerts'][$metric],
                default => $overview['daily'][$metric][$period],
            };
            if ($metric === 'providerBalance') {
                $items = app(CardProviderReferenceQuery::class)->balanceRows();
                $rows = new LengthAwarePaginator(array_slice($items, ($page - 1) * 25, 25), count($items), 25, $page);
                $rows->through(fn ($r) => [...$r, 'companyName' => null, 'userInfo' => null, 'usdt' => in_array($r['asset'], ['USD', 'USDT'], true) ? $r['amount'] : null]);
            } elseif (in_array($metric, ['stock', 'theoretical', 'actual', 'difference'], true)) {
                $rows = app(PlatformOverviewQuery::class)->topLevelPartners()->orderBy('p.tenant_id')->orderBy('p.id')->paginate(25, ['p.id', 'p.tenant_id', 'p.user_id', 'p.enabled'], 'page', $page);
                $summaries = app(PartnerListSummary::class)->forRows($rows->items());
                $rows->through(fn ($r) => ['id' => $r->id, 'companyId' => $r->tenant_id, 'userId' => $r->user_id,
                    'amount' => (string) BigDecimal::of($summaries[$r->id]['summary'][$metric])->toScale(8), 'usdt' => (string) BigDecimal::of($summaries[$r->id]['summary'][$metric])->toScale(8),
                    'source' => 'Partner report', 'asset' => 'USDT', 'time' => null]);
                $rows = PlatformUserSummary::page($rows);
            } else {
                $query = $this->source($metric, $at);
                if (in_array($metric, ['inflow', 'outflow', 'cardFunding', ...PlatformOverviewQuery::ACTIVITY], true)) {
                    $query->where('s.posted_at', '<=', $at->toIso8601String());
                    if ($period !== 'total') {
                        $days = $period === 'today' ? 1 : (int) substr($period, 1);
                        $query->where('s.posted_at', '>=', $today->subDays($days - 1)->utc()->toIso8601String());
                    }
                }
                if ($asset !== null) {
                    $query->where('s.asset_code', $asset);
                    $total = (string) BigDecimal::of((clone $query)->sum('s.amount'));
                }
                $rows = $query->join('tenants as t', 't.id', '=', 's.tenant_id')->orderBy('s.tenant_id')->orderByRaw('s.posted_at DESC NULLS FIRST')->orderBy('s.id')
                    ->paginate(25, ['s.*', 't.name as company_name'], 'page', $page);
                $snapshot = ! empty($overview['rateAt']) ? MarketSnapshot::query()->where('observed_at', $overview['rateAt'])->first() : null;
                $rows->through(function ($r) use ($snapshot) {
                    $usdt = null;
                    if ($r->amount !== null) {
                        $amount = BigDecimal::of($r->amount);
                        try {
                            $usdt = (string) (($r->asset_code === 'USDT' || $amount->isZero()) ? $amount : $amount->multipliedBy(app(MarketPrices::class)->rate($snapshot, $r->asset_code)));
                        } catch (\Throwable) {
                            // Missing saved evidence stays unknown.
                        }
                    }

                    return ['id' => $r->id, 'companyId' => $r->tenant_id, 'companyName' => $r->company_name, 'userId' => $r->user_id,
                        'amount' => $r->amount === null ? null : (string) $r->amount, 'asset' => $r->asset_code, 'usdt' => $usdt,
                        'source' => $r->source, 'time' => $r->posted_at === null ? null : CarbonImmutable::parse($r->posted_at)->toIso8601String()];
                });
                $rows = PlatformUserSummary::page($rows);
            }

            if (in_array($metric, ['pendingDepositRefunds', 'pendingWithdrawals'], true)) {
                $ancestors = app(PlatformPartnerAncestors::class)->forRows($rows->items());
                $rows->through(fn ($row) => [...$row, 'upstreamPartners' => $ancestors[$row['companyId'].':'.$row['userId']] ?? []]);
            }

            $adjustments = null;
            $recordedAmount = null;
            if ($metric === 'inflow' && $period === 'total' && ($asset === null || $asset === 'USDT')) {
                $adjustments = app(OverviewRechargeAdjustments::class)->history($at, $adjustmentPage, $period);
                $recordedAmount = $asset === null ? $overview['inflow']['recordedTotals'][$period] : $total;
                $total = $recordedAmount === null ? null : (string) BigDecimal::of($recordedAmount)->plus($adjustments['amount'])->toScale(8);
            }

            return ['adjustments' => $adjustments, 'recordedAmount' => $recordedAmount, 'metric' => $metric, 'period' => $period, 'amount' => $total, 'asset' => $asset ?? 'USDT',
                'updatedAt' => $at->toIso8601String(), 'timezone' => PlatformFundsFilters::TIMEZONE, 'rows' => ['items' => $rows->items(), 'page' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'total' => $rows->total()]];
        });
    }

    private function source(string $metric, CarbonImmutable $at): Builder
    {
        $overview = app(PlatformOverviewQuery::class);
        if (in_array($metric, ['inflow', 'outflow'], true)) {
            $source = $overview->cashSource($metric === 'outflow');
        } elseif ($metric === 'pendingWithdrawals') {
            $source = $overview->pendingWithdrawals($at);
        } elseif ($metric === 'pendingDepositRefunds') {
            $source = $overview->pendingDepositRefunds($at)->selectRaw("r.id, r.tenant_id, r.user_id, r.asset_code, r.amount, r.created_at AS posted_at, 'Deposit refunds in progress'::text AS source");
        } elseif ($metric === 'cardFunding') {
            $source = $overview->cardFundingSource();
        } elseif ($metric === 'reimbursement') {
            $source = DB::table('partner_journal_entries as j')->join('partner_configurations as p', fn ($q) => $q->on('p.id', '=', 'j.partner_id')->on('p.tenant_id', '=', 'j.tenant_id'))
                ->where('j.kind', 'REIMBURSEMENT')->selectRaw("j.id, j.tenant_id, p.user_id, 'USDT'::text AS asset_code, CASE WHEN j.reverses_id IS NULL THEN j.amount ELSE -j.amount END AS amount, j.created_at AS posted_at, 'Reimbursements'::text AS source");
        } elseif ($metric === 'cardBalance' || in_array($metric, ['partnerAssets', 'ordinaryAssets'], true)) {
            $source = $this->assetSource($metric);
        } else {
            $source = null;
            foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenant) {
                $all = $overview->members($tenant);
                if (in_array($metric, PlatformOverviewQuery::ACTIVITY, true)) {
                    $activity = app(PartnerBusinessStock::class)->activityForMembers($tenant, $all, $all, depositMembers: $overview->members($tenant, true), withIdentity: true);
                    $part = DB::query()->fromSub($activity, 'a')->where('category', $metric)
                        ->selectRaw("id, ?::uuid AS tenant_id, user_id, 'USDT'::text AS asset_code, amount, posted_at, category AS source", [$tenant]);
                } elseif ($metric === 'remaining') {
                    $part = app(LegacyStockReport::class)->activeRisks($tenant, (clone $all)->select('user_id'), $at)
                        ->selectRaw("c.id, c.tenant_id, c.user_id, 'USDT'::text AS asset_code, p.paid-COALESCE(r.returned,0) AS amount, c.ends_at AS posted_at, 'Annual fees at 70% return progress'::text AS source");
                } else {
                    $days = $metric === 'refund3' ? 3 : 10;
                    $part = DB::table('security_deposit_refund_requests')->where('tenant_id', $tenant)->where('status', 'CHECKING')->whereNull('cancel_requested_at')
                        ->where('refund_eligible_at', '>=', $at->toIso8601String())->where('refund_eligible_at', '<=', $at->addDays($days)->toIso8601String())
                        ->selectRaw("id, tenant_id, user_id, 'USDT'::text AS asset_code, amount, refund_eligible_at AS posted_at, 'Security deposit refund'::text AS source");
                }
                $source === null ? $source = $part : $source->unionAll($part);
            }
            $source ??= DB::table('users')->whereRaw('false')->selectRaw("id, tenant_id, id AS user_id, 'USDT'::text AS asset_code, 0::numeric AS amount, NULL::timestamptz AS posted_at, ''::text AS source");
        }

        return DB::query()->fromSub($source, 's');
    }

    private function assetSource(string $metric): Builder
    {
        $overflow = DB::table('ledger_accounts')->where('account_type', 'USER_CARD_OVERFLOW')->where('asset_code', 'USDT')
            ->select('tenant_id', 'user_id', 'card_id')->selectRaw('SUM(balance) AS balance')->groupBy('tenant_id', 'user_id', 'card_id');
        $cards = DB::table('user_cards as c')->leftJoinSub($overflow, 'o', fn ($j) => $j->on('o.card_id', '=', 'c.id')->on('o.tenant_id', '=', 'c.tenant_id')->on('o.user_id', '=', 'c.user_id'))
            ->selectRaw("c.id, c.tenant_id, c.user_id, 'USDT'::text AS asset_code, CASE WHEN c.card_currency='USD' THEN c.provider_balance+COALESCE(o.balance,0) ELSE NULL END AS amount, NULL::timestamptz AS posted_at, 'Total card balance'::text AS source");
        if ($metric === 'cardBalance') {
            return $cards;
        }
        $partner = $metric === 'partnerAssets';
        $scope = fn ($q, $tenant, $user) => $q->whereExists(fn ($p) => $p->selectRaw('1')->from('users as u')->whereColumn('u.id', $user)->whereColumn('u.tenant_id', $tenant))
            ->whereExists(fn ($p) => $p->selectRaw('1')->from('partner_configurations as p')->whereColumn('p.tenant_id', $tenant)->whereColumn('p.user_id', $user)->where('p.enabled', true), 'and', ! $partner);
        $cards->whereNull('c.archived_at');
        $scope($cards, 'c.tenant_id', 'c.user_id');
        $accounts = DB::table('ledger_accounts as a')->whereIn('a.asset_code', ['USDT', 'USDC', 'ETH', 'BTC'])
            ->where(fn ($q) => $q->whereIn('a.account_type', ['USER_AVAILABLE', 'USER_WITHDRAWAL_HOLD', 'USER_CARD_ISSUE_HOLD', 'USER_CARD_FUNDING_HOLD', 'USER_WEALTH_PRINCIPAL'])
                ->orWhere(fn ($q) => $q->where('a.account_type', 'USER_SECURITY_DEPOSIT')->where('a.asset_code', 'USDT')))
            ->selectRaw('a.id, a.tenant_id, a.user_id, a.asset_code, a.balance AS amount, NULL::timestamptz AS posted_at, a.account_type AS source');
        $scope($accounts, 'a.tenant_id', 'a.user_id');

        return $accounts->unionAll($cards);
    }
}
