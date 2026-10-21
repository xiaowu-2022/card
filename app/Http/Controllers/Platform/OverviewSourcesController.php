<?php

namespace App\Http\Controllers\Platform;

use App\Application\Tenant\PlatformOverviewQuery;
use App\Application\Tenant\PlatformOverviewSources;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OverviewSourcesController extends Controller
{
    public function __invoke(Request $request, PlatformOverviewSources $sources, AuthorizationService $authorization)
    {
        $filters = $request->validate([
            'metric' => ['required', Rule::in(PlatformOverviewSources::METRICS)],
            'period' => ['sometimes', Rule::in(['today', 'd3', 'd7', 'd15', 'd30', 'total'])],
            'page' => 'nullable|integer|min:1|max:100000',
            'adjustment_page' => 'nullable|integer|min:1|max:100000',
            'asset' => ['nullable', Rule::in(['USDT', 'USDC', 'ETH', 'BTC', 'USD'])],
        ]);
        $gate = PlatformOverviewSources::gate($filters['metric']);
        $permissions = match ($gate) {
            'partners' => ['users.read', 'wallet.read', 'ledger.read', 'partners.manage'],
            'assets' => ['users.read', 'wallet.read', 'ledger.read', 'cards.read'],
            'cards' => ['cards.read', 'ledger.read'], 'inflow' => ['wallet_topups.read'], 'outflow' => ['withdrawals.read'],
            'providers' => ['provider_operation.read'],
        };
        foreach ($permissions as $permission) {
            abort_unless($authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, $permission), 403);
        }
        $metric = $filters['metric'];
        $period = $filters['period'] ?? 'total';
        abort_if(in_array($metric, PlatformOverviewQuery::ACTIVITY, true) && $period === 'total', 422);
        abort_if($metric === 'cardFunding' && ! in_array($period, ['today', 'total'], true), 422);
        abort_if(! empty($filters['asset']) && ! in_array($metric, ['inflow', 'outflow'], true), 422);

        return response()->json($sources->read($metric, $period, $filters['page'] ?? 1, $filters['asset'] ?? null, $filters['adjustment_page'] ?? 1))->header('Cache-Control', 'private, no-store');
    }
}
