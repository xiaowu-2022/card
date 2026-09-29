<?php

namespace App\Http\Controllers\User;

use App\Application\Card\ReadUnissuedCardholderMaterialsQuery;
use App\Application\Card\SubmitProviderCardholderAction;
use App\Application\Card\SyncProviderCardholderAction;
use App\Domain\Card\Enums\ProviderCardholderStatus;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\SecurityDeposit\Services\RefundCardPolicy;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitCardSetupRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class CardSetupController extends Controller
{
    public function details(string $application, Request $request, TenantContext $context, ReadUnissuedCardholderMaterialsQuery $query): JsonResponse
    {
        return response()->json(['fields' => $query->execute($context->id(), $request->user('tenant_user')->id, $application)])
            ->header('Cache-Control', 'private, no-store, max-age=0')->header('Pragma', 'no-cache')->header('X-Content-Type-Options', 'nosniff');
    }

    public function store(SubmitCardSetupRequest $request, TenantContext $context, SubmitProviderCardholderAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $holder = $action->execute($context->id(), $user->id, $request->validated(), $request->attributes->get('request_id'));

        return $this->submissionResponse($holder);
    }

    public function sync(string $application, Request $request, TenantContext $context, SyncProviderCardholderAction $action): RedirectResponse
    {
        RefundCardPolicy::assertAllowed($context->id(), $request->user('tenant_user')->id);
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $holder = $action->execute($context->id(), $user->id, $application, $request->attributes->get('request_id'));

        return $this->submissionResponse($holder);
    }

    private function submissionResponse(ProviderCardholder $holder): RedirectResponse
    {
        if ($holder->status === ProviderCardholderStatus::Ready && $holder->provider_cardholder_id !== null) {
            return back();
        }

        return back()->withErrors(['form' => match ($holder->status) {
            ProviderCardholderStatus::Rejected, ProviderCardholderStatus::Disabled => 'The cardholder could not be added. Check the details and try again.',
            ProviderCardholderStatus::ActionRequired => 'Update the requested card setup information.',
            default => 'The cardholder addition could not be confirmed. Do not submit it again.',
        }]);
    }
}
