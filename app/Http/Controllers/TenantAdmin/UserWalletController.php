<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Wallet\TenantAdminWalletQuery;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class UserWalletController extends Controller
{
    public function show(string $user, TenantContext $context, TenantAdminWalletQuery $query): Response
    {
        return Inertia::render('tenant-admin/UserWallet', ['userId' => $user] + $query->wallet($context->id(), $user));
    }

    public function ledger(string $user, TenantContext $context, TenantAdminWalletQuery $query): Response
    {
        return Inertia::render('tenant-admin/UserLedger', ['userId' => $user] + $query->ledger($context->id(), $user));
    }
}
