<?php

namespace App\Http\Controllers\Platform;

use App\Application\Promotion\ManualPromotion;
use App\Application\Promotion\PaidPromotionQuery;
use App\Application\Promotion\PaidPromotionRules;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class UserPromotionController extends Controller
{
    public function show(Request $request, string $tenant, string $user, ManualPromotion $manual, PaidPromotionRules $rules): Response
    {
        $account = User::query()->where('tenant_id', $tenant)->whereKey($user)->firstOrFail();
        $company = Tenant::findOrFail($tenant);
        $canAdjust = app(AuthorizationService::class)->allows($request->user('platform_admin'), ScopeType::Platform, null, 'promotion_members.manage');

        return Inertia::render('platform/UserPromotion', [
            'account' => ['id' => $user, 'companyId' => $tenant, 'companyName' => $company->name, 'accountId' => $account->account_id, 'email' => $account->email],
            'currentRank' => $manual->benefit($tenant, $user)?->rank ?? 0,
            'manualLevel' => $manual->managed($tenant, $user), 'latestAdjustmentId' => $manual->latest($tenant, $user)?->id,
            'paidRank' => $rules->cycle($tenant, $user)?->rank ?? 0,
            'levels' => app(PaidPromotionQuery::class)->levels($tenant), 'canAdjust' => $canAdjust,
            'history' => DB::table('manual_promotion_adjustments')->where('tenant_id', $tenant)->where('user_id', $user)
                ->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString()
                ->through(fn ($r) => ['id' => $r->id, 'beforeRank' => $r->previous_rank, 'afterRank' => $r->effective_rank, 'manual' => $r->rank !== null,
                    'actor' => $r->actor_name, 'reason' => $r->reason, 'time' => $r->created_at]),
        ]);
    }

    public function update(Request $request, string $tenant, string $user, ManualPromotion $manual): RedirectResponse
    {
        $data = $request->validate(['choice' => ['required', 'string', 'max:40'], 'reason' => ['required', 'string', 'max:500'],
            'request_id' => ['required', 'uuid'], 'expected_adjustment_id' => ['nullable', 'uuid'], 'confirmed' => ['required', 'accepted']]);
        $manual->adjust($tenant, $user, $request->user('platform_admin'), $data['choice'], $data['reason'], $data['request_id'], $data['expected_adjustment_id'] ?? null);

        return back()->with('success', 'Promotion level adjusted.');
    }
}
