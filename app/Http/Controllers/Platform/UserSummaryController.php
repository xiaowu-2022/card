<?php

namespace App\Http\Controllers\Platform;

use App\Application\Card\PlatformCardQuery;
use App\Application\User\PlatformUserCardsSummary;
use App\Application\User\PlatformUserFinancialSummary;
use App\Application\User\Snapshots\UserSnapshotReader;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Http\Request;

final class UserSummaryController
{
    public function cardList(Tenant $tenant, string $user, Request $request, PlatformCardQuery $query, UserSnapshotReader $snapshots)
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1|max:100000', 'as_of' => 'prohibited']);
        $cards = $snapshots->read(null, fn () => $query->forUser($tenant->id, $user, (int) ($data['page'] ?? 1)), $tenant->id);

        return response()->json(['tenantId' => $tenant->id, 'userId' => $user, 'cards' => $cards])
            ->header('Cache-Control', 'private, no-store');
    }

    public function funds(Tenant $tenant, string $user, Request $request, PlatformUserFinancialSummary $query, UserSnapshotReader $snapshots)
    {
        $data = $snapshots->read($snapshots->cutoff($request), fn () => $query->funds($tenant->id, $user), $tenant->id);

        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function cards(Tenant $tenant, string $user, Request $request, PlatformUserCardsSummary $query, UserSnapshotReader $snapshots)
    {
        $data = $request->validate(['archived' => 'nullable|boolean', 'page' => 'nullable|integer|min:1|max:100000']);

        $result = $snapshots->read($snapshots->cutoff($request), fn () => $query->read($tenant->id, $user, $request->boolean('archived'), (int) ($data['page'] ?? 1)), $tenant->id);

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
