<?php

namespace App\Http\Controllers\Platform\CompanyConfiguration;

use App\Application\Notification\AssignCompanyNotificationProfileAction;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignCompanyNotificationProfileRequest;
use Illuminate\Http\RedirectResponse;

final class TenantSmsSettingsController extends Controller
{
    public function update(Tenant $tenant, AssignCompanyNotificationProfileRequest $request, AssignCompanyNotificationProfileAction $action): RedirectResponse
    {
        $action->execute($tenant->id, 'sms', $request->validated('profile_id'), $request->user('platform_admin'), $request->attributes->get('request_id'));

        return back()->with('success', 'Notification profile selected.');
    }
}
