<?php

namespace App\Http\Controllers;

use App\Application\Support\SupportAccess;
use App\Application\Support\SupportRealtime;
use App\Application\Support\SupportUserAgents;
use App\Domain\Tenant\TenantContext;
use Illuminate\Http\Request;

final class SupportRealtimeController extends Controller
{
    public function configuration(Request $request, SupportRealtime $realtime)
    {
        $channels = $this->channels($request);

        return response()->json($realtime->enabled() ? [
            'enabled' => true,
            'path' => '/app/'.rawurlencode((string) config('broadcasting.connections.reverb.key')),
            'port' => config('support-realtime.public_port') ? (int) config('support-realtime.public_port') : null,
            'channels' => $channels,
        ] : ['enabled' => false])->header('Cache-Control', 'private, no-store');
    }

    public function authorize(Request $request, SupportRealtime $realtime)
    {
        $data = $request->validate(['socket_id' => ['required', 'string', 'regex:/^[0-9]{1,20}\.[0-9]{1,20}$/D'], 'channel_name' => 'required|string|max:200']);
        abort_unless(in_array($data['channel_name'], $this->channels($request), true), 403);
        abort_unless($realtime->enabled(), 503);
        $signature = hash_hmac('sha256', $data['socket_id'].':'.$data['channel_name'], (string) config('broadcasting.connections.reverb.secret'));

        return response()->json(['auth' => config('broadcasting.connections.reverb.key').':'.$signature])->header('Cache-Control', 'private, no-store');
    }

    private function channels(Request $request): array
    {
        // Scope comes from middleware and the current authenticated principal, never submitted IDs.
        $scope = $request->attributes->get('admin_scope_type');
        if ($scope === 'PLATFORM') {
            $id = $request->user('platform_admin')->id;
            app(SupportAccess::class)->platform($id);

            return [SupportRealtime::platformChannel(), SupportRealtime::adminChannel($id)];
        }
        $tenant = app(TenantContext::class)->id();
        if ($scope === 'TENANT') {
            $id = $request->user('tenant_admin')->id;
            app(SupportAccess::class)->admin($tenant, $id);

            return [SupportRealtime::teamChannel($tenant), SupportRealtime::adminChannel($id)];
        }
        $user = $request->attributes->get('consumer_user');
        abort_unless($user && $user->tenant_id === $tenant, 401);
        $channels = [SupportRealtime::userChannel($tenant, $user->id)];
        if (app(SupportUserAgents::class)->enabled($tenant, $user->id)) {
            $channels[] = SupportRealtime::teamChannel($tenant);
        }

        return $channels;
    }
}
