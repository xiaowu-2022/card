<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\RejectKycAction;
use App\Application\Kyc\RequireKycResubmissionAction;
use App\Application\Kyc\TenantKycQueueQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Enums\KycReviewReason;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewKycApplicationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class KycController extends Controller
{
    public function index(Request $request, TenantContext $context, TenantKycQueueQuery $query): Response
    {
        return Inertia::render('tenant-admin/KycQueue', [
            'applications' => $query->paginate($context->id(), $request->string('search')->trim()->value() ?: null, $request->string('status')->value() ?: null, $request->string('date')->value() ?: null),
            'filters' => $request->only(['search', 'status', 'date']),
        ]);
    }

    public function show(string $kyc, Request $request, TenantContext $context, TenantKycQueueQuery $query): Response
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('tenant_admin')->user();
        $recentAt = $request->session()->get("tenant_admin.recent_auth_at.{$admin->id}");

        return Inertia::render('tenant-admin/KycDetail', [
            ...$query->detail($context->id(), $kyc),
            'recentlyAuthenticated' => is_int($recentAt) && $recentAt >= now()->subSeconds((int) config('kyc.admin_recent_auth_ttl_seconds'))->getTimestamp(),
        ]);
    }

    public function approve(string $kyc, Request $request, TenantContext $context, ApproveKycAction $action): RedirectResponse
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('tenant_admin')->user();
        $action->execute($context->id(), $kyc, $admin, $request->attributes->get('request_id'));

        return back()->with('success', 'Identity verification approved.');
    }

    public function reject(string $kyc, ReviewKycApplicationRequest $request, TenantContext $context, RejectKycAction $action): RedirectResponse
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('tenant_admin')->user();
        $action->execute($context->id(), $kyc, $admin, KycReviewReason::from($request->validated('reason_code')), $request->validated('review_message'), $request->attributes->get('request_id'));

        return back()->with('success', 'Identity verification rejected.');
    }

    public function requireResubmission(string $kyc, ReviewKycApplicationRequest $request, TenantContext $context, RequireKycResubmissionAction $action): RedirectResponse
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('tenant_admin')->user();
        $action->execute($context->id(), $kyc, $admin, KycReviewReason::from($request->validated('reason_code')), $request->validated('review_message'), $request->attributes->get('request_id'));

        return back()->with('success', 'Resubmission requested.');
    }
}
