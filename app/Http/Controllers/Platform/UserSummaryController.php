<?php

namespace App\Http\Controllers\Platform;

use App\Application\User\PlatformUserCardsSummary;
use App\Application\User\PlatformUserFinancialSummary;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class UserSummaryController
{
    private function snapshot(callable $read)
    {
        $outer = DB::transactionLevel();

        return DB::transaction(function () use ($read, $outer) {
            if ($outer === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return response()->json($read())->header('Cache-Control', 'private, no-store');
        });
    }

    public function funds(Tenant $tenant, string $user, PlatformUserFinancialSummary $query)
    {
        return $this->snapshot(fn () => $query->funds($tenant->id, $user));
    }

    public function cards(Tenant $tenant, string $user, Request $request, PlatformUserCardsSummary $query)
    {
        $data = $request->validate(['archived' => 'nullable|boolean', 'page' => 'nullable|integer|min:1|max:100000']);

        return $this->snapshot(fn () => $query->read($tenant->id, $user, $request->boolean('archived'), (int) ($data['page'] ?? 1)));
    }
}
