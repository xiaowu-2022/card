<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantArticleKey;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantArticle;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTenantArticleAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(Tenant $tenant, TenantArticleKey $key, string $locale, string $body, AdminUser $actor, ?string $requestId = null): void
    {
        app(CompanyConfigurationAuthority::class)->assert($actor);
        DB::transaction(function () use ($tenant, $key, $locale, $body, $actor, $requestId): void {
            // Serializes creation for the fixed tenant/key/locale tuple, including its first save.
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $article = TenantArticle::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'article_key' => $key->value, 'locale' => $locale],
                ['body' => trim($body)],
            );
            $this->audit->record($tenant->id, 'ADMIN', $actor->id, 'TENANT_ARTICLE_UPDATED', 'tenant_articles', $article->id,
                after: ['article_key' => $key->value, 'locale' => $locale, 'configured' => $article->body !== ''], requestId: $requestId);
        });
    }
}
