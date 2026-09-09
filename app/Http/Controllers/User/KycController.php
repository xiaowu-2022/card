<?php

namespace App\Http\Controllers\User;

use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Kyc\UserKycQuery;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitKycApplicationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class KycController extends Controller
{
    public function show(TenantContext $context, UserKycQuery $query): Response
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $tenant = $context->tenant();
        $kyc = $query->get($tenant->id, $user->id);

        return Inertia::render('user/Kyc', [
            'kyc' => $kyc,
            'canSubmit' => $tenant->status === TenantStatus::Active && $user->status === UserStatus::Active && (bool) $tenant->kycSettings?->enabled && in_array($kyc['status'], ['NOT_SUBMITTED', 'RESUBMISSION_REQUIRED'], true),
            'maxDocumentMb' => (int) config('kyc.document_max_mb'),
        ]);
    }

    public function store(SubmitKycApplicationRequest $request, TenantContext $context, SubmitKycApplicationAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $validated = $request->validated();
        $action->execute($context->tenant(), $user, $validated['document_country'], $validated['identity_number'], $validated['front'], $validated['back'], $request->attributes->get('request_id'));

        return redirect('/kyc')->with('success', 'Your identity documents were submitted for review.');
    }
}
