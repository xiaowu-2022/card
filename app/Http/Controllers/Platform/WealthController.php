<?php

namespace App\Http\Controllers\Platform;

use App\Application\Wealth\WealthConfiguration;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class WealthController extends Controller
{
    public function show(Tenant $tenant, WealthConfiguration $config)
    {
        return Inertia::render('platform/WealthSettings', ['company' => ['id' => $tenant->id, 'name' => $tenant->name], 'settings' => $config->get($tenant->id), 'readOnly' => false]);
    }

    public function read(TenantContext $context, WealthConfiguration $config)
    {
        $tenant = Tenant::findOrFail($context->id());

        return Inertia::render('platform/WealthSettings', ['company' => ['id' => $tenant->id, 'name' => $tenant->name], 'settings' => $config->get($tenant->id), 'readOnly' => true]);
    }

    public function save(Request $request, Tenant $tenant, WealthConfiguration $config)
    {
        $config->save($request->user('platform_admin'), $tenant->id, $request->all());

        return back();
    }
}
