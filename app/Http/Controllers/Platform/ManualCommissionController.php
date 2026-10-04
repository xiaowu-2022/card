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
    public function show(string $tenant, string $user)
    {
        $account = User::where('tenant_id', $tenant)->whereKey($user)->firstOrFail();
        $balances = LedgerAccount::where('tenant_id', $tenant)->where('user_id', $user)->where('account_type', 'USER_AVAILABLE')->whereIn('asset_code', ['USDT'])->get()
            ->map(fn ($a) => ['asset' => $a->asset_code, 'amount' => Money::of($a->balance, $a->asset_code)->amount()]);

        return Inertia::render('platform/ManualCommission', [
            'account' => ['id' => $user, 'companyId' => $tenant, 'companyName' => Tenant::findOrFail($tenant)->name, 'accountId' => $account->account_id, 'email' => $account->email],
            'balances' => $balances,
            'commission' => app(PromotionReportQuery::class)->cumulative($tenant, $user),
            'manual' => Money::of((string) DB::table('manual_commission_adjustments')->where('tenant_id', $tenant)->where('user_id', $user)->sum(DB::raw("CASE WHEN direction='INCREASE' THEN amount ELSE -amount END")), 'USDT')->amount(),
            'history' => DB::table('manual_commission_adjustments')->where('tenant_id', $tenant)->where('user_id', $user)->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString()
                ->through(fn ($r) => ['id' => $r->id, 'asset' => $r->asset_code, 'direction' => $r->direction, 'amount' => Money::of($r->amount, $r->asset_code)->amount(), 'commissionBefore' => Money::of($r->commission_before, 'USDT')->amount(), 'commissionAfter' => Money::of($r->commission_after, 'USDT')->amount(), 'before' => Money::of($r->balance_before, $r->asset_code)->amount(), 'after' => Money::of($r->balance_after, $r->asset_code)->amount(), 'reason' => $r->reason, 'actor' => $r->actor_name, 'time' => $r->created_at]),
        ]);
    }

    public function update(Request $request, string $tenant, string $user, AdjustManualCommission $action)
    {
        $data = $request->validate(['asset' => ['required', 'in:USDT'], 'direction' => ['required', 'in:INCREASE,DECREASE'], 'amount' => ['required', 'string', 'max:40'], 'reason' => ['required', 'string', 'max:500'], 'request_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted']]);
        $action->execute($tenant, $user, $request->user('platform_admin'), $data);

        return back()->with('success', 'Manual commission adjusted.');
    }
}
