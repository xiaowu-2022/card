<?php

namespace App\Http\Controllers\User;

use App\Application\Card\ActivatePhysicalCardAction;
use App\Application\Card\CardManagementAccess;
use App\Application\Card\RefreshManagedCardAction;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActivatePhysicalCardRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PhysicalCardActivationController extends Controller
{
    public function sync(string $card, Request $request, TenantContext $context): JsonResponse
    {
        $userId = $request->user('tenant_user')->id;
        app(CardManagementAccess::class)->card($context->id(), $userId, $card);
        $fresh = app(RefreshManagedCardAction::class)->execute($context->id(), $userId, $card);
        $status = DB::table('card_activation_attempts')->where('card_id', $card)->latest('created_at')->value('status');

        return response()->json(['status' => $status ?? ($fresh->provider_status === 'normal' ? 'SUCCEEDED' : 'UNKNOWN')])->header('Cache-Control', 'private, no-store');
    }

    public function store(string $card, ActivatePhysicalCardRequest $request, TenantContext $context, ActivatePhysicalCardAction $action): JsonResponse
    {
        $data = $request->validated();
        $status = $action->execute($context->id(), $request->user('tenant_user')->id, $card, $data['request_id'],
            $data['expiration_date'], $data['pin'], $data['pin_confirmation']);

        return response()->json(['status' => $status])->header('Cache-Control', 'private, no-store');
    }
}
