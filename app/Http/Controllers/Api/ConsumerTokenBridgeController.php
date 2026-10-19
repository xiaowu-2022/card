<?php

namespace App\Http\Controllers\Api;

use App\Application\User\ConsumerTokenBridge;
use App\Domain\Tenant\TenantContext;
use Illuminate\Http\Request;

final class ConsumerTokenBridgeController
{
    public function open(Request $request, TenantContext $tenant, ConsumerTokenBridge $bridge)
    {
        abort_unless($request->attributes->get('consumer_mode') === 'mobile', 404);

        return response()->json(['channel' => $bridge->open($tenant->id())]);
    }

    public function publish(Request $request, TenantContext $tenant, ConsumerTokenBridge $bridge)
    {
        abort_unless($request->attributes->get('consumer_mode') === 'mobile'
            && $request->attributes->get('consumer_token'), 401);

        return response()->json(['revision' => $bridge->publish($tenant->id(), $this->channel($request), $request->bearerToken())]);
    }

    public function claim(Request $request, TenantContext $tenant, ConsumerTokenBridge $bridge)
    {
        abort_unless($request->attributes->get('consumer_mode') === 'mobile', 404);
        $data = $request->validate(['revision' => 'required|integer|min:1|max:2147483647']);

        return response()->json(['token' => $bridge->claim($tenant->id(), $this->channel($request), (int) $data['revision'])]);
    }

    private function channel(Request $request): string
    {
        $value = $request->header('X-Consumer-Bridge');
        abort_unless(is_string($value) && preg_match('/^[A-Za-z0-9]{64}$/D', $value), 422);

        return $value;
    }
}
