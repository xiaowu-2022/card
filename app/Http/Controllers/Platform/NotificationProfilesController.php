<?php

namespace App\Http\Controllers\Platform;

use App\Application\Notification\DTOs\UpdateTenantEmailSettings;
use App\Application\Notification\DTOs\UpdateTenantSmsSettings;
use App\Application\Notification\PlatformNotificationProfilesQuery;
use App\Application\Notification\SavePlatformEmailProfileAction;
use App\Application\Notification\SavePlatformSmsProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SavePlatformEmailProfileRequest;
use App\Http\Requests\SavePlatformSmsProfileRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationProfilesController extends Controller
{
    public function sms(PlatformNotificationProfilesQuery $query): Response
    {
        return Inertia::render('platform/NotificationProfiles', ['channel' => 'sms', 'profiles' => $query->execute('sms')]);
    }

    public function email(PlatformNotificationProfilesQuery $query): Response
    {
        return Inertia::render('platform/NotificationProfiles', ['channel' => 'email', 'profiles' => $query->execute('email')]);
    }

    public function saveSms(SavePlatformSmsProfileRequest $request, SavePlatformSmsProfileAction $action, ?string $profile = null): RedirectResponse
    {
        $action->execute($profile, $request->validated('name'), new UpdateTenantSmsSettings(
            $request->boolean('enabled'), $request->validated('access_key_id'), $request->validated('access_key_secret'),
            $request->string('sign_name')->trim()->toString(), $request->string('verification_template_code')->toString(), $request->validated('existing_account_template_code'),
            $request->integer('resend_interval_seconds'), $request->integer('code_ttl_seconds'),
        ), $request->user('platform_admin'), $request->attributes->get('request_id'));

        return redirect('/platform/settings/sms')->with('success', 'SMS settings saved.');
    }

    public function saveEmail(SavePlatformEmailProfileRequest $request, SavePlatformEmailProfileAction $action, ?string $profile = null): RedirectResponse
    {
        $action->execute($profile, $request->validated('name'), new UpdateTenantEmailSettings(
            $request->boolean('enabled'), strtolower($request->string('from_address')->trim()->toString()), $request->string('from_name')->trim()->toString(),
            $request->validated('smtp_token'), $request->integer('daily_recipient_limit'),
        ), $request->user('platform_admin'), $request->attributes->get('request_id'));

        return redirect('/platform/settings/email')->with('success', 'Email settings saved.');
    }
}
