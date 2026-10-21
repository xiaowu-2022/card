<?php

namespace App\Http\Controllers\Platform;

use App\Application\Tenant\PlatformDailyFundsQuery;
use App\Application\Tenant\PlatformFundsFilters;
use App\Application\Tenant\PlatformListFilters;
use App\Application\Tenant\PlatformOverviewQuery;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class DashboardController extends Controller
{
    public function __invoke(Request $request, PlatformFundsFilters $filters, PlatformDailyFundsQuery $query, PlatformListFilters $lists, AuthorizationService $authorization, PlatformOverviewQuery $overview): Response
    {
        $access = [
            'overflow' => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, 'cards.read'),
            'inflow' => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, 'wallet_topups.read'),
            'outflow' => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, 'withdrawals.read'),
        ];
        $validated = $filters->validated($request);
        $allows = fn ($permission) => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, $permission);
        $funds = $allows('users.read') && $allows('wallet.read') && $allows('ledger.read');
        $overviewAccess = ['inflow' => $access['inflow'], 'outflow' => $access['outflow'],
            'partners' => $funds && $allows('partners.manage'), 'cards' => $access['overflow'] && $allows('ledger.read'),
            'providers' => $allows('provider_operation.read'), 'assets' => $funds && $access['overflow']];
        $canAdjustRecharge = $access['inflow'] && $allows('wallet_topups.confirm');
        $outer = DB::transactionLevel();

        return DB::transaction(function () use ($request, $validated, $lists, $access, $query, $overview, $overviewAccess, $outer, $canAdjustRecharge) {
            if ($outer === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return Inertia::render('platform/Dashboard', [
                'filters' => $validated, 'companies' => $lists->companies(), 'financialAccess' => $access,
                ...$query->execute($validated, $access),
                'overviewAccess' => $overviewAccess, 'overview' => $overview->execute($overviewAccess) + ['canAdjustRecharge' => $canAdjustRecharge],
            ])->toResponse($request)->header('Cache-Control', 'private, no-store');
        });
    }
}
