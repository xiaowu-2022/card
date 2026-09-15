<?php

namespace App\Http\Controllers\Platform\CompanyConfiguration;

use App\Application\Notification\AssignCompanyNotificationProfileAction;
use App\Application\Notification\SendTenantTestEmailAction;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignCompanyNotificationProfileRequest;
use App\Http\Requests\SendTenantTestEmailRequest;
use Illuminate\Http\RedirectResponse;

final class TenantEmailSettingsController extends Controller
{
    public function update(Tenant $tenant, AssignCompanyNotificationProfileRequest $request, AssignCompanyNotificationProfileAction $action): RedirectResponse
    {
        $action->execute($tenant->id, 'email', $request->validated('profile_id'), $request->user('platform_admin'), $request->attributes->get('request_id'));

        return back()->with('success', 'Notification profile selected.');
    }

    public function test(Tenant $tenant, SendTenantTestEmailRequest $request, SendTenantTestEmailAction $action): RedirectResponse
    {
        $status = $action->execute($tenant, $request->validated('request_id'), $request->validated('test_email'), $request->user('platform_admin'));
        if ($status === 'ACCEPTED') {
            return redirect('/platform/tenants/'.$tenant->id.'/configuration/settings/email')->with('success', 'Proton accepted the test email. Check the recipient inbox and spam folder.');
        }

        return back()->withErrors(['form' => $status === 'UNKNOWN'
            ? 'A previous test is unconfirmed. Check the inbox and Proton Sent folder; it will not be resent automatically.'
            : 'Unable to send email. Check the saved SMTP settings or contact support.']);
    }
}
