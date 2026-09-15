<?php

namespace App\Http\Controllers\User;

use App\Application\Card\SyncUserCardTransactionsAction;
use App\Application\Card\UserCardTransactionsQuery;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\CardTransactionsRequest;
use Illuminate\Http\JsonResponse;

final class CardTransactionsController extends Controller
{
    public function index(CardTransactionsRequest $request, TenantContext $context, UserCardTransactionsQuery $query, string $card): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('tenant_user');

        return response()->json($query->get($context->id(), $user->id, $card, $request->integer('page', 1)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function sync(CardTransactionsRequest $request, TenantContext $context, SyncUserCardTransactionsAction $sync, string $card): JsonResponse
    {
        $user = $request->user('tenant_user');

        return response()->json($sync->execute($context->id(), $user->id, $card, $request->integer('page', 1)))
            ->header('Cache-Control', 'private, no-store');
    }
}
