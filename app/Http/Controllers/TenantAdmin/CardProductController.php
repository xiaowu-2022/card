<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\CardProduct\CardProductCatalogQuery;
use App\Application\CardProduct\ConfigureTenantCardProductAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConfigureTenantCardProductRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class CardProductController extends Controller
{
    public function index(TenantContext $context, CardProductCatalogQuery $query): Response
    {
        return Inertia::render('tenant-admin/CardProducts', $query->tenant($context->id()));
    }

    public function update(
        string $cardProduct,
        ConfigureTenantCardProductRequest $request,
        TenantContext $context,
        ConfigureTenantCardProductAction $configure,
    ): RedirectResponse {
        /** @var AdminUser $actor */
        $actor = $request->user('tenant_admin');
        $configure->execute($context->id(), $cardProduct, $request->validated(), $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Tenant card offering updated. Future pricing only; no funds were moved.');
    }
}
