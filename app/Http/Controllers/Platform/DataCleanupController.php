<?php

namespace App\Http\Controllers\Platform;

use App\Application\Cleanup\DataCleanup;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DataCleanupController extends Controller
{
    public function preview(Request $request, string $tenant, DataCleanup $cleanup): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:50'], 'user_ids.*' => ['required', 'uuid', 'distinct'],
            'modules' => ['required', 'array', 'min:1', 'max:4'], 'modules.*' => ['required', 'in:account,orders,commissions,cards', 'distinct'],
            'tenant_id' => ['prohibited'], 'balances' => ['prohibited'], 'tables' => ['prohibited'],
        ]);
        return response()->json($cleanup->preview($tenant, $request->user('platform_admin'), $data))->header('Cache-Control', 'private, no-store');
    }

    public function execute(Request $request, string $tenant, string $batch, DataCleanup $cleanup): JsonResponse
    {
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted'],
            'user_ids' => ['prohibited'], 'modules' => ['prohibited'], 'tenant_id' => ['prohibited'], 'balances' => ['prohibited'], 'tables' => ['prohibited']]);
        return response()->json($cleanup->execute($tenant, $batch, $request->user('platform_admin'), $data['request_id'], true))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $tenant, string $batch, DataCleanup $cleanup): JsonResponse
    {
        return response()->json($cleanup->read($tenant, $batch, $request->user('platform_admin')))->header('Cache-Control', 'private, no-store');
    }
}
