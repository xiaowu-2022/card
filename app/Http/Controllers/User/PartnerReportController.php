<?php

namespace App\Http\Controllers\User;

use App\Application\Partners\PartnerHierarchy;
use App\Application\Partners\PartnerReport;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class PartnerReportController extends Controller
{
    public function hierarchy(Request $request, TenantContext $context, PartnerHierarchy $query, ?string $partner = null)
    {
        $request->validate(['page' => 'nullable|integer|min:1|max:100000', 'flow' => 'nullable|in:inflow,outflow', 'flow_page' => 'nullable|integer|min:1|max:100000', 'user_id' => 'prohibited', 'tenant_id' => 'prohibited', 'partner' => 'prohibited']);
        $view = str_ends_with($request->path(), '/report') ? 'report' : 'children';
        $data = $query->read($context->id(), $request->user('tenant_user')->id, $partner, $view, $request->integer('page', 1), $request->query('flow'), $request->integer('flow_page', 1));
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render($view === 'report' ? 'user/PartnerStock' : 'user/PartnerChildren', $view === 'report' ? $data : ['partners' => $data])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function __invoke(Request $request, TenantContext $context, PartnerReport $query)
    {
        // No client-provided tenant/partner identity is accepted, including on JSON requests.
        $request->validate(['page' => 'nullable|integer|min:1', 'flow' => 'nullable|in:inflow,outflow', 'flow_page' => 'nullable|integer|min:1|max:100000', 'partner' => 'prohibited', 'user_id' => 'prohibited', 'tenant_id' => 'prohibited']);
        $report = $query->read($context->id(), $request->user('tenant_user')->id, true, $request->integer('page', 1), $request->query('flow'), $request->integer('flow_page', 1));
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($report)->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('user/PartnerStock', ['report' => $report])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }
}
