<?php

namespace App\Http\Controllers\Platform\CompanyConfiguration;

use App\Application\Promotion\AdminPromotionQuery;
use App\Application\Promotion\CompanyFundBookQuery;
use App\Application\Promotion\ConfigurePromotionAction;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConfigurePromotionRequest;
use App\Http\Requests\PromotionDateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class PromotionController extends Controller
{
    public function show(Tenant $tenant, PromotionDateRequest $request, AdminPromotionQuery $query): Response
    {
        return Inertia::render('tenant-admin/Promotion', ['promotion' => $query->execute($tenant->id, $request->validated('account_id'), $request->integer('page', 1))]);
    }

    public function funds(Tenant $tenant, PromotionDateRequest $request, CompanyFundBookQuery $query): Response
    {
        return Inertia::render('tenant-admin/CompanyFunds', ['book' => $query->execute($tenant->id, $request->validated('date'), $request->integer('page', 1))]);
    }

    public function update(Tenant $tenant, ConfigurePromotionRequest $request, ConfigurePromotionAction $configure, AdminPromotionQuery $query): RedirectResponse
    {
        $actorId = $request->user('platform_admin')->id;
        if ($request->validated('action') === 'level') {
            $configure->level($tenant->id, $actorId, $request->integer('rank'), $request->validated('name'), $request->validated('reward'), $request->validated('revision'));
        } else {
            $configure->memberLevel($tenant->id, $actorId, $query->userId($tenant->id, $request->validated('account_id')), $request->validated('level_id'));
        }

        return back()->with('success', 'Promotion update completed.');
    }
}
