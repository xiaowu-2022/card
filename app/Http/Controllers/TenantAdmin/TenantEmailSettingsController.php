<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Notification\DTOs\UpdateTenantEmailSettings;
use App\Application\Notification\SendTenantTestEmailAction;
use App\Application\Notification\UpdateTenantEmailSettingsAction;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendTenantTestEmailRequest;
use App\Http\Requests\UpdateTenantEmailSettingsRequest;
use Illuminate\Http\RedirectResponse;

final class TenantEmailSettingsController extends Controller
{
    public function update(UpdateTenantEmailSettingsRequest $request, TenantContext $context, UpdateTenantEmailSettingsAction $action): RedirectResponse
    {
        $action->execute($context->id(), new UpdateTenantEmailSettings($request->boolean('enabled'),
            strtolower($request->string('from_address')->trim()->toString()), $request->string('from_name')->trim()->toString(),
            $request->validated('smtp_token'), $request->integer('daily_recipient_limit')),
            $request->user('tenant_admin'), $request->attributes->get('request_id'));

        return redirect('/admin/settings/email')->with('success', 'Email settings saved.');
    }

    public function test(SendTenantTestEmailRequest $request, TenantContext $context, SendTenantTestEmailAction $action): RedirectResponse
    {
        $status = $action->execute($context->tenant(), $request->validated('request_id'), $request->validated('test_email'), $request->user('tenant_admin'));
        if ($status === 'ACCEPTED') {
            return redirect('/admin/settings/email')->with('success', 'Proton accepted the test email. Check the recipient inbox and spam folder.');
        }

        return back()->withErrors(['form' => $status === 'UNKNOWN'
            ? 'A previous test is unconfirmed. Check the inbox and Proton Sent folder; it will not be resent automatically.'
            : 'Unable to send email. Check the saved SMTP settings or contact support.']);
    }
}
