<?php

namespace App\Application\Tenant;

use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantArticle;

final class TenantSettingsQuery
{
    /** @return array<string, mixed> */
    public function execute(Tenant $tenant, bool $includeArticles = false): array
    {
        $tenant->loadMissing(['branding', 'locales', 'businessSettings', 'domains']);

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
                'depositRefundWaitDays' => $tenant->businessSettings->security_deposit_refund_wait_days,
                'withdrawalFeePercent' => $tenant->businessSettings->withdrawal_fee_percent,
            ],
            'kyc' => PlatformKycSetting::current()->policy(),
            'supportedLocales' => config('tenancy.supported_locales'),
            'supportedAssets' => config('tenancy.supported_assets'),
            ...($includeArticles ? ['articles' => TenantArticle::query()->where('tenant_id', $tenant->id)
                ->orderBy('article_key')->orderBy('locale')->get(['article_key', 'locale', 'body'])
                ->map(fn (TenantArticle $article): array => ['key' => $article->article_key, 'locale' => $article->locale, 'body' => $article->body])->all()] : []),
        ];
    }
}
