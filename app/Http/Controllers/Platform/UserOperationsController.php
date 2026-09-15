<?php

namespace App\Http\Controllers\Platform;

use App\Application\Tenant\PlatformListFilters;
use App\Application\User\PlatformUserQuery;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class UserOperationsController extends Controller
{
    public function __invoke(Request $request, PlatformListFilters $lists, PlatformUserQuery $query, AuthorizationService $authorization): Response
    {
        $filters = $lists->validated($request, ['status' => ['nullable', 'in:ACTIVE,SUSPENDED,DISABLED']]);
        $allowed = fn (string $permission): bool => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, $permission);
        $financialAccess = ['balances' => $allowed('wallet.read'), 'commission' => $allowed('ledger.read'), 'withdrawals' => $allowed('withdrawals.read')];

        return Inertia::render('platform/Users', [
            'users' => $query->paginate($filters['company'] ?? null, $filters['search'] ?? null, $filters['status'] ?? null, $financialAccess),
            'financialAccess' => $financialAccess,
            'companies' => $lists->companies(), 'filters' => $filters,
        ]);
    }
}
