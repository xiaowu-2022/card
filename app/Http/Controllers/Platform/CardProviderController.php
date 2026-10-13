<?php

namespace App\Http\Controllers\Platform;

use App\Application\CardProviderDirectory\CardProviderReferenceQuery;
use App\Application\CardProviderDirectory\DeleteCardProviderReferenceAction;
use App\Application\CardProviderDirectory\PhotonPayAccounts;
use App\Application\CardProviderDirectory\SaveCardProviderReferenceAction;
use App\Application\CardProviderDirectory\U1Accounts;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class CardProviderController extends Controller
{
    public function __invoke(Request $request, CardProviderReferenceQuery $query): Response|RedirectResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        $result = $query->list();
        if ($result['providers']->currentPage() > $result['providers']->lastPage()) {
            return redirect()->route('platform.card-providers.index', ['page' => $result['providers']->lastPage()]);
        }

        return Inertia::render('platform/CardProviders', $result);
    }

    public function check(string $reference, Request $request, PhotonPayAccounts $accounts)
    {
        if (DB::table('u1_connections')->where('reference_id', $reference)->exists()) {
            app(U1Accounts::class)->check($reference, $request->user('platform_admin'));

            return $request->expectsJson() ? response()->json(['checked' => true])->header('Cache-Control', 'private, no-store')
                : back()->with('success', 'U1 account and card groups verified.');
        }
        $accounts->check($reference, $request->user('platform_admin'));

        return back()->with('success', 'Connection and BIN catalog verified. Issuing and request signing have not been tested.');
    }

    public function destroy(string $reference, Request $request, DeleteCardProviderReferenceAction $delete)
    {
        $delete->execute($reference, $request->only(['version', 'confirmed', 'request_id']), $request->user('platform_admin'));

        return back()->with('success', 'Card provider deleted.');
    }

    public function store(Request $request, SaveCardProviderReferenceAction $save)
    {
        if ($request->input('provider_type') === 'U1') {
            app(U1Accounts::class)->save(null, $request->all(), $request->user('platform_admin'));

            return back()->with('success', 'U1 account saved.');
        }
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
        if ($request->input('provider_type') === 'U1') {
            app(U1Accounts::class)->save($reference, $request->all(), $request->user('platform_admin'));

            return back()->with('success', 'U1 account saved.');
        }
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
