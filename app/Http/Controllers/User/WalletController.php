<?php

namespace App\Http\Controllers\User;

use App\Application\Wallet\ActivateUserWalletAction;
use App\Application\Wallet\UserWalletQuery;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class WalletController extends Controller
{
    public function show(TenantContext $context, UserWalletQuery $query): Response
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();

        return Inertia::render('user/Wallet', $query->get($context->id(), $user->id));
    }

    public function activate(Request $request, TenantContext $context, ActivateUserWalletAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $result = $action->execute($context->id(), $user->id, $request->attributes->get('request_id'));

        return back()->with('success', $result->created ? 'Wallet activated.' : 'Your wallet is already active.');
    }
}
