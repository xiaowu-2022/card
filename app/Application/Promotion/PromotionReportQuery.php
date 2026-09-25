<?php

namespace App\Application\Promotion;

use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Models\User;
use App\Domain\User\Services\ContactMasker;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Read-only projections; income is deduplicated by its immutable award identity. */
final class PromotionReportQuery
{
    public function __construct(private readonly ContactMasker $masker) {}

    public function cumulative(string $tenant, string $user): string
    {
        return Money::of((string) $this->income($tenant, $user)->sum('amount'), 'USDT')->amount();
    }

    public function income(string $tenant, ?string $user): Builder
    {
        $paid = DB::table('paid_promotion_shares as s')
            ->join('paid_promotion_events as e', fn ($j) => $j->on('e.id', '=', 's.event_id')->on('e.tenant_id', '=', 's.tenant_id'))
            ->join('ledger_entries as l', fn ($j) => $j->on('l.id', '=', 's.ledger_entry_id')->on('l.tenant_id', '=', 's.tenant_id'))
            ->join('users as u', fn ($j) => $j->on('u.id', '=', 'e.user_id')->on('u.tenant_id', '=', 'e.tenant_id'))
            ->where('s.tenant_id', $tenant)->when($user !== null, fn ($q) => $q->where('s.user_id', $user))->where('s.amount', '>', 0)
            ->selectRaw('s.id, LOWER(e.kind) AS kind, s.amount, e.user_id AS source_user_id, u.account_id AS source_account_id,
                e.source_rank, s.rank AS beneficiary_rank, s.depth, e.amount AS source_amount, s.rate, s.standard, s.covered,
                e.source_id, l.posted_at AS occurred_at, e.occurred_at AS business_at');
        $legacy = DB::table('commission_awards as a')
            ->join('promotion_funding_events as f', fn ($j) => $j->on('f.id', '=', 'a.funding_event_id')->on('f.tenant_id', '=', 'a.tenant_id'))
            ->join('ledger_entries as l', fn ($j) => $j->on('l.id', '=', 'a.ledger_entry_id')->on('l.tenant_id', '=', 'a.tenant_id'))
            ->join('users as u', fn ($j) => $j->on('u.id', '=', 'f.user_id')->on('u.tenant_id', '=', 'f.tenant_id'))
            ->where('a.tenant_id', $tenant)->when($user !== null, fn ($q) => $q->where('a.user_id', $user))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('paid_promotion_shares as s')->whereColumn('s.id', 'a.id')->whereColumn('s.tenant_id', 'a.tenant_id'))
            ->selectRaw("a.id, 'legacy' AS kind, a.amount, f.user_id AS source_user_id, u.account_id AS source_account_id,
                NULL::integer AS source_rank, NULL::integer AS beneficiary_rank, NULL::integer AS depth,
                f.amount AS source_amount, NULL::numeric AS rate, NULL::integer AS standard, NULL::integer AS covered,
                f.id AS source_id, l.posted_at AS occurred_at, f.funded_at AS business_at");

        return DB::query()->fromSub($paid->unionAll($legacy), 'income');
    }

    private function context(string $tenant, string $user, array $filters, bool $daily = false): array
    {
        $company = Tenant::query()->whereKey($tenant)->firstOrFail();
        User::query()->where('tenant_id', $tenant)->whereKey($user)->firstOrFail();
        $today = CarbonImmutable::now($company->timezone)->startOfDay();
        $from = $filters['date_from'] ?? $filters['date'] ?? ($daily ? $today->format('Y-m-d') : null);
        $to = $filters['date_to'] ?? $filters['date'] ?? ($daily ? $today->format('Y-m-d') : null);

        return ['ranks' => PromotionRanks::forTenant($tenant), 'dateFrom' => $from, 'dateTo' => $to, 'timezone' => $company->timezone,
            'presets' => [1 => $today->format('Y-m-d'), 7 => $today->subDays(6)->format('Y-m-d'), 30 => $today->subDays(29)->format('Y-m-d')],
            'today' => $today->format('Y-m-d')];
    }

    private function period(Builder $query, array $context, string $column = 'occurred_at'): Builder
    {
        if ($context['dateFrom'] !== null) {
            $query->where($column, '>=', CarbonImmutable::createFromFormat('!Y-m-d', $context['dateFrom'], $context['timezone'])->utc())
                ->where($column, '<', CarbonImmutable::createFromFormat('!Y-m-d', $context['dateTo'], $context['timezone'])->addDay()->utc());
        }

        return $query;
    }

    private function totals(Builder $query): array
    {
        $row = (clone $query)->selectRaw("COALESCE(SUM(amount),0)::text AS total,
            COALESCE(SUM(amount) FILTER (WHERE kind='annual'),0)::text AS annual,
            COALESCE(SUM(amount) FILTER (WHERE kind='activation'),0)::text AS activation,
            COALESCE(SUM(amount) FILTER (WHERE kind='legacy'),0)::text AS legacy")->first();

        return (array) $row;
    }

    /** Resolve the beneficiary independently of the authenticated viewer. Never trust a user ID from the client. */
    private function viewingContext(string $tenant, string $viewer, ?string $subject): array
    {
        User::query()->where('tenant_id', $tenant)->whereKey($viewer)->firstOrFail();
        $beneficiary = $viewer;
        $breadcrumbs = [];
        if ($subject !== null) {
            abort_unless(Str::isUuid($subject), 404);
            $target = $this->team($tenant, $viewer)->where('id', $subject)->first();
            abort_if($target === null, 404);
            $beneficiary = $target->user_id;
            $breadcrumbs = DB::select('WITH RECURSIVE ancestors AS (
                SELECT id,inviter_id,user_id,0 AS distance,ARRAY[id] AS visited FROM promotion_members WHERE tenant_id=? AND id=?
                UNION ALL SELECT m.id,m.inviter_id,m.user_id,a.distance+1,a.visited || m.id
                FROM promotion_members m JOIN ancestors a ON a.inviter_id=m.id
                WHERE m.tenant_id=? AND a.user_id<>? AND NOT m.id=ANY(a.visited)
            ) SELECT a.id,u.account_id AS "accountId",p.display_name AS "displayName"
              FROM ancestors a JOIN users u ON u.id=a.user_id AND u.tenant_id=?
              LEFT JOIN user_profiles p ON p.user_id=u.id AND p.tenant_id=u.tenant_id
              WHERE a.user_id<>? ORDER BY a.distance DESC', [$tenant, $subject, $tenant, $viewer, $tenant, $viewer]);
        }
        $identity = DB::table('users as u')
            ->leftJoin('user_profiles as p', fn ($j) => $j->on('p.user_id', '=', 'u.id')->on('p.tenant_id', '=', 'u.tenant_id'))
            ->where('u.tenant_id', $tenant)->where('u.id', $beneficiary)
            ->select('u.account_id', 'p.display_name')->first();

        return ['user' => $beneficiary, 'subject' => ['id' => $subject, 'accountId' => $identity->account_id,
            'displayName' => $identity->display_name], 'breadcrumbs' => $breadcrumbs];
    }

    private function descendant(string $tenant, string $subjectUser, string $member): object
    {
        abort_unless(Str::isUuid($member), 404);
        $target = $this->team($tenant, $subjectUser)->where('id', $member)->first();
        abort_if($target === null, 404);

        return $target;
    }

    public function commissions(string $tenant, string $user, array $filters): array
    {
        $view = $this->viewingContext($tenant, $user, $filters['subject'] ?? null);
        $user = $view['user'];
        $context = $this->context($tenant, $user, $filters);
        $page = (int) ($filters['page'] ?? 1);
        $query = $this->period($this->income($tenant, $user), $context);
        if (! empty($filters['source_member'])) {
            $source = $this->descendant($tenant, $user, $filters['source_member']);
            $query->where('source_user_id', $source->user_id);
        }
        if (($filters['kind'] ?? 'all') !== 'all') {
            $query->where('kind', $filters['kind']);
        }
        if (! empty($filters['account_id'])) {
            $query->where('source_account_id', 'like', '%'.$filters['account_id'].'%');
        }
        if (($filters['rank'] ?? 'all') === 'unknown') {
            $query->whereNull('source_rank');
        } elseif (($filters['rank'] ?? 'all') !== 'all') {
            $query->where('source_rank', (int) $filters['rank']);
        }
        match ($filters['relation'] ?? 'all') {
            'direct' => $query->where('depth', 1), 'indirect' => $query->where('depth', '>', 1),
            'unknown' => $query->whereNull('depth'), default => null,
        };
        $totals = $this->totals($query);
        $rows = $query->orderByDesc('occurred_at')->orderBy('id')->offset(($page - 1) * 30)->limit(31)->get();
        $items = $rows->take(30)->map(fn ($r) => [
            'id' => $r->id, 'kind' => $r->kind, 'amount' => $r->amount, 'sourceAccountId' => $r->source_account_id,
            'sourceRank' => $r->source_rank, 'beneficiaryRank' => $r->beneficiary_rank,
            'relation' => $r->depth === null ? 'unknown' : ($r->depth === 1 ? 'direct' : 'indirect'),
            'sourceAmount' => $r->source_amount, 'rate' => $r->rate, 'standard' => $r->standard, 'covered' => $r->covered,
            'occurredAt' => $r->occurred_at, 'businessAt' => $r->business_at,
        ])->all();

        return $context + ['subject' => $view['subject'], 'breadcrumbs' => $view['breadcrumbs'], 'filters' => $filters, 'totals' => $totals, 'items' => $items, 'page' => $page, 'hasMore' => $rows->count() > 30];
    }

    private function team(string $tenant, string $user): Builder
    {
        return DB::query()->fromRaw('(WITH RECURSIVE descendants AS (
            SELECT m.id,m.user_id,m.created_at,1 AS depth FROM promotion_members p
            JOIN promotion_members m ON m.inviter_id=p.id AND m.tenant_id=p.tenant_id
            WHERE p.tenant_id=? AND p.user_id=?
            UNION ALL SELECT m.id,m.user_id,m.created_at,d.depth+1 FROM promotion_members m
            JOIN descendants d ON m.inviter_id=d.id WHERE m.tenant_id=?
        ) SELECT * FROM descendants) AS team', [$tenant, $user, $tenant]);
    }

    public function daily(string $tenant, string $user, array $filters): array
    {
        $context = $this->context($tenant, $user, $filters, true);
        $team = $this->team($tenant, $user);
        $invites = DB::query()->fromSub($team, 't')->join('users as u', 'u.id', '=', 't.user_id')->where('u.tenant_id', $tenant)
            ->selectRaw("t.id,'invitation' AS kind,u.account_id,t.depth,NULL::integer AS source_rank,NULL::numeric AS source_amount,
                0::numeric AS commission,NULL::timestamptz AS posted_at,t.created_at AS occurred_at,false AS first_funding,NULL::text AS purchase_kind");
        $funds = DB::table('promotion_funding_events as f')->joinSub(clone $team, 't', 't.user_id', '=', 'f.user_id')
            ->join('users as u', fn ($j) => $j->on('u.id', '=', 'f.user_id')->on('u.tenant_id', '=', 'f.tenant_id'))
            ->leftJoin('paid_promotion_events as e', fn ($j) => $j->on('e.source_id', '=', 'f.id')->on('e.tenant_id', '=', 'f.tenant_id')->where('e.kind', 'ACTIVATION'))
            ->leftJoinSub($this->income($tenant, $user)->whereIn('kind', ['activation', 'legacy']), 'i', 'i.source_id', '=', 'f.id')
            ->where('f.tenant_id', $tenant)
            ->selectRaw("f.id,'activation' AS kind,u.account_id,t.depth,e.source_rank,f.amount AS source_amount,
                COALESCE(i.amount,0) AS commission,i.occurred_at AS posted_at,f.funded_at AS occurred_at,
                EXISTS(SELECT 1 FROM account_activations aa WHERE aa.tenant_id=f.tenant_id AND aa.user_id=f.user_id AND aa.source_type='DEPOSIT' AND aa.source_id=f.funding_entry_id) AS first_funding,NULL::text AS purchase_kind");
        $annual = DB::table('paid_promotion_orders as o')->joinSub(clone $team, 't', 't.user_id', '=', 'o.user_id')
            ->join('users as u', fn ($j) => $j->on('u.id', '=', 'o.user_id')->on('u.tenant_id', '=', 'o.tenant_id'))
            ->leftJoinSub($this->income($tenant, $user)->where('kind', 'annual'), 'i', 'i.source_id', '=', 'o.id')
            ->where('o.tenant_id', $tenant)->where('o.status', 'COMPLETED')
            ->selectRaw("o.id,'annual' AS kind,u.account_id,t.depth,o.rank AS source_rank,o.amount AS source_amount,
                COALESCE(i.amount,0) AS commission,i.occurred_at AS posted_at,o.completed_at AS occurred_at,EXISTS(SELECT 1 FROM account_activations aa WHERE aa.tenant_id=o.tenant_id AND aa.user_id=o.user_id AND aa.source_type='ANNUAL' AND aa.source_id=o.id) AS first_funding,
                CASE WHEN o.previous_tariff>0 THEN 'upgrade' WHEN EXISTS(SELECT 1 FROM paid_promotion_cycles prior
                  WHERE prior.tenant_id=o.tenant_id AND prior.user_id=o.user_id AND prior.id<>o.cycle_id AND prior.ends_at<=o.completed_at)
                  THEN 'renewal' ELSE 'purchase' END AS purchase_kind");
        $movements = $this->period(DB::query()->fromSub($invites->unionAll($funds)->unionAll($annual), 'movements'), $context);
        $counts = (clone $movements)->selectRaw("COUNT(*) FILTER (WHERE kind='invitation') AS invited,
            COUNT(*) FILTER (WHERE kind='activation') AS funded,COUNT(*) FILTER (WHERE kind='annual') AS orders")->first();
        $totals = $this->totals($this->period($this->income($tenant, $user), $context));
        if (($filters['activity'] ?? 'all') !== 'all') {
            $movements->where('kind', $filters['activity']);
        }
        $page = (int) ($filters['page'] ?? 1);
        $rows = $movements->orderByDesc('occurred_at')->orderBy('id')->offset(($page - 1) * 30)->limit(31)->get();

        return $context + ['filters' => $filters, 'totals' => $totals, 'counts' => (array) $counts, 'page' => $page, 'hasMore' => $rows->count() > 30,
            'items' => $rows->take(30)->map(fn ($r) => ['id' => $r->kind.':'.$r->id, 'kind' => $r->kind, 'sourceAccountId' => $r->account_id,
                'relation' => $r->depth === 1 ? 'direct' : 'indirect', 'sourceRank' => $r->source_rank, 'sourceAmount' => $r->source_amount,
                'amount' => $r->commission, 'occurredAt' => $r->occurred_at, 'postedAt' => $r->posted_at,
                'firstFunding' => (bool) $r->first_funding, 'purchaseKind' => $r->purchase_kind])->all()];
    }

    public function members(string $tenant, string $user, array $filters): array
    {
        $view = $this->viewingContext($tenant, $user, $filters['subject'] ?? null);
        $user = $view['user'];
        unset($filters['scope']);
        $search = trim((string) ($filters['account_id'] ?? ''));
        $team = $this->team($tenant, $user);
        $counts = DB::query()->fromSub(clone $team, 't')
            ->selectRaw('COUNT(*) FILTER (WHERE depth=1) AS direct,COUNT(*) AS total')->first();
        $context = $this->context($tenant, $user, []);
        $policy = DB::table('tenants as tenant')->join('tenant_business_settings as settings', 'settings.tenant_id', '=', 'tenant.id')
            ->where('tenant.id', $tenant)->select('tenant.default_asset', 'settings.required_security_deposit_asset', 'settings.required_security_deposit_amount')->first();
        $depositSupported = $policy !== null && $policy->default_asset === 'USDT' && $policy->required_security_deposit_asset === 'USDT';
        $depositRequired = Money::of($policy?->required_security_deposit_amount ?? '0', 'USDT');
        $at = CarbonImmutable::now();
        $cycles = DB::table('paid_promotion_cycles')->where('tenant_id', $tenant)->where('starts_at', '<=', $at)->where('ends_at', '>', $at)
            ->selectRaw('DISTINCT ON (user_id) user_id,rank,ends_at')->orderBy('user_id')->orderByDesc('starts_at');
        $income = $this->income($tenant, $user)->selectRaw("source_user_id,SUM(amount)::text AS total,
            COALESCE(SUM(amount) FILTER (WHERE kind='annual'),0)::text AS annual,
            COALESCE(SUM(amount) FILTER (WHERE kind='activation'),0)::text AS activation,
            COALESCE(SUM(amount) FILTER (WHERE kind='legacy'),0)::text AS legacy")->groupBy('source_user_id');
        $query = DB::table('promotion_members as m')->joinSub($team, 't', 't.id', '=', 'm.id')
            ->join('users as u', fn ($j) => $j->on('u.id', '=', 'm.user_id')->on('u.tenant_id', '=', 'm.tenant_id'))
            ->leftJoin('user_profiles as profile', fn ($j) => $j->on('profile.user_id', '=', 'u.id')->on('profile.tenant_id', '=', 'u.tenant_id'))
            ->leftJoinSub($cycles, 'c', 'c.user_id', '=', 'm.user_id')
            ->leftJoinSub($income, 'i', 'i.source_user_id', '=', 'm.user_id')
            ->leftJoin('ledger_accounts as d', fn ($j) => $j->on('d.user_id', '=', 'm.user_id')->on('d.tenant_id', '=', 'm.tenant_id')->where('d.account_type', 'USER_SECURITY_DEPOSIT')->where('d.asset_code', 'USDT'))
            ->where('m.tenant_id', $tenant);
        if ($search === '') {
            $query->where('t.depth', 1);
        }
        if ($search !== '') {
            $query->where('u.account_id', 'like', '%'.$search.'%');
        }
        if (($filters['rank'] ?? 'all') !== 'all') {
            $query->whereRaw('COALESCE(c.rank,0)=?', [(int) $filters['rank']]);
        }
        match ($filters['funding'] ?? 'all') {
            'funded' => $query->where('d.balance', '>', 0),
            'unfunded' => $query->whereRaw('COALESCE(d.balance,0)=0'), default => null,
        };
        $total = (clone $query)->count();
        $page = (int) ($filters['page'] ?? $filters['direct_page'] ?? 1);
        // Sort the complete scoped result before pagination; income totals are text DTOs,
        // so explicitly restore numeric ordering rather than lexicographic ordering.
        match ($filters['sort'] ?? 'registered_desc') {
            'commission_desc' => $query->orderByRaw('COALESCE(i.total::numeric,0) DESC')->orderByDesc('u.created_at'),
            'commission_asc' => $query->orderByRaw('COALESCE(i.total::numeric,0) ASC')->orderByDesc('u.created_at'),
            'registered_asc' => $query->orderBy('u.created_at'),
            default => $query->orderByDesc('u.created_at'),
        };
        $rows = $query->selectRaw("m.id,t.depth,u.account_id,u.email,profile.display_name,COALESCE(c.rank,0) AS rank,c.ends_at,m.created_at,
            COALESCE(d.balance,0)::text AS deposit_amount,COALESCE(i.total,'0') AS total,COALESCE(i.annual,'0') AS annual,
            COALESCE(i.activation,'0') AS activation,COALESCE(i.legacy,'0') AS legacy")
            ->orderBy('m.id')->offset(($page - 1) * 20)->limit(21)->get();

        // One recursive aggregate for the visible page, independent of its search/rank filters.
        $roots = $rows->take(20)->pluck('id')->all();
        $teamCounts = collect();
        if ($roots !== []) {
            $placeholders = implode(',', array_fill(0, count($roots), '?'));
            $teamCounts = collect(DB::select("WITH RECURSIVE member_teams AS (
                SELECT inviter_id AS root_id,id FROM promotion_members
                WHERE tenant_id=? AND inviter_id IN ($placeholders)
                UNION SELECT t.root_id,m.id FROM promotion_members m
                JOIN member_teams t ON m.inviter_id=t.id
                WHERE m.tenant_id=? AND m.id<>t.root_id
            ) SELECT root_id,COUNT(*) AS total FROM member_teams GROUP BY root_id", [$tenant, ...$roots, $tenant]))->keyBy('root_id');
        }

        return $context + ['subject' => $view['subject'], 'breadcrumbs' => $view['breadcrumbs'],
            'memberCounts' => ['direct' => (int) $counts->direct, 'total' => (int) $counts->total],
            'subjectTotals' => $this->totals($this->income($tenant, $user)), 'filters' => $filters, 'total' => $total, 'page' => $page, 'hasMore' => $rows->count() > 20,
            'items' => $rows->take(20)->map(fn ($r) => ['id' => $r->id, 'accountId' => $r->account_id, 'rank' => $r->rank, 'endsAt' => $r->ends_at,
                'relation' => $r->depth === 1 ? 'direct' : 'indirect', 'displayName' => $r->display_name, 'maskedEmail' => $r->email ? $this->masker->mask(RegistrationChannel::Email, $r->email) : null,
                'joinedAt' => $r->created_at, 'depositAmount' => $r->deposit_amount,
                'teamSize' => (int) ($teamCounts->get($r->id)?->total ?? 0),
                'membershipStatus' => $r->ends_at !== null ? 'agent' : (AccountActivationStatus::depositSatisfied($depositSupported, Money::of($r->deposit_amount, 'USDT'), $depositRequired) ? 'ordinary' : 'inactive'),
                'totals' => ['total' => $r->total, 'annual' => $r->annual, 'activation' => $r->activation, 'legacy' => $r->legacy]])->all()];
    }

    /** A member's descendants, with commissions belonging to the authorized viewing subject. */
    public function memberTeam(string $tenant, string $viewer, string $member, ?string $subject = null): array
    {
        $view = $this->viewingContext($tenant, $viewer, $subject);
        $viewer = $view['user'];
        $target = $this->descendant($tenant, $viewer, $member);
        $team = $this->team($tenant, $target->user_id);
        $at = CarbonImmutable::now();
        $cycles = DB::table('paid_promotion_cycles')->where('tenant_id', $tenant)->where('starts_at', '<=', $at)->where('ends_at', '>', $at)
            ->selectRaw('DISTINCT ON (user_id) user_id,rank')->orderBy('user_id')->orderByDesc('starts_at');
        $people = DB::query()->fromSub(clone $team, 't')->leftJoinSub($cycles, 'c', 'c.user_id', '=', 't.user_id')
            ->selectRaw('COALESCE(c.rank,0) AS rank,COUNT(*) FILTER (WHERE t.depth=1) AS direct,COUNT(*) FILTER (WHERE t.depth>1) AS indirect')
            ->groupByRaw('COALESCE(c.rank,0)')->get()->keyBy('rank');
        $income = $this->income($tenant, $viewer)->joinSub(clone $team, 't', 't.user_id', '=', 'income.source_user_id')
            ->whereIn('income.kind', ['annual', 'activation'])
            ->selectRaw("income.source_rank AS rank,COALESCE(SUM(income.amount) FILTER (WHERE income.kind='annual'),0)::text AS annual,
                COALESCE(SUM(income.amount) FILTER (WHERE income.kind='activation'),0)::text AS activation")
            ->groupBy('income.source_rank')->get()->keyBy('rank');
        $rows = collect(PromotionRanks::forTenant($tenant))->map(fn ($rank) => [
            'rank' => $rank, 'direct' => (int) ($people->get($rank)?->direct ?? 0), 'indirect' => (int) ($people->get($rank)?->indirect ?? 0),
            'annual' => Money::of($income->get($rank)?->annual ?? '0', 'USDT')->amount(),
            'activation' => Money::of($income->get($rank)?->activation ?? '0', 'USDT')->amount(),
        ])->all();

        return ['totalMembers' => array_sum(array_column($rows, 'direct')) + array_sum(array_column($rows, 'indirect')), 'rows' => $rows];
    }
}
