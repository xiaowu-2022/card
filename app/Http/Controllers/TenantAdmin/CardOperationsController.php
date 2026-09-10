<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Card\TenantAdminCardQuery;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class CardOperationsController extends Controller
{
    public function __invoke(TenantContext $context, TenantAdminCardQuery $query): Response
    {
        return Inertia::render('tenant-admin/Cards', $query->get($context->id()));
    }
}
