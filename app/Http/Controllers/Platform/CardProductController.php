<?php

namespace App\Http\Controllers\Platform;

use App\Application\CardProduct\CardProductCatalogQuery;
use App\Application\CardProduct\CreateCardProductAction;
use App\Application\CardProduct\RefreshCardFormFactors;
use App\Application\CardProduct\UpdateCardProductAction;
use App\Domain\Admin\Models\AdminUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCardProductRequest;
use App\Http\Requests\UpdateCardProductRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CardProductController extends Controller
{
    public function index(CardProductCatalogQuery $query): Response
    {
        return Inertia::render('platform/CardProducts', $query->platform());
    }

    public function refreshMerchant(string $merchant, Request $request, RefreshCardFormFactors $action): RedirectResponse
    {
        $action->merchant($merchant, $request->user('platform_admin'));

        return back()->with('success', 'Card capabilities refreshed.');
    }

    public function refreshForms(string $cardProduct, Request $request, RefreshCardFormFactors $action): RedirectResponse
    {
        $action->execute($cardProduct, $request->user('platform_admin'));

        return back()->with('success', 'Card capabilities refreshed.');
    }

    public function store(CreateCardProductRequest $request, CreateCardProductAction $create): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('platform_admin');
        $create->execute($request->validated(), $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Card product created. No financial operation was performed.');
    }

    public function update(string $cardProduct, UpdateCardProductRequest $request, UpdateCardProductAction $update): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('platform_admin');
        $update->execute($cardProduct, $request->validated(), $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Card product configuration updated.');
    }
}
