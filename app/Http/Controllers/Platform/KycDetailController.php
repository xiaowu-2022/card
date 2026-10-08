<?php

namespace App\Http\Controllers\Platform;

use App\Application\Admin\PlatformAdminRecentAuthentication;
use App\Application\Kyc\TenantKycQueueQuery;
use App\Application\Media\ImagePresentation;
use App\Application\Media\ImageStorage;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Infrastructure\Storage\OssImages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class KycDetailController extends Controller
{
    public function retry(Tenant $tenant, string $kyc, Request $request, \App\Application\Kyc\RetryKycProcessing $action)
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'reason' => 'required|string|min:3|max:500|not_regex:/[<>]/']);
        $application = $action->execute($tenant->id, $kyc, $request->user('platform_admin'), $data['request_id'], $data['reason']);
        return response()->json(['applicationId' => $application->id, 'processingStatus' => $application->processing_status], 202);
    }

    public function review(Tenant $tenant, string $kyc, Request $request)
    {
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'identity_number' => 'nullable|string|max:128', 'reason_code' => ['required_if:decision,reject', \Illuminate\Validation\Rule::enum(\App\Domain\Kyc\Enums\KycReviewReason::class)],
            'review_message' => 'required_if:decision,reject|string|min:3|max:500|not_regex:/[<>]/']);
        if ($data['decision'] === 'approve') {
            app(\App\Application\Kyc\ApproveKycAction::class)->execute($tenant->id, $kyc, $request->user('platform_admin'), $request->attributes->get('request_id'), $data['identity_number'] ?? null);
        } else {
            app(\App\Application\Kyc\RejectKycAction::class)->execute($tenant->id, $kyc, $request->user('platform_admin'),
                \App\Domain\Kyc\Enums\KycReviewReason::from($data['reason_code']), $data['review_message'], $request->attributes->get('request_id'));
        }
        return response()->json(['reviewed' => true]);
    }

    public function user(Tenant $tenant, string $user, Request $request, TenantKycQueueQuery $query, AuthorizationService $authorization)
    {
        $data = $request->validate(['application' => 'nullable|uuid', 'page' => 'nullable|integer|min:1|max:100000']);
        $member = User::where('tenant_id', $tenant->id)->with('profile')->findOrFail($user);
        $applications = KycApplication::where('tenant_id', $tenant->id)->where('user_id', $member->id);
        $page = (clone $applications)->orderByDesc('submitted_at')->orderByDesc('id')->paginate(20, ['id', 'review_status', 'submitted_at']);
        $selected = isset($data['application'])
            ? (clone $applications)->whereKey($data['application'])->firstOrFail(['id'])
            : $page->first();

        return response()->json([
            'company' => ['id' => $tenant->id, 'name' => $tenant->name],
            'user' => ['id' => $member->id, 'displayName' => $member->profile?->display_name, 'email' => $member->email],
            'platformVerified' => \Illuminate\Support\Facades\DB::table('platform_user_creations')->where('tenant_id', $tenant->id)->where('user_id', $member->id)->exists(),
            'applications' => ['items' => $page->map(fn ($application) => [
                'id' => $application->id, 'reviewStatus' => $application->review_status->value,
                'submittedAt' => $application->submitted_at->toIso8601String(),
            ]), 'page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
            'application' => $selected ? $query->detail($tenant->id, $selected->id)['application'] : null,
            'canViewDocuments' => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, 'kyc.document.view'),
            'canReview' => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, 'kyc.review'),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Tenant $tenant, string $kyc, Request $request, TenantKycQueueQuery $query, AuthorizationService $authorization)
    {
        $detail = [
            ...$query->detail($tenant->id, $kyc),
            'company' => ['id' => $tenant->id, 'name' => $tenant->name],
            'canViewDocuments' => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, 'kyc.document.view'),
            'canReview' => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, 'kyc.review'),
        ];
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($detail)->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('platform/KycDetail', $detail)->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function access(Tenant $tenant, string $kyc, Request $request, PlatformAdminRecentAuthentication $recent, AuditLogger $audit)
    {
        $application = KycApplication::where('tenant_id', $tenant->id)->findOrFail($kyc);
        $admin = $request->user('platform_admin');
        if (! $recent->valid($request->session(), $admin, $tenant->id)) {
            $input = $request->validate(['password' => ['required', 'string', 'max:255']]);
            if (! Hash::check($input['password'], $admin->password)) {
                throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
            }
            $recent->mark($request->session(), $admin, $tenant->id);
        }
        $documents = [];
        $documentSources = [];
        foreach (['front', 'back'] as $side) {
            if (! $application->{$side.'_object_key'}) {
                continue;
            }
            $audit->record($tenant->id, 'ADMIN', $admin->id, 'KYC_DOCUMENT_VIEWED', 'kyc_application', $application->id, null, ['document_side' => strtoupper($side)], $request->attributes->get('request_id'));
            $storage = app(ImageStorage::class);
            $image = $storage->record((string) config('kyc.document_disk'), $application->{$side.'_object_key'});
            abort_if($image && ($image->tenant_id !== $tenant->id || $image->state !== 'ready'), 404);
            $config = $storage->active();
            // Public-read OSS images load in the browser; never proxy them through
            // the server's unreliable OSS connection just to display a document.
            if ($config && $image) {
                $url = app(OssImages::class)->url($config, $image->object_key);
                $process = ImagePresentation::process('document', $image->mime);
                $documents[$side] = $url.($process ? '?x-oss-process='.rawurlencode($process) : '');
                $documentSources[$side] = array_values(array_unique([$documents[$side], $url, URL::temporarySignedRoute('platform.kyc.documents.show', now()->addSeconds((int) config('kyc.document_access_ttl_seconds')), ['tenant' => $tenant->id, 'kyc' => $kyc, 'side' => $side, 'viewer' => $admin->id, 'delivery' => 'replica'])]));

                continue;
            }
            $documents[$side] = URL::temporarySignedRoute('platform.kyc.documents.show', now()->addSeconds((int) config('kyc.document_access_ttl_seconds')), ['tenant' => $tenant->id, 'kyc' => $kyc, 'side' => $side, 'viewer' => $admin->id]);
        }

        return response()->json(['documents' => $documents, 'documentSources' => $documentSources])->header('Cache-Control', 'private, no-store');
    }

    public function image(Tenant $tenant, string $kyc, string $side, Request $request, PlatformAdminRecentAuthentication $recent)
    {
        $admin = $request->user('platform_admin');
        abort_unless($request->query('viewer') === $admin->id && $recent->valid($request->session(), $admin, $tenant->id), 403);
        $application = KycApplication::where('tenant_id', $tenant->id)->findOrFail($kyc);
        $key = $application->{$side.'_object_key'};
        abort_unless(is_string($key) && $key !== '', 404);
        abort_unless(in_array($request->query('delivery'), [null, 'replica'], true), 404);
        try {
            return app(ImageStorage::class)->displayResponse((string) config('kyc.document_disk'), $key, 'document', replicaOnly: $request->query('delivery') === 'replica')
                ->header('Cache-Control', 'private, no-store')->header('Pragma', 'no-cache');
        } catch (\Throwable) {
            abort(503, 'Identity document is unavailable.');
        }
    }
}
