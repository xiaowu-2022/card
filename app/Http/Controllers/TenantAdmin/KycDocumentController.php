<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

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

    public function show(string $kyc, string $side, TenantContext $context): Response
    {
        $application = KycApplication::query()->where('tenant_id', $context->id())->whereKey($kyc)->firstOrFail();
        $key = $side === 'front' ? $application->front_object_key : $application->back_object_key;
        abort_unless(is_string($key) && $key !== '', 404);
        $disk = Storage::disk((string) config('kyc.document_disk'));
        try {
            $contents = $disk->get($key);
        } catch (Throwable) {
            abort(404, 'Identity document is unavailable.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 415, 'The stored document type is not supported.');

        return response($contents, 200, [
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'Content-Type' => $mime,
            'Content-Disposition' => "inline; filename=identity-{$side}",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
