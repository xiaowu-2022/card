<?php

namespace App\Http\Controllers\Platform\CompanyConfiguration;

use App\Application\Tenant\UpdateTenantArticleAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Enums\TenantArticleKey;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTenantArticleRequest;
use Illuminate\Http\RedirectResponse;

final class TenantArticleController extends Controller
{
    public function update(Tenant $tenant, UpdateTenantArticleRequest $request, UpdateTenantArticleAction $update, string $article, string $locale): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('platform_admin');
        $update->execute($tenant, TenantArticleKey::from($article), $locale, $request->validated('body') ?? '', $actor, $request->attributes->get('request_id'));

        return redirect('/platform/tenants/'.$tenant->id.'/configuration/settings/articles')->with('success', 'Article saved.');
    }
}
