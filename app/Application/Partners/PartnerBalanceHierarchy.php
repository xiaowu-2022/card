<?php

namespace App\Application\Partners;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Current enabled partners projected onto the existing company invitation tree. */
final class PartnerBalanceHierarchy
{
    public function read(?string $company, ?string $parent, int $page): array
    {
        $outer = DB::transactionLevel();

        return DB::transaction(function () use ($company, $parent, $page, $outer) {
            if ($outer === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $partners = DB::table('partner_configurations as p')
                ->join('users as u', fn ($j) => $j->on('u.id', 'p.user_id')->on('u.tenant_id', 'p.tenant_id'))
                ->join('tenants as t', 't.id', 'p.tenant_id')
                ->leftJoin('user_profiles as profile', fn ($j) => $j->on('profile.user_id', 'u.id')->on('profile.tenant_id', 'u.tenant_id'))
                ->where('p.enabled', true)->when($company, fn ($q) => $q->where('p.tenant_id', $company))
                ->orderBy('t.name')->orderBy('u.account_id')->orderBy('p.id')
                ->get(['p.id', 'p.tenant_id', 'p.user_id', 'u.account_id', 'profile.display_name', 't.name as company_name']);
            $byId = $partners->keyBy('id');
            abort_if($parent !== null && ! $byId->has($parent), 404);
            $parents = [];
            foreach ($partners->groupBy('tenant_id') as $tenant => $rows) {
                $members = DB::table('promotion_members')->where('tenant_id', $tenant)->get(['id', 'user_id', 'inviter_id'])->keyBy('id');
                $byUser = $members->keyBy('user_id');
                $enabled = $rows->keyBy('user_id');
                foreach ($rows as $row) {
                    $cursor = $byUser->get($row->user_id)?->inviter_id;
                    $seen = [];
                    $parents[$row->id] = null;
                    while ($cursor !== null && ! isset($seen[$cursor])) {
                        $seen[$cursor] = true;
                        $member = $members->get($cursor);
                        if (! $member) {
                            break;
                        }
                        if ($enabled->has($member->user_id)) {
                            $parents[$row->id] = $enabled->get($member->user_id)->id;
                            break;
                        }
                        $cursor = $member->inviter_id;
                    }
                }
            }
            $children = $partners->filter(fn ($p) => $parents[$p->id] === $parent)->values();
            $visible = $children->slice(($page - 1) * 20, 20);
            $balances = [];
            $items = $visible->map(function ($row) use ($partners, $parents, &$balances) {
                $teamIds = [$row->id => true];
                foreach ($partners as $candidate) {
                    $cursor = $candidate->id;
                    $seen = [];
                    while ($cursor !== null && ! isset($seen[$cursor])) {
                        if ($cursor === $row->id) {
                            $teamIds[$candidate->id] = true;
                            break;
                        }
                        $seen[$cursor] = true;
                        $cursor = $parents[$cursor] ?? null;
                    }
                }
                $team = array_fill_keys(['theoretical', 'actual', 'difference'], '0.00000000');
                foreach ($partners as $member) {
                    if (! isset($teamIds[$member->id])) {
                        continue;
                    }
                    $balances[$member->id] ??= app(LegacyStockReport::class)->accountBalance($member->tenant_id, $member->user_id, $member->id);
                    foreach ($team as $key => $amount) {
                        $team[$key] = (string) BigDecimal::of($amount)->plus($balances[$member->id][$key])->toScale(8);
                    }
                }

                return (array) $row + ['personal' => array_intersect_key($balances[$row->id], $team), 'team' => $team,
                    'teamPartners' => count($teamIds), 'hasChildren' => in_array($row->id, $parents, true)];
            })->values()->all();
            $breadcrumbs = [];
            $cursor = $parent;
            $seen = [];
            while ($cursor !== null && ! isset($seen[$cursor])) {
                $seen[$cursor] = true;
                $p = $byId->get($cursor);
                array_unshift($breadcrumbs, ['id' => $p->id, 'name' => trim($p->display_name ?? '') ?: $p->account_id]);
                $cursor = $parents[$cursor];
            }

            return ['items' => $items, 'total' => $children->count(), 'page' => $page,
                'pages' => max(1, (int) ceil($children->count() / 20)), 'breadcrumbs' => $breadcrumbs];
        });
    }
}
