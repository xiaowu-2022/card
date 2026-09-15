<?php

namespace App\Http\Controllers\Platform;

use App\Application\Tenant\UpdatePlatformKycSettingsAction;
use App\Domain\Tenant\Enums\KycReviewMode;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTenantKycSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class KycSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('platform/KycSettings', ['policy' => PlatformKycSetting::current()->policy()]);
    }

    public function update(UpdateTenantKycSettingsRequest $request, UpdatePlatformKycSettingsAction $update): RedirectResponse
    {
        $data = $request->validated();
        $update->execute((bool) $data['enabled'], (int) $data['max_accounts_per_identity'], KycReviewMode::from($data['review_mode']), (bool) ($data['automatic_approval_confirmed'] ?? false), $request->user('platform_admin'), $request->attributes->get('request_id'));

        return back()->with('success', 'Global identity verification policy saved.');
    }
}
