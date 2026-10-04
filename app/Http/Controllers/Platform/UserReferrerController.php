<?php

namespace App\Http\Controllers\Platform;

use App\Application\Promotion\ChangeReferrer;
use App\Domain\Promotion\Models\PromotionMember;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class UserReferrerController extends Controller
{
    public function show(Request $request, string $tenant, string $user, ChangeReferrer $action)
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $account = User::where('tenant_id', $tenant)->findOrFail($user);
        $member = PromotionMember::where('tenant_id', $tenant)->where('user_id', $user)->firstOrFail();
        $descendants = $action->descendants($tenant, $member->id);
        $identity = fn ($id) => DB::table('promotion_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.tenant_id', $tenant)->where('u.tenant_id', $tenant)->where('m.id', $id)->first(['m.id', 'u.account_id', 'u.email']);
        $candidates = [];
        if (trim($data['search'] ?? '') !== '') {
            $pattern = '%'.addcslashes(trim($data['search']), '%_').'%';
            $candidates = DB::table('promotion_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.tenant_id', $tenant)->where('u.tenant_id', $tenant)->where('u.status', 'ACTIVE')
                ->whereNotIn('m.id', array_filter([$member->id, $member->inviter_id, ...$descendants]))
                ->where(fn ($q) => $q->where('u.account_id', 'like', $pattern)->orWhere('u.email', 'ilike', $pattern))->orderBy('u.account_id')->limit(20)->get(['m.id', 'u.account_id', 'u.email']);
        }

        return Inertia::render('platform/UserReferrer', [
            'account' => ['id' => $user, 'companyId' => $tenant, 'companyName' => Tenant::findOrFail($tenant)->name, 'accountId' => $account->account_id],
            'current' => $identity($member->inviter_id), 'revision' => (int) $member->referrer_revision, 'descendants' => count($descendants),
            'search' => $data['search'] ?? '', 'candidates' => $candidates,
            'history' => DB::table('referrer_changes as r')->leftJoin('promotion_members as old', 'old.id', '=', 'r.old_inviter_id')->leftJoin('users as ou', 'ou.id', '=', 'old.user_id')
                ->join('promotion_members as new', 'new.id', '=', 'r.new_inviter_id')->join('users as nu', 'nu.id', '=', 'new.user_id')
                ->where('r.tenant_id', $tenant)->where('r.user_id', $user)->orderByDesc('r.revision')->paginate(20, ['r.id', 'r.reason', 'r.actor_name', 'r.created_at', 'r.descendants', 'ou.account_id as old_account', 'nu.account_id as new_account'])->withQueryString(),
        ]);
    }

    public function update(Request $request, string $tenant, string $user, ChangeReferrer $action)
    {
        $data = $request->validate(['new_inviter_id' => ['required', 'uuid'], 'old_inviter_id' => ['nullable', 'uuid'], 'revision' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:500'], 'request_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted']]);
        $action->execute($tenant,$user,$request->user('platform_admin'),$data);

        return back()->with('success','Referrer updated.');
    }
}
