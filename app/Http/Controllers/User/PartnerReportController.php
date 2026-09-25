<?php

namespace App\Http\Controllers\User;

use App\Application\Partners\PartnerReport;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class PartnerReportController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, PartnerReport $query)
    {
        // No client-provided tenant/partner identity is accepted, including on JSON requests.
        abort_unless($query->enabled($context->id(), $request->user('tenant_user')->id), 404);
        $request->validate(['page' => 'nullable|integer|min:1', 'partner' => 'prohibited', 'user_id' => 'prohibited', 'tenant_id' => 'prohibited']);
        $report = $query->read($context->id(), $request->user('tenant_user')->id, true, $request->integer('page', 1));
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($report)->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('user/PartnerStock', ['report' => $report])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }
}
