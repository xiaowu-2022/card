<?php

namespace App\Http\Controllers\Platform;

use App\Application\CardProviderDirectory\CardProviderReferenceQuery;
use App\Application\CardProviderDirectory\SaveCardProviderReferenceAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CardProviderController extends Controller
{
    public function __invoke(Request $request, CardProviderReferenceQuery $query): Response
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return Inertia::render('platform/CardProviders', $query->list());
    }

    public function store(Request $request, SaveCardProviderReferenceAction $save)
    {
        $save->execute(null, $request->only(['name', 'reference_balance', 'request_id', 'version', 'balance', 'asset', 'tenant_id', 'runtime_driver']), $request->user('platform_admin'), $request->attributes->get('request_id'));

        return back()->with('success', 'Card provider reference saved.');
    }

    public function update(string $reference, Request $request, SaveCardProviderReferenceAction $save)
    {
        $save->execute($reference, $request->only(['name', 'reference_balance', 'request_id', 'version', 'balance', 'asset', 'tenant_id', 'runtime_driver']), $request->user('platform_admin'), $request->attributes->get('request_id'));

        return back()->with('success', 'Card provider reference saved.');
    }
}
