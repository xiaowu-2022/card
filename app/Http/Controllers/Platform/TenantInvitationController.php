<?php

namespace App\Http\Controllers\Platform;

use App\Application\Admin\CancelAdminInvitationAction;
use App\Application\Admin\ResendAdminInvitationAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TenantInvitationController extends Controller
{
    public function resend(Request $request, string $tenant, string $invitation, ResendAdminInvitationAction $resend): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('platform_admin');
        $resend->execute(Tenant::query()->findOrFail($tenant), $invitation, $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Invitation resent. The previous link is no longer valid.');
    }

    public function cancel(Request $request, string $tenant, string $invitation, CancelAdminInvitationAction $cancel): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('platform_admin');
        $cancel->execute(Tenant::query()->findOrFail($tenant), $invitation, $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Pending invitation cancelled.');
    }
}
