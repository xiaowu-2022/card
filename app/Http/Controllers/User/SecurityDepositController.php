<?php

namespace App\Http\Controllers\User;

use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\SecurityDeposit\RefundSecurityDepositAction;
use App\Application\SecurityDeposit\SecurityDepositFundingQuery;
use App\Application\SecurityDeposit\SecurityDepositHistoryQuery;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\FundSecurityDepositRequest;
use App\Http\Requests\RefundSecurityDepositRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class SecurityDepositController extends Controller
{
    public function refund(RefundSecurityDepositRequest $request, TenantContext $context, RefundSecurityDepositAction $refunds): RedirectResponse
    {
        $userId = $request->user('tenant_user')->id;
        match ($request->validated('action')) {
            'request' => $refunds->request($context->id(), $userId, $request->validated('request_id')),
            'cancel' => $refunds->cancel($context->id(), $userId, $request->validated('refund_id')),
        };

        return back()->with('success', 'Security deposit refund request updated.');
    }

    public function show(TenantContext $context, SecurityDepositFundingQuery $query, KycStatusService $kyc): Response|RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();

        if ($kyc->forUser($context->id(), $user->id) !== KycUserStatus::Approved) {
            return redirect('/kyc');
        }

        return Inertia::render('user/SecurityDeposit', ['preview' => $query->preview($context->id(), $user->id)]);
    }

    public function history(Request $request, TenantContext $context, SecurityDepositHistoryQuery $query): \Symfony\Component\HttpFoundation\Response
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);

        return Inertia::render('user/SecurityDepositHistory', [
            'history' => $query->execute($context->id(), $request->user('tenant_user')->id, (int) ($data['page'] ?? 1)),
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
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
            return redirect()->route('user.authenticated.dashboard');
        }

        return Inertia::render('user/SecurityDepositSuccess', ['receipt' => $receipt]);
    }
}
