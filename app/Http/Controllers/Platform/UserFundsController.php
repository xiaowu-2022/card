<?php

namespace App\Http\Controllers\Platform;

use App\Application\User\PlatformUserFundsQuery;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class UserFundsController extends Controller
{
    public function __invoke(Tenant $tenant, string $user, Request $request, PlatformUserFundsQuery $query)
    {
        $filters = $request->validate([
            'page' => 'nullable|integer|min:1|max:100000',
            'asset' => 'nullable|string|regex:/^[A-Z0-9]{2,10}$/',
            'event' => 'nullable|string|regex:/^[A-Z0-9_]{1,80}$/',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);

        return response()->json($query->read($tenant, $user, $filters))->header('Cache-Control', 'private, no-store');
    }
}
