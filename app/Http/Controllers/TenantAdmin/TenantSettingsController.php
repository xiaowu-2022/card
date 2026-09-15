<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Notification\TenantEmailSettingsQuery;
use App\Application\Notification\TenantSmsSettingsQuery;
use App\Application\Tenant\TenantSettingsQuery;
use App\Application\Tenant\UpdateTenantBrandingAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Tenant\UpdateTenantKycSettingsAction;
use App\Application\Tenant\UpdateTenantLocalesAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Enums\KycReviewMode;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTenantBrandingRequest;
use App\Http\Requests\UpdateTenantBusinessSettingsRequest;
use App\Http\Requests\UpdateTenantKycSettingsRequest;
use App\Http\Requests\UpdateTenantLocalesRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TenantSettingsController extends Controller
{
    public function show(Request $request, TenantContext $context, TenantSettingsQuery $query, TenantSmsSettingsQuery $sms, TenantEmailSettingsQuery $email): Response
    {
        return Inertia::render('tenant-admin/Settings', [
            'section' => $request->route('section', 'branding'),
            'settings' => [
                ...$query->execute($context->tenant(), $request->route('section') === 'articles'),
                ...($request->route('section') === 'sms' ? ['sms' => $sms->execute($context->id())] : []),
                ...($request->route('section') === 'email' ? ['email' => $email->execute($context->id())] : []),
            ],
        ]);
    }

    public function branding(UpdateTenantBrandingRequest $request, TenantContext $context, UpdateTenantBrandingAction $update): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('tenant_admin');
        $update->execute($context->tenant(), $request->safe()->except(['logo', 'favicon']), $request->file('logo'), $request->file('favicon'), $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Branding updated.');
    }

    public function locales(UpdateTenantLocalesRequest $request, TenantContext $context, UpdateTenantLocalesAction $update): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('tenant_admin');
        $update->execute($context->tenant(), $request->validated('enabled_locales'), $request->validated('default_locale'), $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Locales updated atomically.');
    }

    public function business(UpdateTenantBusinessSettingsRequest $request, TenantContext $context, UpdateTenantBusinessSettingsAction $update): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('tenant_admin');
        $update->execute($context->tenant(), $request->validated(), $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Business configuration updated. No funds were moved.');
    }

    public function kyc(UpdateTenantKycSettingsRequest $request, TenantContext $context, UpdateTenantKycSettingsAction $update): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('tenant_admin');
        $update->execute($context->tenant(), $request->boolean('enabled'), $request->integer('max_accounts_per_identity'), $actor, $request->attributes->get('request_id'), KycReviewMode::from($request->validated('review_mode')));

        return back()->with('success', 'KYC configuration updated.');
    }
}
