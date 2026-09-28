<?php

namespace App\Http\Controllers\Platform;

use App\Application\Assets\PlatformFundsQuery;
use App\Application\Tenant\PlatformListFilters;
use App\Domain\Assets\ChainObservation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class FundsOrdersController extends Controller
{
    public function deposits(Request $request, PlatformFundsQuery $query, PlatformListFilters $lists)
    {
        return $this->listing('deposit', $request, $query, $lists);
    }

    public function withdrawals(Request $request, PlatformFundsQuery $query, PlatformListFilters $lists)
    {
        return $this->listing('withdrawal', $request, $query, $lists);
    }

    private function listing(string $mode, Request $request, PlatformFundsQuery $query, PlatformListFilters $lists)
    {
        $statuses = $mode === 'deposit' ? PlatformFundsQuery::DEPOSIT_STATUSES : PlatformFundsQuery::WITHDRAWAL_STATUSES;
        $filters = $lists->validated($request, [
            'status' => ['nullable', 'in:'.implode(',', $statuses)],
            'asset' => ['nullable', 'in:USDT,USDC,ETH,BTC,USD'],
            'network' => ['nullable', 'in:TRON,ETHEREUM,BITCOIN'],
        ]);

        return Inertia::render('platform/AssetOrders', [
            'mode' => $mode, 'orders' => $query->paginate($mode, $filters),
            'companies' => $lists->companies(), 'filters' => $filters, 'statuses' => $statuses,
            // Unassigned chain evidence has no company owner; never mix it into a filtered company list.
            'observations' => $mode === 'deposit' && ! array_filter($filters, fn ($v, $k) => $k !== 'page' && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH)
                ? ChainObservation::where('status', 'REQUIRES_REVIEW')->latest()->limit(20)->get(['network', 'event_id', 'rail_code', 'amount', 'occurred_at'])->toArray() : [],
        ]);
    }
}
