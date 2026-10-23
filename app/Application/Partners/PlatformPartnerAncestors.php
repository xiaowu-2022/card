<?php

namespace App\Application\Partners;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Services\AuthorizationService;
use Illuminate\Support\Facades\DB;

/** Nearest two enabled partners on the current same-company invitation chain. */
final class PlatformPartnerAncestors
{
    public function forRows(array $rows): array
    {
        $actor = request()->user('platform_admin');
        if (! $rows || ! $actor || ! app(AuthorizationService::class)->allows($actor, ScopeType::Platform, null, 'users.read')) {
            return [];
        }
        $seeds = collect($rows)->unique(fn ($row) => $row['companyId'].':'.$row['userId']);
        $values = implode(',', array_fill(0, $seeds->count(), '(?::uuid, ?::uuid)'));
        $bindings = $seeds->flatMap(fn ($row) => [$row['companyId'], $row['userId']])->all();
        $ancestors = DB::select(<<<SQL
            WITH RECURSIVE seeds(tenant_id, user_id) AS (VALUES {$values}), walk AS (
                SELECT s.tenant_id, s.user_id AS root_user_id, m.id, m.user_id, m.inviter_id,
                    0 AS distance, 0 AS partner_count, ARRAY[m.id] AS visited
                FROM seeds s JOIN promotion_members m ON m.tenant_id=s.tenant_id AND m.user_id=s.user_id
                UNION ALL
                SELECT w.tenant_id, w.root_user_id, m.id, m.user_id, m.inviter_id,
                    w.distance+1, w.partner_count+CASE WHEN p.id IS NULL THEN 0 ELSE 1 END, w.visited || m.id
                FROM walk w JOIN promotion_members m ON m.id=w.inviter_id AND m.tenant_id=w.tenant_id
                JOIN users u ON u.id=m.user_id AND u.tenant_id=m.tenant_id
                LEFT JOIN partner_configurations p ON p.user_id=m.user_id AND p.tenant_id=m.tenant_id AND p.enabled
                WHERE w.partner_count<2 AND NOT m.id=ANY(w.visited)
            )
            SELECT w.tenant_id, w.root_user_id, p.id, u.account_id, profile.display_name
            FROM walk w
            JOIN partner_configurations p ON p.tenant_id=w.tenant_id AND p.user_id=w.user_id AND p.enabled
            JOIN users u ON u.id=w.user_id AND u.tenant_id=w.tenant_id
            LEFT JOIN user_profiles profile ON profile.user_id=u.id AND profile.tenant_id=u.tenant_id
            WHERE w.distance>0
            ORDER BY w.tenant_id, w.root_user_id, w.distance
            SQL, $bindings);
        $result = [];
        foreach ($ancestors as $partner) {
            $result[$partner->tenant_id.':'.$partner->root_user_id][] = [
                'id' => $partner->id,
                'name' => trim($partner->display_name ?? '') ?: $partner->account_id,
                'accountId' => $partner->account_id,
            ];
        }

        return $result;
    }
}
