<?php

namespace App\Http\Controllers\Platform;

use App\Application\Promotion\ChangeInvitationCode;
use App\Domain\Promotion\Models\PromotionMember;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class UserInvitationCodeController extends Controller
{
    public function show(Request $request, string $tenant, string $user)
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $company = Tenant::findOrFail($tenant);
        $account = User::where('tenant_id', $tenant)->findOrFail($user);
        $member = PromotionMember::where('tenant_id', $tenant)->where('user_id', $user)->firstOrFail();

        return Inertia::render('platform/UserInvitationCode', [
            'account' => ['id' => $user, 'companyId' => $tenant, 'companyName' => $company->name, 'accountId' => $account->account_id, 'email' => $account->email],
            'currentCode' => $member->invitation_code, 'revision' => (int) $member->invitation_revision,
            'nextCode' => (int) DB::table('promotion_invitation_counter')->where('id', 1)->value('next_value'),
            'canChange' => $company->status->value === 'ACTIVE' && $account->status->value === 'ACTIVE',
            'history' => DB::table('promotion_invitation_changes')->where('tenant_id', $tenant)->where('user_id', $user)
                ->orderByDesc('revision')->paginate(20, ['id', 'old_code', 'new_code', 'reason', 'actor_name', 'created_at'])->withQueryString(),
        ]);
    }

    public function update(Request $request, string $tenant, string $user, ChangeInvitationCode $action)
    {
        $action->execute($tenant, $user, $request->user('platform_admin'), $request->only(['new_code', 'old_code', 'revision', 'reason', 'request_id', 'confirmed']));

        return back()->with('success', 'Invitation code updated.');
    }
}
