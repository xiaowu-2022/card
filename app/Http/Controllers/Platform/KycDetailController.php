<?php

namespace App\Http\Controllers\Platform;

use App\Application\Admin\PlatformAdminRecentAuthentication;
use App\Application\Kyc\TenantKycQueueQuery;
use App\Application\Media\ImageStorage;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class KycDetailController extends Controller
{
    public function show(Tenant $tenant, string $kyc, Request $request, TenantKycQueueQuery $query, AuthorizationService $authorization)
    {
        return Inertia::render('platform/KycDetail', [
            ...$query->detail($tenant->id, $kyc),
            'company' => ['id' => $tenant->id, 'name' => $tenant->name],
            'canViewDocuments' => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, 'kyc.document.view'),
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
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
        foreach (['front', 'back'] as $side) {
            if (! $application->{$side.'_object_key'}) {
                continue;
            }
            $audit->record($tenant->id, 'ADMIN', $admin->id, 'KYC_DOCUMENT_VIEWED', 'kyc_application', $application->id, null, ['document_side' => strtoupper($side)], $request->attributes->get('request_id'));
            $documents[$side] = URL::temporarySignedRoute('platform.kyc.documents.show', now()->addSeconds((int) config('kyc.document_access_ttl_seconds')), ['tenant' => $tenant->id, 'kyc' => $kyc, 'side' => $side, 'viewer' => $admin->id]);
        }

        return response()->json(['documents' => $documents])->header('Cache-Control', 'private, no-store');
    }

    public function image(Tenant $tenant, string $kyc, string $side, Request $request, PlatformAdminRecentAuthentication $recent)
    {
        $admin = $request->user('platform_admin');
        abort_unless($request->query('viewer') === $admin->id && $recent->valid($request->session(), $admin, $tenant->id), 403);
        $application = KycApplication::where('tenant_id', $tenant->id)->findOrFail($kyc);
        $key = $application->{$side.'_object_key'};
        abort_unless(is_string($key) && $key !== '', 404);
        try {
            return app(ImageStorage::class)->displayResponse((string) config('kyc.document_disk'), $key, 'document')
                ->header('Cache-Control', 'private, no-store')->header('Pragma', 'no-cache');
        } catch (\Throwable) {
            abort(503, 'Identity document is unavailable.');
        }
    }
}
