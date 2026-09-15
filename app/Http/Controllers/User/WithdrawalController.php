<?php

namespace App\Http\Controllers\User;

use App\Application\Withdrawal\CancelWithdrawalAction;
use App\Application\Withdrawal\CreateWithdrawalAction;
use App\Application\Withdrawal\CreateWithdrawalDestinationAction;
use App\Application\Withdrawal\UserWithdrawalQuery;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateWithdrawalDestinationRequest;
use App\Http\Requests\CreateWithdrawalRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class WithdrawalController extends Controller
{
    public function create(Request $request, TenantContext $context, UserWithdrawalQuery $query): Response
    {
        /** @var User $user */ $user = Auth::guard('tenant_user')->user();

        return Inertia::render('user/Withdraw', $query->form($context->id(), $user->id));
    }

    public function index(Request $request, TenantContext $context, UserWithdrawalQuery $query): Response
    {
        /** @var User $user */ $user = Auth::guard('tenant_user')->user();
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return Inertia::render('user/WithdrawalHistory', $query->history($context->id(), $user->id, $request->integer('page', 1)));
    }

    public function storeDestination(CreateWithdrawalDestinationRequest $request, TenantContext $context, CreateWithdrawalDestinationAction $action): RedirectResponse
    {
        /** @var User $user */ $user = Auth::guard('tenant_user')->user();
        $action->execute($context->id(), $user->id, $request->string('address')->toString(), $request->input('label'), $request->attributes->get('request_id'));

        return back()->with('success', 'Withdrawal address added.');
    }

    public function store(CreateWithdrawalRequest $request, TenantContext $context, CreateWithdrawalAction $action): RedirectResponse
    {
        /** @var User $user */ $user = Auth::guard('tenant_user')->user();
        $order = $request->filled('address')
            ? $action->executeWithAddress($context->id(), $user->id, $request->string('request_id')->toString(), $request->string('address')->toString(), $request->input('amount'), $request->attributes->get('request_id'), $request->input('expected_fee'))
            : $action->execute($context->id(), $user->id, $request->string('request_id')->toString(), $request->string('destination_id')->toString(), $request->input('amount'), $request->attributes->get('request_id'), $request->input('expected_fee'));

        return redirect()->route('user.withdrawals.show', ['withdrawal' => $order->id]);
    }

    public function show(string $withdrawal, TenantContext $context, UserWithdrawalQuery $query): Response
    {
        /** @var User $user */ $user = Auth::guard('tenant_user')->user();

        return Inertia::render('user/WithdrawalStatus', $query->order($context->id(), $user->id, $withdrawal));
    }

    public function cancel(string $withdrawal, Request $request, TenantContext $context, CancelWithdrawalAction $action): RedirectResponse
    {
        /** @var User $user */ $user = Auth::guard('tenant_user')->user();
        $action->execute($context->id(), $user->id, $withdrawal, $request->attributes->get('request_id'));

        return back()->with('success', 'Withdrawal cancelled. Funds have been returned to your available balance.');
    }
}
