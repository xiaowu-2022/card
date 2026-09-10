<?php

namespace App\Http\Controllers\User;

use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\SecurityDeposit\SecurityDepositFundingQuery;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\FundSecurityDepositRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class SecurityDepositController extends Controller
{
    public function show(TenantContext $context, SecurityDepositFundingQuery $query): Response
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();

        return Inertia::render('user/SecurityDeposit', ['preview' => $query->preview($context->id(), $user->id)]);
    }

    public function fund(FundSecurityDepositRequest $request, TenantContext $context, FundSecurityDepositAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $receipt = $action->execute(
            $context->id(),
            $user->id,
            $request->string('request_id')->toString(),
            $request->input('expected_remaining'),
            $request->attributes->get('request_id'),
        );

        return redirect()->route('user.security-deposit.success')->with('security_deposit_receipt', $receipt->toArray());
    }

    public function success(Request $request): Response|RedirectResponse
    {
        $receipt = $request->session()->get('security_deposit_receipt');
        if (! is_array($receipt)) {
            return redirect()->route('user.wallet');
        }

        return Inertia::render('user/SecurityDepositSuccess', ['receipt' => $receipt]);
    }
}
