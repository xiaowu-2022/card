<?php

namespace App\Http\Controllers\Platform;

use App\Application\Promotion\AdjustManualCommission;
use App\Application\Promotion\PromotionReportQuery;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class ManualCommissionController extends Controller
{
    public function show(Request $request, string $tenant, string $user)
    {
        $filters = $request->validate(['kind' => 'nullable|in:activation,annual,legacy,pending', 'page' => 'nullable|integer|min:1|max:100000']);
        $account = User::where('tenant_id', $tenant)->whereKey($user)->firstOrFail();
        $balances = LedgerAccount::where('tenant_id', $tenant)->where('user_id', $user)->where('account_type', 'USER_AVAILABLE')->whereIn('asset_code', ['USDT'])->get()
            ->map(fn ($a) => ['asset' => $a->asset_code, 'amount' => Money::of($a->balance, $a->asset_code)->amount()]);

        return Inertia::render('platform/ManualCommission', [
            'account' => ['id' => $user, 'companyId' => $tenant, 'companyName' => Tenant::findOrFail($tenant)->name, 'accountId' => $account->account_id, 'email' => $account->email],
            'balances' => $balances, 'filters' => $filters,
            'commission' => app(PromotionReportQuery::class)->cumulative($tenant, $user),
            'categories' => collect(['activation', 'annual', 'legacy'])->mapWithKeys(fn ($kind) => [$kind => app(AdjustManualCommission::class)->categoryNet($tenant, $user, $kind)->amount()]),
            'history' => DB::table('manual_commission_adjustments as a')
                ->leftJoin('commission_adjustment_classifications as c', fn ($j) => $j->on('c.adjustment_id', '=', 'a.id')->on('c.tenant_id', '=', 'a.tenant_id'))
                ->where('a.tenant_id', $tenant)->where('a.user_id', $user)
                ->when($filters['kind'] ?? null, fn ($q, $kind) => $kind === 'pending' ? $q->whereNull('c.kind') : $q->where('c.kind', $kind))->orderByDesc('a.created_at')->orderByDesc('a.id')
                ->paginate(20, ['a.*', 'c.kind', 'c.kind_before', 'c.kind_after', 'c.reason as classification_reason', 'c.actor_name as classifier', 'c.created_at as classified_at'])->withQueryString()
                ->through(fn ($r) => ['id' => $r->id, 'asset' => $r->asset_code, 'kind' => $r->kind, 'direction' => $r->direction, 'amount' => Money::of($r->amount, $r->asset_code)->amount(),
                    'kindBefore' => $r->kind_before === null ? null : Money::of($r->kind_before, 'USDT')->amount(), 'kindAfter' => $r->kind_after === null ? null : Money::of($r->kind_after, 'USDT')->amount(),
                    'commissionBefore' => Money::of($r->commission_before, 'USDT')->amount(), 'commissionAfter' => Money::of($r->commission_after, 'USDT')->amount(),
                    'before' => Money::of($r->balance_before, $r->asset_code)->amount(), 'after' => Money::of($r->balance_after, $r->asset_code)->amount(),
                    'reason' => $r->reason, 'actor' => $r->actor_name, 'time' => $r->created_at, 'classifier' => $r->classifier, 'classifiedAt' => $r->classified_at, 'classificationReason' => $r->classification_reason]),
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $tenant, string $user, AdjustManualCommission $action)
    {
        $data = $request->validate(['commission_type' => ['required', 'in:activation,annual,legacy'], 'asset' => ['required', 'in:USDT'], 'direction' => ['required', 'in:INCREASE,DECREASE'], 'amount' => ['required', 'string', 'max:40'], 'reason' => ['required', 'string', 'max:500'], 'request_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted']]);
        $action->execute($tenant, $user, $request->user('platform_admin'), $data);

        return back()->with('success', 'Commission adjusted.');
    }

    public function classify(Request $request, string $tenant, string $user, string $adjustment, AdjustManualCommission $action)
    {
        $data = $request->validate(['commission_type' => ['required', 'in:activation,annual,legacy'], 'reason' => ['required', 'string', 'max:500'], 'request_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted']]);
        $action->classify($tenant, $user, $adjustment, $request->user('platform_admin'), $data);

        return back()->with('success', 'Commission classified. No funds moved.');
    }
}
