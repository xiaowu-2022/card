<?php

namespace App\Http\Controllers\User;

use App\Application\Card\SubmitProviderCardholderAction;
use App\Application\Card\SyncProviderCardholderAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitCardSetupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class CardSetupController extends Controller
{
    public function store(SubmitCardSetupRequest $request, TenantContext $context, SubmitProviderCardholderAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $action->execute($context->id(), $user->id, $request->validated(), $request->attributes->get('request_id'));

        return back()->with('success', 'Card setup submitted. We will show the provider review status here.');
    }

    public function sync(Request $request, TenantContext $context, SyncProviderCardholderAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $action->execute($context->id(), $user->id, $request->attributes->get('request_id'));

        return back()->with('success', 'Card setup status refreshed.');
    }
}
