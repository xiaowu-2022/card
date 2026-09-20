<?php

namespace App\Http\Controllers\Platform;

use App\Application\Tenant\PlatformDailyFundsQuery;
use App\Application\Tenant\PlatformFundsFilters;
use App\Application\Tenant\PlatformListFilters;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(Request $request, PlatformFundsFilters $filters, PlatformDailyFundsQuery $query, PlatformListFilters $lists, AuthorizationService $authorization): Response
    {
        $access = [
            'overflow' => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, 'cards.read'),
            'inflow' => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, 'wallet_topups.read'),
            'outflow' => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, 'withdrawals.read'),
        ];
        $validated = $filters->validated($request);

        return Inertia::render('platform/Dashboard', [
            'filters' => $validated, 'companies' => $lists->companies(), 'financialAccess' => $access,
            ...$query->execute($validated, $access),
        ]);
    }
}
