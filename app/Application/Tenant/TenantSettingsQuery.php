<?php

namespace App\Application\Tenant;

use App\Domain\Tenant\Models\Tenant;

final class TenantSettingsQuery
{
    /** @return array<string, mixed> */
    public function execute(Tenant $tenant): array
    {
        $tenant->loadMissing(['branding', 'locales', 'businessSettings', 'kycSettings', 'domains']);

        return [
            'branding' => [
                'brandName' => $tenant->branding->brand_name,
                'primaryColor' => $tenant->branding->primary_color,
                'supportEmail' => $tenant->branding->support_email,
                'supportUrl' => $tenant->branding->support_url,
                'copyrightText' => $tenant->branding->copyright_text,
                'logoUrl' => $tenant->branding->logo_object_key ? asset('storage/'.$tenant->branding->logo_object_key) : null,
                'faviconUrl' => $tenant->branding->favicon_object_key ? asset('storage/'.$tenant->branding->favicon_object_key) : null,
            ],
            'locales' => $tenant->locales->map(fn ($locale) => ['locale' => $locale->locale, 'enabled' => $locale->enabled, 'default' => $locale->is_default]),
            'business' => [
                'depositAmount' => $tenant->businessSettings->required_security_deposit_amount,
                'depositAsset' => $tenant->businessSettings->required_security_deposit_asset,
                'allowWalletTopup' => $tenant->businessSettings->allow_wallet_topup,
                'allowWithdrawal' => $tenant->businessSettings->allow_withdrawal,
            ],
            'kyc' => [
                'enabled' => $tenant->kycSettings->enabled,
                'maxAccountsPerIdentity' => $tenant->kycSettings->max_accounts_per_identity,
                'reviewMode' => $tenant->kycSettings->review_mode->value,
            ],
            'supportedLocales' => config('tenancy.supported_locales'),
            'supportedAssets' => config('tenancy.supported_assets'),
        ];
    }
}
