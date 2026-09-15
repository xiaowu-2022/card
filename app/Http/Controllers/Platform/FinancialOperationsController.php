<?php

namespace App\Http\Controllers\Platform;

use App\Application\Admin\FinancialOperationQuery;
use App\Application\Tenant\PlatformListFilters;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class FinancialOperationsController extends Controller
{
    public function __invoke(Request $request, FinancialOperationQuery $query, PlatformListFilters $lists)
    {
        $filters = $lists->validated($request);

        return Inertia::render('platform/FinancialOperations', ['operations' => $query->paginate($filters['company'] ?? null, $filters['search'] ?? null),
            'filters' => $filters, 'companies' => $lists->companies()]);
    }
}
