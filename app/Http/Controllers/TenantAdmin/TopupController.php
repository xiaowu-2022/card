<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Payment\TenantAdminTopupQuery;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class TopupController extends Controller
{
    public function index(TenantContext $context, TenantAdminTopupQuery $query): Response
    {
        return Inertia::render('tenant-admin/Topups', $query->list($context->id()));
    }

    public function show(string $topup, TenantContext $context, TenantAdminTopupQuery $query): Response
    {
        return Inertia::render('tenant-admin/TopupDetail', $query->detail($context->id(), $topup));
    }
}
