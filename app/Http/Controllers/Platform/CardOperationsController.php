<?php

namespace App\Http\Controllers\Platform;

use App\Application\Card\PlatformCardQuery;
use App\Application\Card\PlatformCardTransactionsQuery;
use App\Application\Card\RecordCardOverflowSpend;
use App\Application\Card\RefreshManagedCardAction;
use App\Application\Tenant\PlatformListFilters;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\UserCard;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\CardTransactionsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class CardOperationsController extends Controller
{
    public function overflowSpend(Request $request, Tenant $tenant, string $card): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/'],
            'request_id' => ['required', 'uuid'], 'note' => ['required', 'string', 'max:500'],
            'confirmed' => ['required', 'accepted'],
        ]);
        app(RecordCardOverflowSpend::class)->execute($tenant->id, $card, $data, $request->user('platform_admin'));

        return back()->with('success', 'Card overflow consumption recorded.');
    }

    public function voidLoad(Request $request, Tenant $tenant, string $card, string $order): RedirectResponse
    {
        DB::transaction(function () use ($request, $tenant, $card, $order): void {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $snapshot = UserCard::query()->where('tenant_id', $tenant->id)->whereKey($card)->firstOrFail();
            User::query()->where('tenant_id', $tenant->id)->whereKey($snapshot->user_id)->lockForUpdate()->firstOrFail();
            UserCard::query()->where('tenant_id', $tenant->id)->whereKey($card)->lockForUpdate()->firstOrFail();
            $owned = CardManagementOrder::query()->where('tenant_id', $tenant->id)->where('card_id', $card)
                ->where('user_id', $snapshot->user_id)->where('kind', 'LOAD')->whereKey($order)->lockForUpdate()->firstOrFail();
            if ($owned->status === 'EXPIRED') {
                return;
            }
            if (! in_array($owned->status, ['QUOTING', 'QUOTED'], true) || $owned->hold_entry_id !== null
                || $owned->settlement_entry_id !== null || $owned->release_entry_id !== null || $owned->provider_called_at !== null) {
                throw ValidationException::withMessages(['card_load' => 'Only unsubmitted reload quotes without a funds hold can be voided.']);
            }
            $before = $owned->status;
            $owned->forceFill(['status' => 'EXPIRED'])->save();
            app(AuditLogger::class)->record($tenant->id, 'ADMIN', $request->user('platform_admin')->id,
                'CARD_LOAD_QUOTE_VOIDED', 'card_management_order', $order, ['status' => $before], ['status' => 'EXPIRED']);
        });

        return back()->with('success', 'Card reload quote voided. You can submit a new reload.');
    }

    public function updateLimit(Request $request, Tenant $tenant, string $card): RedirectResponse
    {
        $data = $request->validate(['balance_limit' => ['present', 'nullable', 'string', 'regex:/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/']]);
        DB::transaction(function () use ($request, $tenant, $card, $data): void {
            // Use the same ownership lock order as card operations.
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $snapshot = UserCard::query()->where('tenant_id', $tenant->id)->whereKey($card)->firstOrFail();
            User::query()->where('tenant_id', $tenant->id)->whereKey($snapshot->user_id)->lockForUpdate()->firstOrFail();
            $owned = UserCard::query()->where('tenant_id', $tenant->id)->whereKey($card)->lockForUpdate()->firstOrFail();
            CardManagementOrder::query()->where('tenant_id', $tenant->id)->where('card_id', $card)
                ->whereIn('status', ['QUOTING', 'QUOTED'])->where('created_at', '<', now()->subMinute())->update(['status' => 'EXPIRED']);
            if (CardManagementOrder::query()->where('tenant_id', $tenant->id)->where('card_id', $card)
                ->whereIn('status', ['QUOTING', 'QUOTED', 'PROCESSING', 'UNKNOWN'])->exists()) {
                throw ValidationException::withMessages(['balance_limit' => 'Wait for the current card operation to finish.']);
            }
            $before = $owned->balance_limit;
            $limit = $data['balance_limit'] === null ? null : Money::of($data['balance_limit'], 'USD')->amount();
            $owned->forceFill(['balance_limit' => $limit])->save();
            app(AuditLogger::class)->record($tenant->id, 'ADMIN', $request->user('platform_admin')->id,
                'CARD_BALANCE_LIMIT_UPDATED', 'user_card', $card, ['balance_limit' => $before], ['balance_limit' => $limit]);
        });

        return back()->with('success', 'Card balance limit updated.');
    }

    public function transactions(CardTransactionsRequest $request, Tenant $tenant, string $card, PlatformCardTransactionsQuery $query): JsonResponse
    {
        return response()->json($query->get($tenant->id, $card, $request->integer('page', 1)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function refresh(Tenant $tenant, string $card, RefreshManagedCardAction $refresh): RedirectResponse
    {
        $owned = UserCard::query()->where('tenant_id', $tenant->id)->whereKey($card)->firstOrFail();
        try {
            $refresh->execute($tenant->id, $owned->user_id, $owned->id);
        } catch (\Throwable) {
            return back()->withErrors(['card_balance' => 'Card balance could not be refreshed. The last confirmed balance is shown.']);
        }

        return back()->with('success', 'Card balance refreshed from the provider.');
    }

    public function __invoke(Request $request, PlatformCardQuery $query, PlatformListFilters $lists): Response
    {
        $filters = $lists->validated($request, [
            'orders_page' => ['sometimes', 'integer', 'min:1'],
            'cards_page' => ['sometimes', 'integer', 'min:1'],
            'tab' => ['nullable', 'in:orders,cards,loads'],
            'loads_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return Inertia::render('platform/Cards', [
            ...$query->get($filters['company'] ?? null, $filters['search'] ?? null),
            'filters' => $filters, 'companies' => $lists->companies(),
        ]);
    }
}
