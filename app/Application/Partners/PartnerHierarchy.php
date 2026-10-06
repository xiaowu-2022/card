<?php

namespace App\Application\Partners;

use Illuminate\Support\Facades\DB;

/** Read-only views over current invitation edges, never a second partner tree. */
final class PartnerHierarchy
{
    public function read(?string $tenant, ?string $viewer, ?string $partner, string $view, int $page = 1, ?string $flow = null, int $flowPage = 1): array
    {
        $outer = DB::transactionLevel();

        return DB::transaction(function () use ($tenant, $viewer, $partner, $view, $page, $flow, $flowPage, $outer) {
            if ($outer === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            if ($viewer !== null) {
                abort_unless(app(PartnerReport::class)->enabled($tenant, $viewer), 403);
            }
            $subject = DB::table('partner_configurations as p')
                ->join('users as u', fn ($j) => $j->on('u.id', 'p.user_id')->on('u.tenant_id', 'p.tenant_id'))
                ->leftJoin('user_profiles as profile', fn ($j) => $j->on('profile.user_id', 'u.id')->on('profile.tenant_id', 'u.tenant_id'))
                ->when($tenant !== null, fn ($q) => $q->where('p.tenant_id', $tenant))
                ->when($partner !== null, fn ($q) => $q->where('p.id', $partner), fn ($q) => $q->where('p.user_id', $viewer))
                ->where('p.enabled', true)->first(['p.id', 'p.tenant_id', 'p.user_id', 'u.account_id', 'profile.display_name']);
            abort_unless($subject, 404);
            $tenant = $subject->tenant_id;
            if ($viewer !== null) {
                abort_unless(DB::query()->fromSub(app(LegacyStockReport::class)->team($tenant, $viewer), 'scope')->where('user_id', $subject->user_id)->exists(), 404);
            }
            $identity = ['id' => $subject->id, 'accountId' => $subject->account_id, 'name' => trim($subject->display_name ?? '') ?: $subject->account_id];
            $base = '/promotion/stock/partners/'.$subject->id;
            if ($view === 'report') {
                $report = app(PartnerReport::class)->read($tenant, $subject->user_id, $viewer !== null, $page, $flow, $flowPage);
                if ($viewer === null) {
                    $report = app(PlatformPartnerIdentity::class)->stock($tenant, $report);
                } else {
                    foreach ($report['journal']['items'] as &$row) {
                        if (is_array($row)) {
                            unset($row['actor_id']);
                        } else {
                            unset($row->actor_id);
                        }
                    }
                    unset($row);
                }
                $report['subject'] = $identity;
                $report['reportPath'] = $base.'/report';
                $report['partnersPath'] = $base;

                return ['report' => $report];
            }
            $nearest = <<<'SQL'
                WITH RECURSIVE walk AS (
                    SELECT m.id,m.user_id, p.id AS partner_id FROM promotion_members parent
                    JOIN promotion_members m ON m.inviter_id=parent.id AND m.tenant_id=parent.tenant_id
                    LEFT JOIN partner_configurations p ON p.tenant_id=m.tenant_id AND p.user_id=m.user_id AND p.enabled
                    WHERE parent.tenant_id=? AND parent.user_id=? AND m.user_id<>?
                    UNION
                    SELECT m.id,m.user_id,p.id FROM promotion_members m JOIN walk w ON m.inviter_id=w.id
                    LEFT JOIN partner_configurations p ON p.tenant_id=m.tenant_id AND p.user_id=m.user_id AND p.enabled
                    WHERE m.tenant_id=? AND w.partner_id IS NULL AND m.user_id<>?
                ) SELECT DISTINCT partner_id,user_id FROM walk WHERE partner_id IS NOT NULL
                SQL;
            $query = DB::query()->fromRaw('('.$nearest.') AS children', [$tenant, $subject->user_id, $subject->user_id, $tenant, $subject->user_id])
                ->join('users as u', 'u.id', 'children.user_id')->where('u.tenant_id', $tenant)
                ->leftJoin('user_profiles as profile', fn ($j) => $j->on('profile.user_id', 'u.id')->on('profile.tenant_id', 'u.tenant_id'));
            $total = (clone $query)->count();
            $rows = $query->orderBy('u.account_id')->orderBy('children.partner_id')->offset(($page - 1) * 20)->limit(20)->get(['children.partner_id', 'children.user_id', 'u.account_id', 'profile.display_name']);
            $counts = collect();
            if ($rows->isNotEmpty()) {
                $placeholders = implode(',', array_fill(0, $rows->count(), '?'));
                $counts = collect(DB::select("WITH RECURSIVE teams AS (
                    SELECT m.user_id AS root,m.id,m.user_id FROM promotion_members m WHERE m.tenant_id=? AND m.user_id IN ({$placeholders})
                    UNION SELECT t.root,m.id,m.user_id FROM teams t JOIN promotion_members m ON m.inviter_id=t.id WHERE m.tenant_id=?
                ) SELECT root,COUNT(*) FILTER (WHERE user_id<>root) AS total FROM teams GROUP BY root", [$tenant, ...$rows->pluck('user_id')->all(), $tenant]))->keyBy('root');
            }

            return ['subject' => $identity, 'listPath' => $base, 'items' => $rows->map(fn ($row) => [
                'id' => $row->partner_id, 'name' => trim($row->display_name ?? '') ?: $row->account_id, 'accountId' => $row->account_id,
                'teamCount' => (int) ($counts->get($row->user_id)?->total ?? 0),
            ])->all(), 'page' => $page, 'total' => $total, 'hasMore' => $page * 20 < $total];
        });
    }
}
