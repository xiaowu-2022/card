<?php

namespace App\Http\Controllers\Platform;

use App\Application\Kyc\PlatformKycQuery;
use App\Application\Tenant\PlatformListFilters;
use App\Application\Wallet\PlatformWalletQuery;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountOperationsController extends Controller
{
    public function index(Request $request, PlatformListFilters $lists, PlatformKycQuery $kyc, PlatformWalletQuery $wallets): Response
    {
        $isKyc = $request->routeIs('platform.kyc.index');
        $filters = $lists->validated($request, $isKyc ? ['status' => ['nullable', 'in:PENDING,APPROVED,REJECTED,RESUBMISSION_REQUIRED']] : []);

        return Inertia::render($isKyc ? 'platform/Kyc' : 'platform/Wallets', [
            'companies' => $lists->companies(), 'filters' => $filters,
            ...($isKyc
                ? ['applications' => $kyc->paginate($filters['company'] ?? null, $filters['search'] ?? null, $filters['status'] ?? null)]
                : ['wallets' => $wallets->paginate($filters['company'] ?? null, $filters['search'] ?? null)]),
        ]);
    }

    public function kyc(Tenant $tenant)
    {
        return redirect('/platform/kyc?company='.$tenant->id);
    }

    public function wallets(Tenant $tenant)
    {
        return redirect('/platform/wallets?company='.$tenant->id);
    }
}
