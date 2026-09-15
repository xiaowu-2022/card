<?php

namespace App\Http\Controllers\User;

use App\Application\Wallet\TransferWalletBalanceAction;
use App\Application\Wallet\WalletTransferQuery;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransferWalletBalanceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WalletTransferController extends Controller
{
    public function show(Request $request, TenantContext $context, WalletTransferQuery $query, ?string $transfer = null): Response
    {
        return Inertia::render('user/Transfer', $query->get($context->id(), $request->user('tenant_user')->id, $transfer));
    }

    public function store(TransferWalletBalanceRequest $request, TenantContext $context, TransferWalletBalanceAction $action): RedirectResponse
    {
        $transfer = $action->execute($context->id(), $request->user('tenant_user')->id, $request->validated('recipient_account_id'), $request->validated('amount'), $request->validated('request_id'));

        return redirect('/wallet/transfers/'.$transfer->id)->with('success', 'Transfer completed.');
    }
}
