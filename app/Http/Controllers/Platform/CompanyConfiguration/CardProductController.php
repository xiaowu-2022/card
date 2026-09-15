<?php

namespace App\Http\Controllers\Platform\CompanyConfiguration;

use App\Application\CardProduct\CardProductCatalogQuery;
use App\Application\CardProduct\ConfigureTenantCardProductAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConfigureTenantCardProductRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class CardProductController extends Controller
{
    public function index(Tenant $tenant, CardProductCatalogQuery $query): Response
    {
        return Inertia::render('tenant-admin/CardProducts', $query->tenant($tenant->id));
    }

    public function update(Tenant $tenant, string $cardProduct, ConfigureTenantCardProductRequest $request, ConfigureTenantCardProductAction $configure): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('platform_admin');
        $configure->execute($tenant->id, $cardProduct, $request->validated(), $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Tenant card offering updated. Future pricing only; no funds were moved.');
    }
}
