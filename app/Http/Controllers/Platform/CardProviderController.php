<?php

namespace App\Http\Controllers\Platform;

use App\Application\CardProviderDirectory\CardProviderReferenceQuery;
use App\Application\CardProviderDirectory\PhotonPayAccounts;
use App\Application\CardProviderDirectory\SaveCardProviderReferenceAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class CardProviderController extends Controller
{
    public function __invoke(Request $request, CardProviderReferenceQuery $query): Response
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return Inertia::render('platform/CardProviders', $query->list());
    }

    public function check(string $reference, Request $request, PhotonPayAccounts $accounts)
    {
        $accounts->check($reference, $request->user('platform_admin'));

        return back()->with('success', 'Connection and BIN catalog verified. Issuing and request signing have not been tested.');
    }

    public function store(Request $request, SaveCardProviderReferenceAction $save)
    {
        if ($request->has('environment')) {
            DB::transaction(function () use ($request, $save): void {
                $row = $save->execute(null, $request->only(['name', 'request_id']) + ['reference_balance' => '0.00'], $request->user('platform_admin'));
                app(PhotonPayAccounts::class)->save($row->id, $request->all() + ['version' => $row->version], $request->user('platform_admin'));
            });

            return back()->with('success', 'PhotonPay account saved.');
        }
        $save->execute(null, $request->only(['name', 'reference_balance', 'request_id', 'version', 'balance', 'asset', 'tenant_id', 'runtime_driver']), $request->user('platform_admin'), $request->attributes->get('request_id'));

        return back()->with('success', 'Card provider reference saved.');
    }

    public function update(string $reference, Request $request, SaveCardProviderReferenceAction $save)
    {
        if ($request->has('environment')) {
            app(PhotonPayAccounts::class)->save($reference, $request->all(), $request->user('platform_admin'));
            if ($request->boolean('check_connection')) {
                app(PhotonPayAccounts::class)->check($reference, $request->user('platform_admin'));

                return back()->with('success', 'Connection and BIN catalog verified. Issuing and request signing have not been tested.');
            }

            return back()->with('success', 'PhotonPay account saved.');
        }
        $save->execute($reference, $request->only(['name', 'reference_balance', 'request_id', 'version', 'balance', 'asset', 'tenant_id', 'runtime_driver']), $request->user('platform_admin'), $request->attributes->get('request_id'));

        return back()->with('success', 'Card provider reference saved.');
    }
}
