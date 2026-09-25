<?php

namespace App\Http\Controllers\User;

use App\Application\Partners\PartnerReport;
use App\Application\Promotion\PromotionQuery;
use App\Application\Promotion\PromotionReportQuery;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\PromotionDateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class PromotionController extends Controller
{
    public function show(PromotionDateRequest $request, TenantContext $context, PromotionQuery $query, string $section = 'overview'): Response|HttpResponse
    {
        if ($section === 'team') {
            return redirect('/promotion/invitations');
        }
        if (in_array($section, ['overview', 'rules'], true)) {
            return Inertia::render('user/PromotionHub', [
                'home' => $query->home($context->id(), $request->user('tenant_user')->id),
                'section' => $section,
            ]);
        }

        if (in_array($section, ['daily', 'direct'], true)) {
            $reports = app(PromotionReportQuery::class);

            return Inertia::render('user/PromotionReport', ['canViewStock' => app(PartnerReport::class)->enabled($context->id(), $request->user('tenant_user')->id), 'section' => $section, 'report' => $section === 'daily'
                ? $reports->daily($context->id(), $request->user('tenant_user')->id, $request->validated())
                : $reports->members($context->id(), $request->user('tenant_user')->id, $request->validated())])
                ->toResponse($request)->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('user/Promotion', ['promotion' => $query->execute($context->id(), $request->user('tenant_user')->id,
            $request->validated('date'), $request->integer('page', 1), $request->integer('direct_page', 1), $request->validated('account_id'), $request->validated('funding', 'all')), 'section' => $section]);
    }

    public function commissions(PromotionDateRequest $request, TenantContext $context, PromotionReportQuery $query): HttpResponse
    {
        return Inertia::render('user/PromotionCommissions', ['history' => $query->commissions($context->id(), $request->user('tenant_user')->id, $request->validated())])
            ->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function memberTeam(Request $request, TenantContext $context, PromotionReportQuery $query, string $member): JsonResponse
    {
        $data = $request->validate(['subject' => 'nullable|string|max:100']);

        return response()->json($query->memberTeam($context->id(), $request->user('tenant_user')->id, $member, $data['subject'] ?? null))
            ->header('Cache-Control', 'private, no-store');
    }
}
