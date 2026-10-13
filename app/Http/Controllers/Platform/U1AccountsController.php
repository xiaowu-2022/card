<?php

namespace App\Http\Controllers\Platform;

use App\Application\CardProviderDirectory\U1Accounts;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessU1Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class U1AccountsController extends Controller
{
    public function send(string $reference, Request $request, U1Accounts $accounts)
    {
        return response()->json($accounts->send($reference, $request->only(['request_id', 'version', 'notification_type']), $request->user('platform_admin')))
            ->header('Cache-Control', 'private, no-store');
    }

    public function index(string $reference, Request $request, U1Accounts $accounts)
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'request_ids' => ['nullable', 'array', 'max:9'], 'request_ids.*' => ['required', 'uuid']]);
        $result = $accounts->notifications($reference, (int) ($data['page'] ?? 1));
        $result['requests'] = empty($data['request_ids']) ? $accounts->recentRequests($reference)
            : array_map(fn ($id) => $accounts->requestStatus($reference, $id), $data['request_ids']);

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function retry(string $reference, string $notification, U1Accounts $accounts)
    {
        $detail = $accounts->notification($reference, $notification);
        abort_unless($detail['verified'], 422);
        $connection = DB::table('u1_connections')->where('reference_id', $reference)->firstOrFail();
        ProcessU1Notification::dispatch($connection->id, $notification)->afterCommit();

        return response()->json(['queued' => true])->header('Cache-Control', 'private, no-store');
    }

    public function show(string $reference, string $notification, U1Accounts $accounts)
    {
        return response()->json($accounts->notification($reference, $notification))->header('Cache-Control', 'private, no-store');
    }
}
