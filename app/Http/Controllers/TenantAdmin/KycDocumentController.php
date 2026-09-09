<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class KycDocumentController extends Controller
{
    public function access(string $kyc, string $side, Request $request, TenantContext $context, AuditLogger $audit): RedirectResponse
    {
        $application = KycApplication::query()->where('tenant_id', $context->id())->whereKey($kyc)->firstOrFail();
        /** @var AdminUser $admin */
        $admin = Auth::guard('tenant_admin')->user();
        $audit->record($context->id(), 'ADMIN', $admin->id, 'KYC_DOCUMENT_VIEWED', 'kyc_application', $application->id, null, ['document_side' => strtoupper($side)], $request->attributes->get('request_id'));
        $url = URL::temporarySignedRoute('tenant-admin.kyc.documents.show', now()->addSeconds((int) config('kyc.document_access_ttl_seconds')), ['kyc' => $application->id, 'side' => $side]);

        return redirect()->away($url);
    }

    public function show(string $kyc, string $side, TenantContext $context): StreamedResponse
    {
        $application = KycApplication::query()->where('tenant_id', $context->id())->whereKey($kyc)->firstOrFail();
        $key = $side === 'front' ? $application->front_object_key : $application->back_object_key;

        return Storage::disk((string) config('kyc.document_disk'))->response($key, "identity-{$side}", ['Cache-Control' => 'private, no-store']);
    }
}
