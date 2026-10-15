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

    public function reportFailure(\Illuminate\Http\Request $request, TenantContext $context, string $card): JsonResponse
    {
        $data = $request->validate([
            'page' => ['required', 'integer', 'min:1', 'max:100000'],
            'stage' => ['required', 'in:response_validation,http,transport'],
            'reason' => ['required', 'in:invalid_page,invalid_fee,invalid_item,request_failed'],
            'http_status' => ['nullable', 'integer', 'min:0', 'max:599'],
            'source_request_id' => ['nullable', 'uuid'],
        ]);
        $owned = \App\Domain\Card\Models\UserCard::where('tenant_id', $context->id())
            ->where('user_id', $request->user('tenant_user')->id)->whereKey($card)->firstOrFail();
        \Illuminate\Support\Facades\Log::warning('Client card transactions load failed', [
            ...$data, 'tenant_id' => $context->id(), 'user_id' => $owned->user_id,
            'card_id' => $owned->id, 'request_id' => $request->attributes->get('request_id'),
        ]);
        return response()->json(['recorded' => true])->header('Cache-Control', 'private, no-store');
    }

    public function sync(CardTransactionsRequest $request, TenantContext $context, SyncUserCardTransactionsAction $sync, string $card): JsonResponse
    {
        $user = $request->user('tenant_user');

        return response()->json($sync->execute($context->id(), $user->id, $card, $request->integer('page', 1)))
            ->header('Cache-Control', 'private, no-store');
    }
}
