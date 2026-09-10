<?php

namespace App\Http\Controllers\User;

use App\Application\Payment\CreateWalletTopupAction;
use App\Application\Payment\UserWalletTopupQuery;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateWalletTopupRequest;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class WalletTopupController extends Controller
{
    public function index(TenantContext $context, UserWalletTopupQuery $query): Response
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();

        return Inertia::render('user/Topup', $query->get($context->id(), $user->id));
    }

    public function store(CreateWalletTopupRequest $request, TenantContext $context, CreateWalletTopupAction $action): HttpResponse
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $result = $action->execute(
            $context->id(), $user->id, $request->string('wallet_id')->toString(), $request->input('amount'),
            $request->string('asset')->toString(), $request->string('request_id')->toString(),
            route('user.topups.return', ['topup' => '__ORDER__']), $request->attributes->get('request_id'),
        );
        if ($result->checkoutUrl) {
            return Inertia::location($result->checkoutUrl);
        }

        return redirect()->route('user.topups.return', ['topup' => $result->order->id]);
    }

    public function returned(string $topup, TenantContext $context, UserWalletTopupQuery $query): Response
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();

        return Inertia::render('user/TopupStatus', ['order' => $query->order($context->id(), $user->id, $topup)]);
    }
}
