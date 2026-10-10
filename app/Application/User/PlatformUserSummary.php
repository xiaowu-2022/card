<?php

namespace App\Application\User;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Services\AuthorizationService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Batch enrich only the identities already present on a Platform list page. */
final class PlatformUserSummary
{
    public static function rows(string $tenant, array $rows): array
    {
        $scoped = array_map(fn ($row) => [...(array) $row, 'companyId' => $tenant], $rows);

        return self::page(new LengthAwarePaginator($scoped, count($scoped), max(1, count($scoped))))->items();
    }

    public static function page($page)
    {
        $actor = request()->user('platform_admin');
        if (! $actor || ! app(AuthorizationService::class)->allows($actor, ScopeType::Platform, null, 'users.read')) {
            return $page;
        }
        $rows = collect($page->items())->map(fn ($row) => (array) $row);
        if ($rows->isEmpty()) {
            return $page;
        }
        $company = fn ($r) => $r['companyId'] ?? $r['tenantId'] ?? $r['tenant_id'];
        $account = fn ($r) => $r['accountId'] ?? $r['account_id'] ?? $r['user']['displayName'] ?? null;
        $users = DB::table('users as u')->join('tenants as t', 't.id', '=', 'u.tenant_id')
            ->leftJoin('user_profiles as p', fn ($j) => $j->on('p.user_id', '=', 'u.id')->on('p.tenant_id', '=', 'u.tenant_id'))
            ->leftJoin('support_user_agents as sa', fn ($j) => $j->on('sa.user_id', '=', 'u.id')->on('sa.tenant_id', '=', 'u.tenant_id'))
            ->where(function ($q) use ($rows, $company, $account) {
                foreach ($rows as $row) {
                    $q->orWhere(fn ($q) => $q->where('u.tenant_id', $company($row))
                        ->where(isset($row['userId']) ? 'u.id' : 'u.account_id', $row['userId'] ?? $account($row)));
                }
            })->get(['u.id', 'u.tenant_id', 'u.account_id', 'u.email', 'u.support_remark', 'p.display_name', 't.name', 'sa.enabled'])
            ->map(fn ($u) => ['id' => $u->id, 'companyId' => $u->tenant_id, 'companyName' => $u->name,
                'accountId' => $u->account_id, 'displayName' => $u->display_name, 'email' => $u->email,
                'remark' => $u->support_remark, 'supportAgent' => (bool) $u->enabled]);
        $byId = $users->keyBy(fn ($u) => $u['companyId'].':'.$u['id']);
        $byAccount = $users->keyBy(fn ($u) => $u['companyId'].':'.$u['accountId']);

        return $page->through(function ($row) use ($company, $account, $byId, $byAccount) {
            $row = (array) $row;
            $row['userInfo'] = isset($row['userId']) ? $byId->get($company($row).':'.$row['userId']) : $byAccount->get($company($row).':'.$account($row));

            return $row;
        });
    }
}
