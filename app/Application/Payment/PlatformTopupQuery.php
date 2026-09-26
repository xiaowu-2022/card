<?php

namespace App\Application\Payment;

use App\Domain\Payment\Models\WalletTopupOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class PlatformTopupQuery
{
    public function paginate(?string $company, ?string $search, ?string $status): LengthAwarePaginator
    {
        return WalletTopupOrder::query()
            ->leftJoin('admin_users as confirmer', 'confirmer.id', '=', 'wallet_topup_orders.manual_confirmed_by')
            ->join('tenants as t', 't.id', '=', 'wallet_topup_orders.tenant_id')
            ->join('users as u', fn ($join) => $join->on('u.id', '=', 'wallet_topup_orders.user_id')->on('u.tenant_id', '=', 'wallet_topup_orders.tenant_id'))
            ->when($company, fn ($q) => $q->where('wallet_topup_orders.tenant_id', $company))
            ->when($status, fn ($q) => $q->where('wallet_topup_orders.status', $status))
            ->when($search, fn ($q) => $q->where(function ($q) use ($search): void {
                $pattern = '%'.addcslashes($search, '%_').'%';
                $q->where('u.email', 'ilike', $pattern)->orWhere('u.account_id', 'like', $pattern)
                    ->orWhereRaw("replace(wallet_topup_orders.id::text, '-', '') ilike ?", ['%'.str_replace('-', '', addcslashes($search, '%_')).'%']);
            }))
            ->select('wallet_topup_orders.*', 't.name as company_name', 'u.account_id', 'u.email as user_email', 'confirmer.name as confirmer_name')
            ->orderByDesc('wallet_topup_orders.created_at')->orderBy('wallet_topup_orders.id')
            ->paginate(25)->withQueryString()->through(fn (WalletTopupOrder $order): array => [
                'id' => $order->id, 'reference' => strtoupper(substr(str_replace('-', '', $order->id), 0, 12)),
                'companyId' => $order->tenant_id, 'companyName' => $order->company_name,
                'accountId' => $order->account_id, 'userEmail' => $order->user_email,
                'amount' => $order->amount, 'asset' => $order->asset_code, 'status' => $order->status->value,
                'network' => $order->network_code, 'manuallyConfirmed' => $order->manual_confirmed_at !== null,
                'manualConfirmedAt' => $order->manual_confirmed_at?->toIso8601String(),
                'manualConfirmedBy' => $order->manual_confirmed_by === null ? null : ['id' => $order->manual_confirmed_by, 'name' => $order->confirmer_name],
                'createdAt' => $order->created_at->toIso8601String(),
                'creditedAt' => $order->credited_at?->toIso8601String(),
            ]);
    }
}
