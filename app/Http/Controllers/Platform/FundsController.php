<?php

namespace App\Http\Controllers\Platform;

use App\Application\Tenant\PlatformListFilters;
use App\Application\User\PlatformFundsQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class FundsController extends Controller
{
    public function __invoke(Request $request, PlatformFundsQuery $query, PlatformListFilters $lists)
    {
        $filters = $lists->validated($request, [
            'page' => 'nullable|integer|min:1|max:100000',
            'asset' => 'nullable|string|regex:/^[A-Z0-9]{2,10}$/',
            'event' => 'nullable|string|regex:/^[A-Z0-9_]{1,80}$/',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);

        return Inertia::render('platform/Funds', [...$query->read($filters), 'filters' => $filters, 'companies' => $lists->companies()])
            ->toResponse($request)->header('Cache-Control', 'private, no-store');
    }
}
