<?php

namespace App\Application\Wealth;

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Wealth\WealthOrder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class WealthQuery
{
    public function page(string $tenantId, string $userId, string $selectedAsset = 'USDT', string $view = 'deposit'): array
    {
        $settings = app(WealthConfiguration::class)->get($tenantId);
        $accounts = LedgerAccount::where('tenant_id', $tenantId)->where('user_id', $userId)->get();
        $clawbacks = WealthOrder::where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'CANCELLED')->selectRaw('asset_code, SUM(clawback) AS amount')->groupBy('asset_code')->pluck('amount', 'asset_code');
        foreach ($settings as &$setting) {
            $asset = $setting['asset'];
            $setting['available'] = $accounts->first(fn ($a) => $a->asset_code === $asset && $a->account_type->value === 'USER_AVAILABLE')?->balance ?? '0';
            $setting['principal'] = $accounts->first(fn ($a) => $a->asset_code === $asset && $a->account_type->value === 'USER_WEALTH_PRINCIPAL')?->balance ?? '0';
            $setting['interest'] = Money::of((string) DB::table('wealth_installments as i')->join('wealth_orders as o', 'o.id', '=', 'i.order_id')->where('i.tenant_id', $tenantId)->where('o.tenant_id', $tenantId)->where('o.user_id', $userId)->where('o.asset_code', $asset)->whereNotNull('i.settled_at')->sum('i.amount'), $asset)->amount();
            $setting['net'] = Money::of((string) BigDecimal::of($setting['interest'])->minus($clawbacks[$asset] ?? '0'), $asset)->amount();
        }

        $orders = WealthOrder::where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', $selectedAsset);
        if ($view === 'withdraw') {
            $orders->where('status', 'ACTIVE')->where(fn ($q) => $q->where('matures_at', '>', now())
                ->orWhere(fn ($q) => $q->where('maturity_policy', WealthOrder::MANUAL_RENEW)->where('matures_at', '<=', now())->where('redeem_before', '>', now())));
        }

        return ['view' => $view, 'selectedAsset' => $selectedAsset, 'settings' => $settings, 'orders' => $orders->latest()->orderByDesc('id')->paginate(20)->appends(['view' => $view])->through(fn ($o) => $this->dto($o))];
    }

    public function detail(string $tenantId, string $userId, string $id): array
    {
        $order = WealthOrder::where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($id)->firstOrFail();

        return $this->dto($order) + ['schedule' => DB::table('wealth_installments')->where('tenant_id', $tenantId)->where('order_id', $id)->orderBy('month')->get(['month', 'due_at', 'amount', 'settled_at'])->map(fn ($r) => ['month' => $r->month, 'dueAt' => $r->due_at, 'amount' => Money::of($r->amount, $order->asset_code)->amount(), 'settledAt' => $r->settled_at])->all()];
    }

    private function dto(WealthOrder $order): array
    {
        $paid = app(WealthService::class)->paid($order);
        $at = now();
        $mature = $at->greaterThanOrEqualTo($order->matures_at);
        $canRedeem = $order->status === 'ACTIVE' && $order->maturity_policy === WealthOrder::MANUAL_RENEW
            && $mature && $at->lessThan($order->redeem_before);
        $display = $order->close_reason ?? ($order->status === 'ACTIVE' && $mature
            ? ($order->maturity_policy === null ? 'AWAITING_SETTLEMENT' : ($canRedeem ? 'REDEEMABLE' : 'RENEWAL_PENDING')) : $order->status);
        $nextId = WealthOrder::where('tenant_id', $order->tenant_id)->where('user_id', $order->user_id)->where('previous_order_id', $order->id)->value('id');

        return ['id' => $order->id, 'asset' => $order->asset_code, 'principal' => Money::of($order->principal, $order->asset_code)->amount(), 'rate' => $order->annual_rate, 'months' => $order->months, 'status' => $order->status, 'displayStatus' => $display, 'maturityPolicy' => $order->maturity_policy ?? 'AUTO_RETURN',
            'canRedeem' => $canRedeem, 'redeemBefore' => $order->redeem_before?->toIso8601String(),
            'redeemBeforeLocal' => $order->redeem_before?->setTimezone($order->timezone)->format('Y-m-d H:i:s'),
            'previousOrderId' => $order->previous_order_id, 'nextOrderId' => $nextId, 'startedAt' => $order->started_at->toIso8601String(), 'maturesAt' => $order->matures_at->toIso8601String(), 'timezone' => $order->timezone, 'paid' => $paid, 'returnAmount' => Money::of($order->returned ?? ($mature ? $order->principal : (string) BigDecimal::of($order->principal)->minus($paid)), $order->asset_code)->amount(), 'clawback' => $order->clawback === null ? null : Money::of($order->clawback, $order->asset_code)->amount(), 'closedAt' => $order->closed_at?->toIso8601String(), 'canCancel' => $order->status === 'ACTIVE' && now()->lessThan($order->matures_at)];
    }
}
