<?php

namespace App\Http\Controllers\User;

use App\Application\Card\DTOs\CardManagementInput;
use App\Application\Card\UserCardManagementAction;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\ManageCardRequest;
use Illuminate\Http\JsonResponse;

final class CardManagementController extends Controller
{
    public function __invoke(ManageCardRequest $request, TenantContext $tenant, UserCardManagementAction $action, string $card): JsonResponse
    {
        return response()->json($action->execute($tenant->id(), $request->user('tenant_user')->id, $card, CardManagementInput::fromValidated($request->safe()->except(['current_password']))))
            ->header('Cache-Control', 'private, no-store, max-age=0')->header('Pragma', 'no-cache')->header('X-Content-Type-Options', 'nosniff');
    }
}
