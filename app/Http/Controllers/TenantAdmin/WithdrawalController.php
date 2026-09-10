<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Admin\TenantAdminRecentAuthentication;
use App\Application\Withdrawal\ApproveWithdrawalAction;
use App\Application\Withdrawal\RejectWithdrawalAction;
use App\Application\Withdrawal\RevealWithdrawalAddressAction;
use App\Application\Withdrawal\TenantAdminWithdrawalQuery;
use App\Application\Withdrawal\VerifyWithdrawalTransactionAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\TenantContext;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\RejectWithdrawalRequest;
use App\Http\Requests\VerifyWithdrawalTransactionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class WithdrawalController extends Controller
{
    public function index(TenantContext $context, TenantAdminWithdrawalQuery $query): Response
    {
        return Inertia::render('tenant-admin/Withdrawals', $query->list($context->id()));
    }

    public function show(string $withdrawal, Request $request, TenantContext $context, TenantAdminWithdrawalQuery $query, TenantAdminRecentAuthentication $recent, BlockchainGatewayInterface $gateway): Response
    {
        /** @var AdminUser $admin */ $admin = Auth::guard('tenant_admin')->user();
        $revealed = $request->session()->get('withdrawal_address_reveal');

        return Inertia::render('tenant-admin/WithdrawalDetail', $query->detail($context->id(), $withdrawal) + [
            'recentlyAuthenticated' => $recent->valid($request->session(), $admin, $context->id()),
            'revealedAddress' => is_array($revealed) && ($revealed['order_id'] ?? null) === $withdrawal ? ($revealed['address'] ?? null) : null,
            'verificationAvailable' => $gateway->available(),
        ]);
    }

    public function approve(string $withdrawal, Request $request, TenantContext $context, ApproveWithdrawalAction $action): RedirectResponse
    {
        /** @var AdminUser $admin */ $admin = Auth::guard('tenant_admin')->user();
        $action->execute($context->id(), $withdrawal, $admin, $request->attributes->get('request_id'));

        return back()->with('success', 'Withdrawal approved. The held amount was not changed.');
    }

    public function reject(string $withdrawal, RejectWithdrawalRequest $request, TenantContext $context, RejectWithdrawalAction $action): RedirectResponse
    {
        /** @var AdminUser $admin */ $admin = Auth::guard('tenant_admin')->user();
        $action->execute($context->id(), $withdrawal, $admin, $request->string('reason')->toString(), $request->attributes->get('request_id'));

        return back()->with('success', 'Withdrawal rejected and the exact hold was returned.');
    }

    public function reveal(string $withdrawal, Request $request, TenantContext $context, RevealWithdrawalAddressAction $action): RedirectResponse
    {
        /** @var AdminUser $admin */ $admin = Auth::guard('tenant_admin')->user();
        $address = $action->execute($context->id(), $withdrawal, $admin, $request->attributes->get('request_id'));

        return back()->with('withdrawal_address_reveal', ['order_id' => $withdrawal, 'address' => $address]);
    }

    public function verify(string $withdrawal, VerifyWithdrawalTransactionRequest $request, TenantContext $context, VerifyWithdrawalTransactionAction $action): RedirectResponse
    {
        /** @var AdminUser $admin */ $admin = Auth::guard('tenant_admin')->user();
        $order = $action->execute($context->id(), $withdrawal, $admin, $request->string('tx_hash')->toString(), $request->attributes->get('request_id'));

        return back()->with('success', $order->status->value === 'SUCCEEDED' ? 'Transaction confirmed and withdrawal settled.' : 'Transaction verification updated.');
    }
}
