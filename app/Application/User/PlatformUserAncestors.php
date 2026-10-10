<?php

namespace App\Application\User;

use App\Application\Promotion\ManualPromotion;
use App\Application\Promotion\OrdinaryMemberQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PlatformUserAncestors
{
    public function read(string $tenant, string $user): array
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE ancestors AS (
                SELECT id, tenant_id, user_id, inviter_id, 0 AS distance, ARRAY[id] AS visited
                FROM promotion_members WHERE tenant_id = ? AND user_id = ?
                UNION ALL
                SELECT m.id, m.tenant_id, m.user_id, m.inviter_id, a.distance + 1, a.visited || m.id
                FROM promotion_members m JOIN ancestors a ON m.id = a.inviter_id AND m.tenant_id = a.tenant_id
                WHERE NOT m.id = ANY(a.visited)
            )
            SELECT u.id, u.account_id, u.email, p.display_name, a.distance
            FROM ancestors a
            JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
            LEFT JOIN user_profiles p ON p.user_id = u.id AND p.tenant_id = u.tenant_id
            WHERE a.distance > 0 ORDER BY a.distance
            SQL, [$tenant, $user]);
        if ($rows === []) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $at = CarbonImmutable::now();
        $ranks = app(ManualPromotion::class)->query($tenant, $at)->whereIn('effective_user.id', $ids)->get()->keyBy('user_id');
        $ordinary = app(OrdinaryMemberQuery::class)->users($tenant, $at)->whereIn('funded.user_id', $ids)->pluck('funded.user_id')->flip();

        return array_map(fn ($row) => [
            'id' => $row->id, 'accountId' => $row->account_id, 'displayName' => $row->display_name,
            'email' => $row->email, 'distance' => (int) $row->distance,
            'promotionRank' => (int) ($ranks[$row->id]->rank ?? 0),
            'ordinaryMember' => $ordinary->has($row->id),
        ], $rows);
    }
}
