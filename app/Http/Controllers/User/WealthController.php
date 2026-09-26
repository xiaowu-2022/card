<?php

namespace App\Http\Controllers\User;

use App\Application\Wealth\WealthOverview;
use App\Application\Wealth\WealthQuery;
use App\Application\Wealth\WealthService;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class WealthController extends Controller
{
    public function index(Request $request, TenantContext $context, WealthOverview $query)
    {
        return Inertia::render('user/WealthOverview', $query->get($context->id(), $request->user('tenant_user')->id));
    }

    public function asset(Request $request, TenantContext $context, WealthQuery $query, string $asset)
    {
        $request->validate(['page' => 'sometimes|integer|min:1|max:100000', 'view' => 'sometimes|in:details,deposit,withdraw']);

        return Inertia::render('user/Wealth', $query->page($context->id(), $request->user('tenant_user')->id, $asset, $request->input('view', 'deposit')));
    }

    public function show(Request $request, TenantContext $context, WealthQuery $query, string $order)
    {
        $request->validate(['view' => 'sometimes|in:details,withdraw']);

        return Inertia::render('user/WealthOrder', ['startWithdrawal' => $request->input('view') === 'withdraw', 'order' => $query->detail($context->id(), $request->user('tenant_user')->id, $order)]);
    }

    public function store(Request $request, TenantContext $context, WealthService $service)
    {
        $d = $request->validate(['asset' => 'required|in:USDT,USDC,ETH,BTC', 'amount' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,18})?$/D'], 'months' => 'required|integer|in:1,3,6,12,24,36,60', 'revision' => 'required|uuid', 'request_id' => 'required|uuid', 'confirmed' => 'required|accepted', 'tenant_id' => 'prohibited', 'user_id' => 'prohibited', 'wallet_id' => 'prohibited', 'rate' => 'prohibited']);
        $order = $service->deposit($context->id(), $request->user('tenant_user')->id, $d['asset'], $d['amount'], $d['months'], $d['revision'], $d['request_id']);

        return redirect('/wealth/orders/'.$order->id);
    }

    public function redeem(Request $request, TenantContext $context, WealthService $service, string $order)
    {
        $d = $request->validate(['request_id' => 'required|uuid', 'current_password' => 'required|string|max:1024',
            'confirmed' => 'required|accepted', 'tenant_id' => 'prohibited', 'user_id' => 'prohibited',
            'wallet_id' => 'prohibited', 'amount' => 'prohibited', 'asset' => 'prohibited', 'rate' => 'prohibited']);
        $service->redeem($context->id(), $request->user('tenant_user')->id, $order, $d['request_id'], $d['current_password']);

        return back();
    }

    public function cancel(Request $request, TenantContext $context, WealthService $service, string $order)
    {
        $d = $request->validate(['request_id' => 'required|uuid', 'current_password' => 'required|string|max:1024', 'confirmed' => 'required|accepted', 'expected_paid' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,18})?$/D'], 'tenant_id' => 'prohibited', 'user_id' => 'prohibited', 'wallet_id' => 'prohibited']);
        $service->cancel($context->id(), $request->user('tenant_user')->id, $order, $d['request_id'], $d['current_password'], $d['expected_paid']);

        return back();
    }
}
