<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Notification\DTOs\UpdateTenantSmsSettings;
use App\Application\Notification\UpdateTenantSmsSettingsAction;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTenantSmsSettingsRequest;
use Illuminate\Http\RedirectResponse;

final class TenantSmsSettingsController extends Controller
{
    public function update(UpdateTenantSmsSettingsRequest $request, TenantContext $context, UpdateTenantSmsSettingsAction $action): RedirectResponse
    {
        $action->execute($context->id(), new UpdateTenantSmsSettings(
            $request->boolean('enabled'),
            $request->validated('access_key_id'),
            $request->validated('access_key_secret'),
            $request->string('sign_name')->trim()->toString(),
            $request->string('verification_template_code')->toString(),
            $request->validated('existing_account_template_code'),
            $request->integer('resend_interval_seconds'),
            $request->integer('code_ttl_seconds'),
        ), $request->user('tenant_admin'), $request->attributes->get('request_id'));

        return redirect('/admin/settings/sms')->with('success', 'SMS settings saved.');
    }
}
