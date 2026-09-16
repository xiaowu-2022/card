<?php

namespace App\Application\Promotion;

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Promotion\Models\CommissionAward;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class PaidPromotionQuery
{
    public function __construct(private PaidPromotionRules $rules, private PaidPromotionRebate $rebates) {}

    public function levels(string $tenant): array
    {
        return DB::table('paid_promotion_levels')->where('tenant_id', $tenant)->orderBy('rank')->get()->map(fn ($l) => [
            'id' => $l->id, 'rank' => $l->rank, 'fee' => $l->fee, 'percent' => $l->percent, 'reward' => (string) $l->reward, 'target' => $l->target, 'revision' => $l->revision, 'enabled' => $l->enabled,
        ])->all();
    }

    /** Benefits-only read: no team traversal, income aggregation or member details. */
    public function benefits(string $tenant, string $user): array
    {
        User::query()->where('tenant_id', $tenant)->whereKey($user)->firstOrFail();
        $at = CarbonImmutable::now();
        $cycle = $this->rules->cycle($tenant, $user, $at);
        $previous = $cycle ? null : DB::table('paid_promotion_cycles')->where('tenant_id', $tenant)->where('user_id', $user)->where('ends_at', '<=', $at)->orderByDesc('ends_at')->first();
        $claims = DB::table('paid_promotion_rebates')->where('tenant_id', $tenant)->where('user_id', $user);

        return [
            'levels' => $this->levels($tenant), 'rank' => $cycle?->rank ?? 0,
            'percent' => $cycle?->percent ?? 0, 'reward' => (string) ($cycle?->reward ?? 20),
            'membershipStatus' => $cycle ? 'ACTIVE' : ($previous ? 'EXPIRED' : 'NONE'),
            'previousCycle' => $previous ? ['rank' => $previous->rank, 'endsAt' => $previous->ends_at] : null,
            'cycle' => $cycle ? ['id' => $cycle->id, 'startsAt' => $cycle->starts_at, 'endsAt' => $cycle->ends_at, 'tariff' => $cycle->tariff] : null,
            'pending' => $cycle && (clone $claims)->where('cycle_id', $cycle->id)->where('status', 'PENDING')->exists(),
            'hasClaims' => $claims->exists(),
        ];
    }

    public function execute(string $tenant, string $user, int $claimsPage = 1): array
    {
        User::query()->where('tenant_id', $tenant)->whereKey($user)->firstOrFail();
        $at = CarbonImmutable::now();
        $cycle = $this->rules->cycle($tenant, $user, $at);
        $previous = $cycle ? null : DB::table('paid_promotion_cycles')->where('tenant_id', $tenant)->where('user_id', $user)->where('ends_at', '<=', $at)->orderByDesc('ends_at')->first();
        $available = LedgerAccount::query()->where('tenant_id', $tenant)->where('user_id', $user)->where('asset_code', 'USDT')->where('account_type', 'USER_AVAILABLE')->value('balance');

        $rows = $this->shares($tenant, $user)->selectRaw('e.kind,e.source_rank,CASE WHEN s.depth=1 THEN 1 ELSE 2 END AS relation,COUNT(*) AS count,SUM(s.amount)::text AS amount,MIN(s.rate)::text AS minimum,MAX(s.rate)::text AS maximum')
            ->groupByRaw('e.kind,e.source_rank,CASE WHEN s.depth=1 THEN 1 ELSE 2 END')->get();
        $tables = ['ANNUAL' => [], 'ACTIVATION' => []];
        $totals = ['ANNUAL' => '0', 'ACTIVATION' => '0'];
        foreach (['ANNUAL', 'ACTIVATION'] as $kind) {
            for ($rank = 0; $rank <= 8; $rank++) {
                $row = ['rank' => $rank];
                foreach (['direct' => 1, 'indirect' => 2] as $direction => $relation) {
                    $r = $rows->first(fn ($r) => $r->kind === $kind && $r->source_rank === $rank && $r->relation === $relation);
                    $row[$direction] = ['count' => (int) ($r?->count ?? 0), 'amount' => $r?->amount ?? '0', 'minimum' => $r?->minimum ?? '0', 'maximum' => $r?->maximum ?? '0'];
                    $totals[$kind] = (string) BigDecimal::of($totals[$kind])->plus($row[$direction]['amount']);
                }
                $tables[$kind][] = $row;
            }
        }
        $legacy = CommissionAward::query()->where('tenant_id', $tenant)->where('user_id', $user)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('paid_promotion_shares as s')->whereColumn('s.id', 'commission_awards.id')->whereColumn('s.tenant_id', 'commission_awards.tenant_id'))->sum('amount');
        $team = collect(DB::select(<<<'SQL'
          WITH RECURSIVE team AS (
            SELECT c.id,c.user_id,1 AS depth FROM promotion_members p
              JOIN promotion_members c ON c.inviter_id=p.id AND c.tenant_id=p.tenant_id
              WHERE p.tenant_id=? AND p.user_id=?
            UNION ALL
            SELECT c.id,c.user_id,t.depth+1 FROM promotion_members c
              JOIN team t ON c.inviter_id=t.id WHERE c.tenant_id=?
          )
          SELECT COALESCE(active.rank,0) AS rank,
            COUNT(*) FILTER (WHERE team.depth=1) AS direct,
            COUNT(*) FILTER (WHERE team.depth>1) AS indirect
          FROM team
          LEFT JOIN LATERAL (
            SELECT rank FROM paid_promotion_cycles
            WHERE tenant_id=? AND user_id=team.user_id AND starts_at<=? AND ends_at>?
            ORDER BY starts_at DESC LIMIT 1
          ) active ON true
          GROUP BY COALESCE(active.rank,0)
          SQL, [$tenant, $user, $tenant, $tenant, $at, $at]))->keyBy('rank');
        $teamByLevel = collect(range(0, 8))->map(fn ($rank) => [
            'rank' => $rank, 'direct' => (int) ($team->get($rank)?->direct ?? 0),
            'indirect' => (int) ($team->get($rank)?->indirect ?? 0),
        ])->all();
        $claims = DB::table('paid_promotion_rebates')->where('tenant_id', $tenant)->where('user_id', $user)->orderByDesc('created_at')->orderBy('id')->offset(($claimsPage - 1) * 30)->limit(31)->get();
        $progress = $cycle ? $this->rebates->progress($cycle) : null;

        return ['membershipStatus' => $cycle ? 'ACTIVE' : ($previous ? 'EXPIRED' : 'NONE'),
            'previousCycle' => $previous ? ['rank' => $previous->rank, 'endsAt' => $previous->ends_at] : null,
            'availableBalance' => Money::of($available ?? '0', 'USDT')->amount(),
            'levels' => $this->levels($tenant), 'rank' => $cycle?->rank ?? 0, 'percent' => $cycle?->percent ?? 0, 'reward' => (string) ($cycle?->reward ?? 20),
            'cycle' => $cycle ? ['id' => $cycle->id, 'startsAt' => $cycle->starts_at, 'endsAt' => $cycle->ends_at, 'tariff' => $cycle->tariff] : null,
            'progress' => $progress, 'pending' => $cycle && DB::table('paid_promotion_rebates')->where('tenant_id', $tenant)->where('user_id', $user)->where('cycle_id', $cycle->id)->where('status', 'PENDING')->exists(),
            'claimsPage' => $claimsPage, 'hasMoreClaims' => $claims->count() > 30,
            'claims' => $claims->take(30)->map(fn ($r) => $this->claim($r))->all(), 'tables' => $tables, 'totals' => $totals, 'legacy' => (string) $legacy,
            'teamByLevel' => $teamByLevel, 'directPeople' => array_sum(array_column($teamByLevel, 'direct')), 'indirectPeople' => array_sum(array_column($teamByLevel, 'indirect'))];
    }

    public function details(string $tenant, string $user, string $kind, int $rank, int $page): array
    {
        $rows = $this->shares($tenant, $user)->join('users as u', fn ($j) => $j->on('u.id', '=', 'e.user_id')->on('u.tenant_id', '=', 'e.tenant_id'))
            ->where('e.kind', $kind)->where('e.source_rank', $rank)->orderByDesc('e.occurred_at')->orderBy('s.id')->offset(($page - 1) * 30)->limit(31)
            ->get(['s.id', 's.depth', 's.rate', 's.amount', 'e.amount as source_amount', 'e.occurred_at', 'u.account_id']);

        return ['kind' => $kind, 'rank' => $rank, 'page' => $page, 'hasMore' => $rows->count() > 30, 'items' => $rows->take(30)->map(fn ($r) => [
            'id' => $r->id, 'direct' => $r->depth === 1, 'rate' => $r->rate, 'amount' => $r->amount, 'sourceAmount' => $r->source_amount, 'occurredAt' => $r->occurred_at, 'accountId' => $r->account_id,
        ])->all()];
    }

    public function scopedOrder(string $tenant, string $user, string $id): array
    {
        return $this->order(DB::table('paid_promotion_orders')->where('tenant_id', $tenant)->where('user_id', $user)->where('id', $id)->firstOrFail());
    }

    public function platform(string $tenant, int $page): array
    {
        $rows = DB::table('paid_promotion_rebates as r')->join('users as u', fn ($j) => $j->on('u.id', '=', 'r.user_id')->on('u.tenant_id', '=', 'r.tenant_id'))
            ->leftJoin('admin_users as a', 'a.id', '=', 'r.reviewer_id')->where('r.tenant_id', $tenant)
            ->orderByRaw("CASE WHEN r.status='PENDING' THEN 0 ELSE 1 END")->orderByDesc('r.created_at')->orderBy('r.id')->offset(($page - 1) * 30)->limit(31)->get(['r.*', 'u.account_id', 'a.name as reviewer_name']);

        return ['companyName' => Tenant::query()->whereKey($tenant)->value('name'), 'tenantId' => $tenant, 'levels' => $this->levels($tenant), 'page' => $page, 'hasMore' => $rows->count() > 30,
            'claims' => $rows->take(30)->map(fn ($r) => $this->claim($r) + ['accountId' => $r->account_id, 'reviewer' => $r->reviewer_name])->all()];
    }

    public function order(object $o): array
    {
        return ['id' => $o->id, 'rank' => $o->rank, 'amount' => $o->amount, 'previousTariff' => $o->previous_tariff, 'tariff' => $o->tariff, 'expiresAt' => $o->expires_at, 'status' => $o->status, 'cycleId' => $o->cycle_id];
    }

    public function claim(object $r): array
    {
        return ['id' => $r->id, 'rank' => $r->rank, 'amount' => $r->amount, 'status' => $r->status, 'target' => $r->target, 'direct' => $r->direct_count, 'indirect' => $r->indirect_count, 'createdAt' => $r->created_at, 'reviewedAt' => $r->reviewed_at, 'reason' => $r->reason];
    }

    private function shares(string $tenant, string $user): Builder
    {
        return DB::table('paid_promotion_shares as s')->join('paid_promotion_events as e', fn ($j) => $j->on('e.id', '=', 's.event_id')->on('e.tenant_id', '=', 's.tenant_id'))->where('s.tenant_id', $tenant)->where('s.user_id', $user)
            ->where(fn ($q) => $q->where('e.kind', 'ACTIVATION')->orWhere('s.amount', '>', 0));
    }
}
