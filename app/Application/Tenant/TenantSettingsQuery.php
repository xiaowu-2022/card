<?php

namespace App\Application\Tenant;

use App\Application\Media\ImageStorage;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantArticle;

final class TenantSettingsQuery
{
    /** @return array<string, mixed> */
    public function execute(Tenant $tenant, bool $includeArticles = false, ?string $section = null): array
    {
        $relations = match ($section) {
            'branding' => ['branding', 'domains'],
            'locales' => ['locales'],
            'business' => ['businessSettings'],
            null => ['branding', 'locales', 'businessSettings', 'domains'],
            default => [],
        };
        $tenant->loadMissing($relations);

        return [
            ...($section === null || $section === 'branding' ? ['branding' => [
                'brandName' => $tenant->branding->brand_name,
                'apkName' => $tenant->branding->apk_name,
                'primaryColor' => $tenant->branding->primary_color,
                'supportEmail' => $tenant->branding->support_email,
                'supportUrl' => $tenant->branding->support_url,
                'copyrightText' => $tenant->branding->copyright_text,
                'logoUrl' => $tenant->branding->logo_object_key ? app(ImageStorage::class)->displayUrl('public', $tenant->branding->logo_object_key, 'brand') : null,
                'logoSources' => app(ImageStorage::class)->previewSources('public', $tenant->branding?->logo_object_key, 'brand'),
                'apkLogoUrl' => $tenant->branding->apk_logo_object_key ? app(ImageStorage::class)->displayUrl('public', $tenant->branding->apk_logo_object_key, 'original') : null,
                'apkLogoSources' => app(ImageStorage::class)->previewSources('public', $tenant->branding?->apk_logo_object_key, 'original'),
                'faviconUrl' => $tenant->branding->favicon_object_key ? app(ImageStorage::class)->displayUrl('public', $tenant->branding->favicon_object_key, 'brand') : null,
            ]] : []),
            ...($section === null || $section === 'locales' ? ['locales' => $tenant->locales->map(fn ($locale) => ['locale' => $locale->locale, 'enabled' => $locale->enabled, 'default' => $locale->is_default])] : []),
            ...($section === null || $section === 'business' ? ['business' => [
                'depositAmount' => $tenant->businessSettings->required_security_deposit_amount,
                'depositAsset' => $tenant->businessSettings->required_security_deposit_asset,
                'depositRefundWaitDays' => $tenant->businessSettings->security_deposit_refund_wait_days,
                'withdrawalFeePercent' => $tenant->businessSettings->withdrawal_fee_percent,
            ]] : []),
            ...($section === null || $section === 'kyc' ? ['kyc' => PlatformKycSetting::current()->policy()] : []),
            'supportedLocales' => config('tenancy.supported_locales'),
            'supportedAssets' => config('tenancy.supported_assets'),
            ...($includeArticles ? ['articles' => TenantArticle::query()->where('tenant_id', $tenant->id)
                ->orderBy('article_key')->orderBy('locale')->get(['article_key', 'locale', 'body'])
                ->map(fn (TenantArticle $article): array => ['key' => $article->article_key, 'locale' => $article->locale, 'body' => $article->body])->all()] : []),
        ];
    }
}
