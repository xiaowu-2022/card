<?php

namespace App\Application\Tenant;

use App\Domain\Tenant\Enums\TenantArticleKey;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantArticle;

final class UserTenantArticleQuery
{
    /** @return array{key: string, locale: string, body: string|null} */
    public function execute(Tenant $tenant, TenantArticleKey $key, string $locale): array
    {
        $enabled = $tenant->locales()->where('locale', $locale)->where('enabled', true)->exists();
        $body = $enabled ? TenantArticle::query()->where('tenant_id', $tenant->id)
            ->where('article_key', $key->value)->where('locale', $locale)->value('body') : null;

        if ($enabled && ($body === null || trim($body) === '') && $locale !== 'en') {
            $body = TenantArticle::query()->where('tenant_id', $tenant->id)
                ->where('article_key', $key->value)->where('locale', 'en')->value('body');
            if ($body !== null && trim($body) !== '') {
                $locale = 'en';
            }
        }

        return ['key' => $key->value, 'locale' => $locale, 'body' => $body === '' ? null : $body];
    }
}
