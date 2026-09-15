<?php

namespace App\Http\Controllers\Platform;

use App\Application\Card\PlatformCardQuery;
use App\Application\Card\RefreshManagedCardAction;
use App\Application\Tenant\PlatformListFilters;
use App\Domain\Card\Models\UserCard;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CardOperationsController extends Controller
{
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
            'tab' => ['nullable', 'in:orders,cards'],
        ]);

        return Inertia::render('platform/Cards', [
            ...$query->get($filters['company'] ?? null, $filters['search'] ?? null),
            'filters' => $filters, 'companies' => $lists->companies(),
        ]);
    }
}
