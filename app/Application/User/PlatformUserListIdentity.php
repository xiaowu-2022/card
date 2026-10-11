<?php

namespace App\Application\User;

use App\Application\Promotion\ManualPromotion;
use App\Application\Promotion\OrdinaryMemberQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PlatformUserListIdentity
{
    public function forUsers(array $users): array
    {
        $result = [];
        foreach (collect($users)->groupBy('tenant_id') as $tenant => $rows) {
            $ids = $rows->pluck('id')->all();
            $ranks = app(ManualPromotion::class)->query($tenant)->whereIn('effective_user.id', $ids)->pluck('rank', 'user_id');
            $ordinary = app(OrdinaryMemberQuery::class)->users($tenant, CarbonImmutable::now())->whereIn('funded.user_id', $ids)->pluck('funded.user_id')->flip();
            $parents = DB::table('promotion_members as m')->join('promotion_members as p', fn ($j) => $j->on('p.id', '=', 'm.inviter_id')->on('p.tenant_id', '=', 'm.tenant_id'))
                ->join('users as u', fn ($j) => $j->on('u.id', '=', 'p.user_id')->on('u.tenant_id', '=', 'p.tenant_id'))
                ->leftJoin('user_profiles as profile', fn ($j) => $j->on('profile.user_id', '=', 'u.id')->on('profile.tenant_id', '=', 'u.tenant_id'))
                ->where('m.tenant_id', $tenant)->whereIn('m.user_id', $ids)->get(['m.user_id', 'u.account_id', 'profile.display_name'])->keyBy('user_id');
            foreach ($ids as $id) {
                $parent = $parents->get($id);
                $result[$tenant.':'.$id] = ['promotionRank' => (int) ($ranks[$id] ?? 0), 'ordinaryMember' => $ordinary->has($id), 'directReferrer' => $parent ? ['accountId' => $parent->account_id, 'displayName' => $parent->display_name] : null];
            }
        }

        return $result;
    }
}
