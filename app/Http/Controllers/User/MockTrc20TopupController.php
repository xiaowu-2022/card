<?php

namespace App\Http\Controllers\User;

use App\Application\Payment\SimulateTrc20TopupAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

final class MockTrc20TopupController extends Controller
{
    public function store(string $topup, TenantContext $context, SimulateTrc20TopupAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $action->execute($context->id(), $user->id, $topup);

        return back();
    }
}
