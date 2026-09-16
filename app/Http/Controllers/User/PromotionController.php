<?php

namespace App\Http\Controllers\User;

use App\Application\Promotion\PromotionMembershipAction;
use App\Application\Promotion\PromotionQuery;
use App\Application\Promotion\PromotionReportQuery;
use App\Application\Promotion\TransferCommissionAction;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\PromotionDateRequest;
use App\Http\Requests\PromotionRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class PromotionController extends Controller
{
    public function show(PromotionDateRequest $request, TenantContext $context, PromotionQuery $query, string $section = 'overview'): Response|RedirectResponse
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

            return Inertia::render('user/PromotionReport', ['section' => $section, 'report' => $section === 'daily'
                ? $reports->daily($context->id(), $request->user('tenant_user')->id, $request->validated())
                : $reports->members($context->id(), $request->user('tenant_user')->id, $request->validated())]);
        }

        return Inertia::render('user/Promotion', ['promotion' => $query->execute($context->id(), $request->user('tenant_user')->id,
            $request->validated('date'), $request->integer('page', 1), $request->integer('direct_page', 1), $request->validated('account_id'), $request->validated('funding', 'all')), 'section' => $section]);
    }

    public function commissions(PromotionDateRequest $request, TenantContext $context, PromotionReportQuery $query): Response
    {
        return Inertia::render('user/PromotionCommissions', ['history' => $query->commissions($context->id(), $request->user('tenant_user')->id, $request->validated())]);
    }

    public function update(PromotionRequest $request, TenantContext $context, PromotionMembershipAction $members, TransferCommissionAction $transfer): RedirectResponse
    {
        if ($request->validated('action') === 'transfer') {
            $transfer->execute($context->id(), $request->user('tenant_user')->id, $request->validated('request_id'));
        } else {
            $members->assignDirectLevel($context->id(), $request->user('tenant_user')->id, $request->validated('member_id'), $request->validated('level_id'));
        }

        return back()->with('success', 'Promotion update completed.');
    }
}
