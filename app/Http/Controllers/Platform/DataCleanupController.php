<?php

namespace App\Http\Controllers\Platform;

use App\Application\Cleanup\DataCleanup;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DataCleanupController extends Controller
{
    public function accounts(Request $request, string $tenant): JsonResponse
    {
        Tenant::whereKey($tenant)->firstOrFail();
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = trim($data['search'] ?? '');
        $query = DB::table('users as u')->leftJoin('user_profiles as p', fn ($j) => $j->on('p.user_id', '=', 'u.id')->on('p.tenant_id', '=', 'u.tenant_id'))
            ->where('u.tenant_id', $tenant)->select('u.id', 'u.account_id as accountId', 'p.display_name as displayName');
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('u.account_id', 'like', '%'.$search.'%')->orWhere('u.email', 'ilike', '%'.$search.'%'));
        }

        return response()->json(['accounts' => $query->orderBy('u.created_at', 'desc')->limit(50)->get()])->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, string $tenant, DataCleanup $cleanup): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:50'], 'user_ids.*' => ['required', 'uuid', 'distinct:ignore_case'],
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

    public function records(Request $request, string $tenant, string $batch, DataCleanup $cleanup): JsonResponse
    {
        $cleanup->read($tenant, $batch, $request->user('platform_admin'));
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $page = DB::table('data_cleanup_items')->where('batch_id', $batch)->orderBy('id')->paginate(50, ['public_details']);

        return response()->json(['records' => array_map(fn ($row) => json_decode($row->public_details, true), $page->items()),
            'page' => $page->currentPage(), 'lastPage' => $page->lastPage()])->header('Cache-Control', 'private, no-store');
    }
}
