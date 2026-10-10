<?php

namespace App\Http\Controllers\Platform;

use App\Application\Assets\ExportPlatformWithdrawals;
use App\Application\Assets\PlatformFundsQuery;
use App\Application\Tenant\PlatformListFilters;
use App\Application\User\PlatformUserSummary;
use App\Domain\Assets\ChainObservation;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

    public function userOrders(Request $request, string $tenant, string $user, string $mode, PlatformFundsQuery $query)
    {
        User::where('tenant_id', $tenant)->whereKey($user)->firstOrFail();
        $statuses = $mode === 'deposit' ? PlatformFundsQuery::DEPOSIT_STATUSES : PlatformFundsQuery::WITHDRAWAL_STATUSES;
        $filters = $request->validate([
            'page' => 'nullable|integer|min:1|max:100000',
            'status' => ['nullable', 'in:'.implode(',', $statuses)],
        ]);
        $filters['company'] = $tenant;
        $filters['user'] = $user;

        return response()->json(['orders' => $query->paginate($mode, $filters), 'statuses' => $statuses])
            ->header('Cache-Control', 'private, no-store');
    }

    public function exportWithdrawals(Request $request, ExportPlatformWithdrawals $export, PlatformListFilters $lists)
    {
        $data = $request->validate(['password' => ['required', 'string'], 'confirmed' => ['required', 'accepted']]);
        $actor = $request->user('platform_admin');
        abort_unless(Hash::check($data['password'], $actor->fresh()->password), 403);
        $filters = $this->filters('withdrawal', $request, $lists);
        unset($filters['page']);

        return response($export->execute($actor, $filters), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="withdrawals-'.now()->format('Ymd-His').'.csv"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function filters(string $mode, Request $request, PlatformListFilters $lists): array
    {
        $statuses = $mode === 'deposit' ? PlatformFundsQuery::DEPOSIT_STATUSES : PlatformFundsQuery::WITHDRAWAL_STATUSES;

        return $lists->validated($request, [
            'status' => ['nullable', 'in:'.implode(',', $statuses)],
            'asset' => ['nullable', 'in:USDT,USDC,ETH,BTC,USD'],
            'network' => ['nullable', 'in:TRON,ETHEREUM,BITCOIN'],
        ]);
    }

    private function listing(string $mode, Request $request, PlatformFundsQuery $query, PlatformListFilters $lists)
    {
        $statuses = $mode === 'deposit' ? PlatformFundsQuery::DEPOSIT_STATUSES : PlatformFundsQuery::WITHDRAWAL_STATUSES;
        $filters = $this->filters($mode, $request, $lists);

        return Inertia::render('platform/AssetOrders', [
            'mode' => $mode, 'orders' => PlatformUserSummary::page($query->paginate($mode, $filters)),
            'companies' => $lists->companies(), 'filters' => $filters, 'statuses' => $statuses,
            // Unassigned chain evidence has no company owner; never mix it into a filtered company list.
            'observations' => $mode === 'deposit' && ! array_filter($filters, fn ($v, $k) => $k !== 'page' && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH)
                ? ChainObservation::where('status', 'REQUIRES_REVIEW')->latest()->limit(20)->get(['network', 'event_id', 'rail_code', 'amount', 'occurred_at'])->toArray() : [],
        ]);
    }
}
