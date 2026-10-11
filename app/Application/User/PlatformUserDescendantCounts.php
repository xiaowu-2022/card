<?php

namespace App\Application\User;

use Illuminate\Support\Facades\DB;

final class PlatformUserDescendantCounts
{
    /** Count current descendants for this page in one read, excluding each root itself. */
    public function forUsers(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // UNION deduplicates descendants and terminates even if a historical cycle exists.
        $rows = DB::select(<<<SQL
            WITH RECURSIVE descendants AS (
                SELECT user_id AS root_user_id, tenant_id, id, user_id
                FROM promotion_members WHERE user_id IN ($placeholders)
                UNION
                SELECT d.root_user_id, m.tenant_id, m.id, m.user_id
                FROM promotion_members m JOIN descendants d
                    ON m.inviter_id = d.id AND m.tenant_id = d.tenant_id
            )
            SELECT d.root_user_id, COUNT(*) AS total
            FROM descendants d JOIN users u ON u.id = d.user_id AND u.tenant_id = d.tenant_id
            WHERE d.user_id <> d.root_user_id GROUP BY d.root_user_id
            SQL, $ids);

        return collect($rows)->mapWithKeys(fn ($row) => [$row->root_user_id => (int) $row->total])->all();
    }
}
