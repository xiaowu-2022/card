<?php

namespace App\Http\Controllers\User;

use App\Application\Kyc\PreviewKycNumber;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Kyc\UserKycQuery;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecognizeKycFrontRequest;
use App\Http\Requests\SubmitKycApplicationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class KycController extends Controller
{
    public function recognizeFront(RecognizeKycFrontRequest $request, TenantContext $context, PreviewKycNumber $action): JsonResponse
    {
        $data = $request->validated();
        $user = Auth::guard('tenant_user')->user();

        return response()->json($action->execute($context->tenant(), $user, $data['front_upload_id'], KycDocumentType::from($data['document_type']), $data['document_country'], (bool) ($data['reverify'] ?? false)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, TenantContext $context, UserKycQuery $query): Response
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $tenant = $context->tenant();
        $kyc = $query->get($tenant->id, $user->id);

        return Inertia::render('user/Kyc', [
            'kyc' => $kyc,
            'backHref' => $request->query('from') === 'account-security' ? '/account/security' : '/account',
            'canSubmit' => $tenant->status === TenantStatus::Active && $user->status === UserStatus::Active && (bool) PlatformKycSetting::current()->enabled && in_array($kyc['status'], ['NOT_SUBMITTED', 'RESUBMISSION_REQUIRED'], true),
            'canReverify' => $tenant->status === TenantStatus::Active && $user->status === UserStatus::Active && (bool) PlatformKycSetting::current()->enabled && $kyc['status'] === 'APPROVED' && ! $kyc['reverificationPending'],
            'maxDocumentMb' => (int) config('kyc.document_max_mb'),
        ]);
    }

    public function store(SubmitKycApplicationRequest $request, TenantContext $context, SubmitKycApplicationAction $action): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $validated = $request->resolvedImages($context->id(), $user->id, 'kyc');
        $application = $action->execute($context->tenant(), $user, $validated['document_country'], '', $validated['front'], $validated['back'] ?? null, $request->attributes->get('request_id'), KycDocumentType::from($validated['document_type']), (bool) ($validated['reverify'] ?? false));

        if ($request->expectsJson()) {
            return response()->json(['applicationId' => $application->id, 'processingStatus' => $application->processing_status, 'success' => 'Your identity documents were submitted for review.'], 202);
        }

        return redirect($request->query('from') === 'account-security' ? '/kyc?from=account-security' : '/kyc')->with('success', $application->automatically_approved ? 'Your identity verification is complete.' : 'Your identity documents were submitted for review.');
    }
}
