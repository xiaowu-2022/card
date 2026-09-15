<?php

namespace App\Http\Controllers\User;

use App\Application\Tenant\UserTenantArticleQuery;
use App\Domain\Tenant\Enums\TenantArticleKey;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AboutController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('user/About');
    }

    public function show(Request $request, TenantContext $context, UserTenantArticleQuery $query, string $article): Response
    {
        return Inertia::render('user/AboutArticle', [
            'article' => $query->execute($context->tenant(), TenantArticleKey::from($article), $request->attributes->get('client_locale')),
        ]);
    }
}
