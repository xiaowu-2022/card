<?php

namespace App\Http\Controllers\User;

use App\Application\Card\CreateCardIssueAction;
use App\Application\Card\SyncCardIssueAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCardIssueRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class CardIssueController extends Controller
{
    public function store(CreateCardIssueRequest $request, TenantContext $context, CreateCardIssueAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $action->execute(
            $context->id(),
            $user->id,
            $request->string('request_id')->toString(),
            $request->string('card_product_id')->toString(),
            $request->input('initial_load_amount'),
            $request->string('cardholder_application_id')->toString(),
            $request->attributes->get('request_id'),
            $request->input('form_factor', 'virtual_card'),
            $request->input('recipient_application_id'),
        );

        return back()->with('success', 'Card request submitted.');
    }

    public function sync(string $issue, Request $request, TenantContext $context, SyncCardIssueAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $action->execute($context->id(), $issue, $user->id);

        return back()->with('success', 'Card request status refreshed.');
    }
}
