<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Tenant\UpdateTenantArticleAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Enums\TenantArticleKey;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTenantArticleRequest;
use Illuminate\Http\RedirectResponse;

final class TenantArticleController extends Controller
{
    public function update(UpdateTenantArticleRequest $request, TenantContext $context, UpdateTenantArticleAction $update, string $article, string $locale): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('tenant_admin');
        $update->execute($context->tenant(), TenantArticleKey::from($article), $locale, $request->validated('body') ?? '', $actor, $request->attributes->get('request_id'));

        return redirect('/admin/settings/articles')->with('success', 'Article saved.');
    }
}
