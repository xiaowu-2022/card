<?php

namespace App\Http\Controllers\User;

use App\Application\Wallet\TransferWalletBalanceAction;
use App\Application\Wallet\WalletTransferQuery;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransferWalletBalanceRequest;
use Illuminate\Http\JsonResponse;
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

    public function history(Request $request, TenantContext $context, WalletTransferQuery $query): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return response()->json($query->history($context->id(), $request->user('tenant_user')->id, (int) ($data['page'] ?? 1)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function recipient(Request $request, TenantContext $context, WalletTransferQuery $query): JsonResponse
    {
        $data = $request->validate(['recipient_account_id' => ['required', 'string', 'regex:/^[0-9]{12}$/D'], 'asset' => ['required', 'in:USDT,USDC,ETH,BTC']]);

        return response()->json($query->recipient($context->id(), $request->user('tenant_user')->id, $data['recipient_account_id'], $data['asset']))->header('Cache-Control', 'private, no-store');
    }

    public function store(TransferWalletBalanceRequest $request, TenantContext $context, TransferWalletBalanceAction $action): RedirectResponse
    {
        $transfer = $action->execute($context->id(), $request->user('tenant_user')->id, $request->validated('recipient_account_id'), $request->validated('amount'), $request->validated('request_id'), $request->validated('asset'));

        return redirect('/wallet/transfers/'.$transfer->id)->with('success', 'Transfer completed.');
    }
}
