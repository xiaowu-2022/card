<?php

namespace App\Http\Controllers\Platform;

use App\Application\Kyc\PlatformKycQuery;
use App\Application\Tenant\PlatformListFilters;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountOperationsController extends Controller
{
    public function index(Request $request, PlatformListFilters $lists, PlatformKycQuery $kyc): Response|RedirectResponse
    {
        $isKyc = $request->routeIs('platform.kyc.index');
        $filters = $lists->validated($request, $isKyc ? ['status' => ['nullable', 'in:PENDING,APPROVED,REJECTED,RESUBMISSION_REQUIRED']] : []);
        if (! $isKyc) {
            return redirect('/platform/users'.($filters ? '?'.http_build_query($filters) : ''));
        }

        return Inertia::render('platform/Kyc', [
            'companies' => $lists->companies(), 'filters' => $filters,
            'applications' => $kyc->paginate($filters['company'] ?? null, $filters['search'] ?? null, $filters['status'] ?? null),
        ]);
    }

    public function kyc(Tenant $tenant)
    {
        return redirect('/platform/kyc?company='.$tenant->id);
    }

    public function wallets(Request $request, Tenant $tenant, PlatformListFilters $lists)
    {
        return redirect('/platform/users?'.http_build_query(['company' => $tenant->id, ...array_diff_key($lists->validated($request), ['company' => true])]));
    }
}
