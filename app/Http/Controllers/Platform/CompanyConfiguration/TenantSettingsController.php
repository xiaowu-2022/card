<?php

namespace App\Http\Controllers\Platform\CompanyConfiguration;

use App\Application\Notification\TenantEmailSettingsQuery;
use App\Application\Notification\TenantSmsSettingsQuery;
use App\Application\Tenant\TenantSettingsQuery;
use App\Application\Tenant\UpdateTenantBrandingAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Tenant\UpdateTenantKycSettingsAction;
use App\Application\Tenant\UpdateTenantLocalesAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Enums\KycReviewMode;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCompanyBusinessSettingsRequest;
use App\Http\Requests\UpdateTenantBrandingRequest;
use App\Http\Requests\UpdateTenantKycSettingsRequest;
use App\Http\Requests\UpdateTenantLocalesRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TenantSettingsController extends Controller
{
    public function show(Tenant $tenant, Request $request, TenantSettingsQuery $query, TenantSmsSettingsQuery $sms, TenantEmailSettingsQuery $email): Response|RedirectResponse
    {
        if ($request->route('section') === 'kyc') {
            return redirect('/platform/settings/kyc');
        }

        return Inertia::render('tenant-admin/Settings', [
            'section' => $request->route('section', 'branding'),
            'settings' => [
                ...$query->execute($tenant, $request->route('section') === 'articles'),
                ...($request->route('section') === 'sms' ? ['sms' => $sms->execute($tenant->id, true)] : []),
                ...($request->route('section') === 'email' ? ['email' => $email->execute($tenant->id, true)] : []),
            ],
        ]);
    }

    public function branding(Tenant $tenant, UpdateTenantBrandingRequest $request, UpdateTenantBrandingAction $update): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('platform_admin');
        $update->execute($tenant, $request->safe()->except(['logo', 'favicon']), $request->file('logo'), $request->file('favicon'), $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Branding updated.');
    }

    public function locales(Tenant $tenant, UpdateTenantLocalesRequest $request, UpdateTenantLocalesAction $update): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('platform_admin');
        $update->execute($tenant, $request->validated('enabled_locales'), $request->validated('default_locale'), $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Locales updated atomically.');
    }

    public function business(Tenant $tenant, UpdateCompanyBusinessSettingsRequest $request, UpdateTenantBusinessSettingsAction $update): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('platform_admin');
        $data = $request->validated();
        if (isset($data['security_deposit_refund_wait_days'])) {
            $data['security_deposit_refund_wait_days'] = (int) $data['security_deposit_refund_wait_days'];
        }
        $update->execute($tenant, $data, $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Business configuration updated. No funds were moved.');
    }

    public function kyc(Tenant $tenant, UpdateTenantKycSettingsRequest $request, UpdateTenantKycSettingsAction $update): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('platform_admin');
        $update->execute($tenant, $request->boolean('enabled'), $request->integer('max_accounts_per_identity'), $actor, $request->attributes->get('request_id'), KycReviewMode::from($request->validated('review_mode')));

        return back()->with('success', 'KYC configuration updated.');
    }
}
