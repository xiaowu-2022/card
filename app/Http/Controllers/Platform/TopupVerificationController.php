<?php

namespace App\Http\Controllers\Platform;

use App\Application\Assets\PlatformFundsQuery;
use App\Application\Payment\ConfirmPlatformTopupAction;
use App\Application\Payment\VerifyPlatformTopupAction;
use App\Application\Tenant\PlatformListFilters;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class TopupVerificationController extends Controller
{
    public function index(Tenant $tenant, Request $request, PlatformFundsQuery $query, PlatformListFilters $lists)
    {
        $request->merge(['company' => $tenant->id]);

        return $this->all($request, $query, $lists);
    }

    public function all(Request $request, PlatformFundsQuery $query, PlatformListFilters $lists)
    {
        return app(FundsOrdersController::class)->deposits($request, $query, $lists);
    }

    public function verify(Request $request, Tenant $tenant, string $topup, VerifyPlatformTopupAction $verify)
    {
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'tx_hash' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'], 'confirmed' => ['accepted']]);
        $result = $verify->execute($tenant->id, $topup, $data['tx_hash'], $data['request_id'], $request->user('platform_admin'));

        return back()->with('success', match ($result) {
            'CREDITED' => 'Chain verification passed. Payment credited.',
            'PAID' => 'Payment verified. Wallet credit is pending.',
            'CONFIRMING' => 'The transaction needs more confirmations. No wallet credit yet.',
            default => 'No matching transfer found. No funds were credited.',
        });
    }

    public function confirm(Request $request, Tenant $tenant, string $topup, ConfirmPlatformTopupAction $confirm)
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted'],
            'amount' => ['prohibited'], 'asset' => ['prohibited'], 'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'], 'wallet_id' => ['prohibited'], 'status' => ['prohibited'],
        ]);
        $confirm->execute($tenant->id, $topup, $data['request_id'], $request->user('platform_admin'), true);

        return back()->with('success', 'Top-up confirmed. The full order amount has been credited.');
    }
}
