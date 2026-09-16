<?php

namespace App\Http\Controllers\Platform\CompanyConfiguration;

use App\Application\Promotion\ConfigurePaidPromotion;
use App\Application\Promotion\PaidPromotionQuery;
use App\Application\Promotion\PaidPromotionRebate;
use App\Application\Promotion\PaidPromotionRules;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class PaidPromotionController extends Controller
{
    public function show(Tenant $tenant, Request $request, PaidPromotionQuery $query, PaidPromotionRules $rules)
    {
        $rules->platform($request->user('platform_admin'), 'tenant.manage');
        $page = max(1, min(100000, $request->integer('page', 1)));

        return Inertia::render('platform/PaidPromotion', ['paid' => $query->platform($tenant->id, $page) + [
            'canReview' => app(AuthorizationService::class)->allows($request->user('platform_admin'), ScopeType::Platform, null, 'promotion_refunds.review'),
        ]]);
    }

    public function configure(Tenant $tenant, Request $request, ConfigurePaidPromotion $configure, string $level)
    {
        $v = $request->validate(['fee' => ['required', 'string', 'regex:/^[1-9][0-9]{0,11}(?:\.[0-9]{1,8})?$/D'],
            'percent' => ['required', 'integer', 'between:0,100'], 'reward' => ['required', 'integer', 'between:20,1000000'], 'target' => ['required', 'integer', 'between:1,100000000'],
            'revision' => ['required', 'integer', 'min:1'], 'enabled' => ['required', 'boolean'], 'current_password' => ['required', 'current_password:platform_admin']]);
        $configure->execute($tenant->id, $request->user('platform_admin'), $level, $v);

        return back()->with('success', 'Promotion update completed.');
    }

    public function review(Tenant $tenant, Request $request, PaidPromotionRebate $rebates, string $rebate)
    {
        $v = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['nullable', 'string', 'max:300'],
            'current_password' => ['required', 'current_password:platform_admin'], 'confirmed' => ['required', 'accepted']]);
        $rebates->review($tenant->id, $rebate, $request->user('platform_admin'), $v['decision'] === 'approve', $v['reason'] ?? null);

        return back()->with('success', 'Fee rebate review completed.');
    }
}
