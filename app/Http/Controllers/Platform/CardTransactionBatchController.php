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
        $input = $request->validate(['tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'card_ids' => ['sometimes', 'array', 'min:1', 'max:500'], 'card_ids.*' => ['required', 'uuid', 'distinct:ignore_case']]);

        return response()->json($sync->preview($input['tenant_id'] ?? null, $input['card_ids'] ?? null))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, BatchCardTransactionSync $sync)
    {
        $input = $request->validate([
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'request_id' => ['required', 'uuid'],
            'card_ids' => ['sometimes', 'array', 'min:1', 'max:500'],
            'card_ids.*' => ['required', 'uuid', 'distinct:ignore_case'],
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
        $batches = DB::table('card_transaction_sync_batches')->where('actor_id', $request->user('platform_admin')->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get(['id', 'tenant_id', 'date_from', 'date_to', 'created_at', 'card_ids', 'execution_mode']);
        $counts = DB::table('card_transaction_sync_items')->whereIn('batch_id', $batches->pluck('id'))
            ->selectRaw("batch_id, COUNT(*) AS total,
                COUNT(*) FILTER(WHERE status='PENDING') AS pending,
                COUNT(*) FILTER(WHERE status='SUCCEEDED') AS succeeded,
                COUNT(*) FILTER(WHERE status='FAILED') AS failed,
                COUNT(*) FILTER(WHERE status='SKIPPED') AS skipped,
                COALESCE(SUM(pages_processed),0) AS pages, COALESCE(SUM(records_written),0) AS records")
            ->groupBy('batch_id')->get()->keyBy('batch_id');

        return response()->json(['items' => $batches->map(function ($row) use ($counts) {
            $values = [];
            foreach (['total', 'pending', 'succeeded', 'failed', 'skipped', 'pages', 'records'] as $key) {
                $values[$key] = (int) ($counts->get($row->id)?->$key ?? 0);
            }

            return ['id' => $row->id, 'tenant_id' => $row->tenant_id, 'date_from' => $row->date_from,
                'date_to' => $row->date_to, 'created_at' => $row->created_at,
                'scope' => $row->card_ids !== null ? 'selected' : ($row->tenant_id ? 'company' : 'all'),
                'execution_mode' => $row->execution_mode, 'counts' => $values,
                'status' => $this->status($row->execution_mode, $values['pending'], $values['failed'])];
        })])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $batch)
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $row = DB::table('card_transaction_sync_batches')->where('actor_id', $request->user('platform_admin')->id)->where('id', $batch)
            ->first(['id', 'tenant_id', 'date_from', 'date_to', 'created_at', 'execution_mode']);
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
            'status' => $this->status($row->execution_mode, (int) $counts->pending, (int) $counts->failed),
            'details' => ['items' => $details->items(), 'page' => $details->currentPage(), 'hasMore' => $details->hasMorePages()]])
            ->header('Cache-Control', 'private, no-store');
    }

    private function status(string $mode, int $pending, int $failed): string
    {
        if ($mode !== 'browser' && ($pending > 0 || $failed > 0)) {
            return 'RETIRED';
        }

        return $pending > 0 ? 'RUNNING' : ($failed > 0 ? 'PARTIAL_FAILED' : 'COMPLETED');
    }

    public function advance(Request $request, string $batch, BatchCardTransactionSync $sync)
    {
        return response()->json($sync->advance($request->user('platform_admin'), $batch))
            ->header('Cache-Control', 'private, no-store');
    }

    public function retry(Request $request, string $batch, BatchCardTransactionSync $sync)
    {
        $sync->retry($request->user('platform_admin'), $batch);

        return response()->json(['id' => $batch], 202);
    }
}
