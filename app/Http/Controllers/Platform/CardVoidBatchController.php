<?php

namespace App\Http\Controllers\Platform;

use App\Application\Card\CardVoidBatch;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class CardVoidBatchController extends Controller
{
    public function index(Request $request, CardVoidBatch $service)
    {
        return response()->json(['items' => $service->recent($request->user('platform_admin'))])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, CardVoidBatch $service)
    {
        return response()->json(['id' => $service->create($request->user('platform_admin'), $request->all())], 202)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $batch, CardVoidBatch $service)
    {
        return response()->json($service->show($request->user('platform_admin'), $batch))->header('Cache-Control', 'private, no-store');
    }

    public function advance(Request $request, string $batch, CardVoidBatch $service)
    {
        return response()->json($service->advance($request->user('platform_admin'), $batch))->header('Cache-Control', 'private, no-store');
    }

    public function retry(Request $request, string $batch, CardVoidBatch $service)
    {
        $service->retry($request->user('platform_admin'), $batch, $request->all());

        return response()->json($service->show($request->user('platform_admin'), $batch))->header('Cache-Control', 'private, no-store');
    }
}
