<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Promotion\AdminPromotionQuery;
use App\Application\Promotion\CompanyFundBookQuery;
use App\Application\Promotion\ConfigurePromotionAction;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConfigurePromotionRequest;
use App\Http\Requests\PromotionDateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class PromotionController extends Controller
{
    public function show(PromotionDateRequest $request, TenantContext $context, AdminPromotionQuery $query): Response
    {
        return Inertia::render('tenant-admin/Promotion', ['promotion' => $query->execute($context->id(), $request->validated('account_id'), $request->integer('page', 1))]);
    }

    public function funds(PromotionDateRequest $request, TenantContext $context, CompanyFundBookQuery $query): Response
    {
        return Inertia::render('tenant-admin/CompanyFunds', ['book' => $query->execute($context->id(), $request->validated('date'), $request->integer('page', 1))]);
    }

    public function update(ConfigurePromotionRequest $request, TenantContext $context, ConfigurePromotionAction $configure, AdminPromotionQuery $query): RedirectResponse
    {
        $actorId = $request->user('tenant_admin')->id;
        if ($request->validated('action') === 'level') {
            $configure->level($context->id(), $actorId, $request->integer('rank'), $request->validated('name'), $request->validated('reward'), $request->validated('revision'));
        } else {
            $configure->memberLevel($context->id(), $actorId, $query->userId($context->id(), $request->validated('account_id')), $request->validated('level_id'));
        }

        return back()->with('success', 'Promotion update completed.');
    }
}
