<?php

namespace App\Http\Controllers\Platform;

use App\Application\Card\BatchCardTransactionSync;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CardTransactionBatchController extends Controller
{
    public function preview(Request $request, BatchCardTransactionSync $sync)
    {
        $input = $request->validate(['tenant_id' => ['nullable', 'uuid', 'exists:tenants,id']]);

        return response()->json($sync->preview($input['tenant_id'] ?? null))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, BatchCardTransactionSync $sync)
    {
        $input = $request->validate([
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'request_id' => ['required', 'uuid'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before_or_equal:'.now('Asia/Shanghai')->format('Y-m-d')],
        ]);
        if (CarbonImmutable::parse($input['date_from'], 'Asia/Shanghai')->diffInDays(CarbonImmutable::parse($input['date_to'], 'Asia/Shanghai')) >= 366) {
            throw ValidationException::withMessages(['date_to' => 'Select at most 366 days.']);
        }
        $id = $sync->create($request->user('platform_admin'), $input);

        return response()->json(['id' => $id], 202);
    }

    public function index(Request $request)
    {
        return response()->json(['items' => DB::table('card_transaction_sync_batches')->where('actor_id', $request->user('platform_admin')->id)
            ->orderByDesc('created_at')->limit(20)->get(['id', 'tenant_id', 'date_from', 'date_to', 'created_at'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $batch)
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $row = DB::table('card_transaction_sync_batches')->where('actor_id', $request->user('platform_admin')->id)->where('id', $batch)
            ->first(['id', 'tenant_id', 'date_from', 'date_to', 'created_at']);
        abort_unless($row, 404);
        $items = DB::table('card_transaction_sync_items')->where('batch_id', $batch);
        $counts = (clone $items)->selectRaw("COUNT(*) AS total,
            COUNT(*) FILTER(WHERE status='PENDING') AS pending,
            COUNT(*) FILTER(WHERE status='SUCCEEDED') AS succeeded,
            COUNT(*) FILTER(WHERE status='FAILED') AS failed,
            COUNT(*) FILTER(WHERE status='SKIPPED') AS skipped,
            COALESCE(SUM(pages_processed),0) AS pages, COALESCE(SUM(records_written),0) AS records")->first();
        $details = (clone $items)->whereIn('card_transaction_sync_items.status', ['FAILED', 'SKIPPED'])
            ->join('user_cards as c', fn ($j) => $j->on('c.id', '=', 'card_transaction_sync_items.card_id')->on('c.tenant_id', '=', 'card_transaction_sync_items.tenant_id'))
            ->orderBy('card_transaction_sync_items.id')->paginate(50,
                ['card_transaction_sync_items.id', 'c.masked_pan', 'card_transaction_sync_items.tenant_id',
                    'card_transaction_sync_items.status', 'error_code', 'next_page'], 'page', $request->integer('page', 1));

        return response()->json(['batch' => $row, 'counts' => $counts,
            'status' => $counts->pending > 0 ? 'RUNNING' : ($counts->failed > 0 ? 'PARTIAL_FAILED' : 'COMPLETED'),
            'details' => ['items' => $details->items(), 'page' => $details->currentPage(), 'hasMore' => $details->hasMorePages()]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function retry(Request $request, string $batch, BatchCardTransactionSync $sync)
    {
        $sync->retry($request->user('platform_admin'), $batch);

        return response()->json(['id' => $batch], 202);
    }
}
