<?php

namespace App\Http\Controllers\Platform\CompanyConfiguration;

use App\Application\Promotion\ConfigurePaidPromotion;
use App\Application\Promotion\PaidPromotionQuery;
use App\Application\Promotion\PaidPromotionRules;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class PaidPromotionController extends Controller
{
    public function show(Tenant $tenant, Request $request, PaidPromotionQuery $query, PaidPromotionRules $rules)
    {
        $rules->platform($request->user('platform_admin'), 'tenant.manage');
        $page = max(1, min(100000, $request->integer('page', 1)));

        return Inertia::render('platform/PaidPromotion', ['paid' => $query->platform($tenant->id, $page)]);
    }

    public function configure(Tenant $tenant, Request $request, ConfigurePaidPromotion $configure, string $level)
    {
        $v = $request->validate(['fee' => ['required', 'string', 'regex:/^[1-9][0-9]{0,11}(?:\.[0-9]{1,8})?$/D'],
            'percent' => ['required', 'integer', 'between:0,100'], 'reward' => ['required', 'integer', 'between:20,1000000'], 'target' => ['required', 'integer', 'between:1,100000000'],
            'revision' => ['required', 'integer', 'min:1'], 'enabled' => ['required', 'boolean']]);
        $configure->execute($tenant->id, $request->user('platform_admin'), $level, $v);

        return back()->with('success', 'Promotion update completed.');
    }
}
