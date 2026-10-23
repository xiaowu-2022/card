<?php

namespace App\Application\Partners;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PartnerStockTeam
{
    /** Exclusive local business scope, preserving the first ordinary branch identity. */
    public function localMembers(string $tenant, string $user): Builder
    {
        return DB::query()->fromRaw('(WITH RECURSIVE team AS (
            SELECT m.id,m.user_id,m.user_id AS direct_user_id FROM promotion_members m
            JOIN promotion_members owner ON owner.id=m.inviter_id AND owner.tenant_id=m.tenant_id
            WHERE m.tenant_id=? AND owner.user_id=? AND m.user_id<>?
              AND NOT EXISTS(SELECT 1 FROM partner_configurations p WHERE p.tenant_id=? AND p.user_id=m.user_id AND p.enabled)
            UNION SELECT m.id,m.user_id,t.direct_user_id FROM team t JOIN promotion_members m ON m.inviter_id=t.id
            WHERE m.tenant_id=? AND m.user_id<>?
              AND NOT EXISTS(SELECT 1 FROM partner_configurations p WHERE p.tenant_id=? AND p.user_id=m.user_id AND p.enabled)
        ) SELECT DISTINCT ON (user_id) user_id,direct_user_id FROM team ORDER BY user_id,direct_user_id) AS team',
            [$tenant, $user, $user, $tenant, $tenant, $user, $tenant])->select('user_id', 'direct_user_id');
    }

    public function members(string $tenant, string $user, bool $excludePartners = true): Builder
    {
        // Carry the owner's first-level branch through every depth. UNION terminates cycles;
        // excluding the owner prevents looping back into another branch or counting own money.
        return DB::query()->fromRaw('(WITH RECURSIVE team AS (
            SELECT m.id,m.user_id,m.user_id AS direct_user_id FROM promotion_members m
            JOIN promotion_members owner ON owner.id=m.inviter_id AND owner.tenant_id=m.tenant_id
            WHERE m.tenant_id=? AND owner.user_id=? AND m.user_id<>?
            UNION SELECT m.id,m.user_id,p.direct_user_id FROM promotion_members m JOIN team p ON m.inviter_id=p.id
            WHERE m.tenant_id=? AND m.user_id<>?
        ) SELECT DISTINCT ON (user_id) user_id,direct_user_id FROM team ORDER BY user_id,direct_user_id) AS team',
            [$tenant, $user, $user, $tenant, $user])->select('user_id', 'direct_user_id')
            // Filter after traversal: an enabled partner is excluded personally,
            // but their non-partner descendants and original branch remain eligible.
            ->when($excludePartners, fn (Builder $scope) => $scope->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('partner_configurations as partner')
                ->where('partner.tenant_id', $tenant)
                ->whereColumn('partner.user_id', 'team.user_id')
                ->where('partner.enabled', true)));
    }
}
