<?php

namespace App\Http\Controllers\Platform;

use App\Application\Tenant\OverviewRechargeAdjustments;
use App\Application\Tenant\PlatformOverviewQuery;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OverviewRechargeAdjustmentController extends Controller
{
    public function index(Request $request, OverviewRechargeAdjustments $adjustments)
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1|max:100000']);
        $outer = DB::transactionLevel();

        return DB::transaction(function () use ($adjustments, $data, $outer) {
            if ($outer === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $at = CarbonImmutable::now();
            $overview = app(PlatformOverviewQuery::class)->execute(array_replace(array_fill_keys(['inflow', 'outflow', 'partners', 'cards', 'providers', 'assets'], false), ['inflow' => true]), $at);

            return response()->json($adjustments->history($at, $data['page'] ?? 1) + [
                'recordedAmount' => $overview['inflow']['recordedTotals']['total'], 'adjustedAmount' => $overview['inflow']['totals']['total'],
            ])->header('Cache-Control', 'private, no-store');
        });
    }

    public function store(Request $request, OverviewRechargeAdjustments $adjustments)
    {
        $entry = $adjustments->record($request->user('platform_admin'), $request->only(['amount', 'business_date', 'reason', 'request_id', 'confirmed']));

        return response()->json(['id' => $entry->id])->header('Cache-Control', 'private, no-store');
    }
}
