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
            $orders->where('status', 'ACTIVE')->where('matures_at', '>', now());
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

        return ['id' => $order->id, 'asset' => $order->asset_code, 'principal' => Money::of($order->principal, $order->asset_code)->amount(), 'rate' => $order->annual_rate, 'months' => $order->months, 'status' => $order->status, 'displayStatus' => $order->status === 'ACTIVE' && now()->greaterThanOrEqualTo($order->matures_at) ? 'AWAITING_SETTLEMENT' : $order->status, 'startedAt' => $order->started_at->toIso8601String(), 'maturesAt' => $order->matures_at->toIso8601String(), 'timezone' => $order->timezone, 'paid' => $paid, 'returnAmount' => Money::of($order->returned ?? (string) BigDecimal::of($order->principal)->minus($paid), $order->asset_code)->amount(), 'clawback' => $order->clawback === null ? null : Money::of($order->clawback, $order->asset_code)->amount(), 'closedAt' => $order->closed_at?->toIso8601String(), 'canCancel' => $order->status === 'ACTIVE' && now()->lessThan($order->matures_at)];
    }
}
