<?php

namespace App\Application\Payment;

use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Payment\Contracts\PaymentProviderInterface;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Domain\Wallet\Models\Wallet;

final readonly class UserWalletTopupQuery
{
    public function __construct(private PaymentProviderInterface $provider) {}

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $userId): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->with('businessSettings')->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->first();
        $available = $wallet ? LedgerAccount::query()->where('tenant_id', $tenantId)->where('wallet_id', $wallet->id)
            ->where('account_type', LedgerAccountType::UserAvailable->value)->first() : null;
        $enabled = $tenant->status->value === 'ACTIVE' && $user->status->value === 'ACTIVE'
            && $wallet?->status === WalletStatus::Active && $tenant->businessSettings->allow_wallet_topup && $this->provider->available();

        return [
            'available' => $available ? ['amount' => $available->balance, 'asset' => $available->asset_code] : null,
            'wallet' => $wallet ? ['id' => $wallet->id, 'asset' => $wallet->asset_code] : null,
            'topupAvailable' => $enabled,
            'orders' => WalletTopupOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->latest()->limit(30)->get()->map(fn (WalletTopupOrder $order): array => $this->present($order))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function order(string $tenantId, string $userId, string $orderId): array
    {
        $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($orderId)->firstOrFail();

        return $this->present($order);
    }

    /** @return array<string, mixed> */
    private function present(WalletTopupOrder $order): array
    {
        return [
            'id' => $order->id,
            'reference' => strtoupper(substr(str_replace('-', '', $order->id), 0, 12)),
            'amount' => $order->amount,
            'asset' => $order->asset_code,
            'status' => match ($order->status->value) {
                'CREDITED' => 'COMPLETED',
                'FAILED' => 'FAILED',
                'CANCELLED' => 'CANCELLED',
                'EXPIRED' => 'EXPIRED',
                default => 'PROCESSING',
            },
            'paymentReceived' => in_array($order->status->value, ['PAID', 'CREDITED'], true),
            'createdAt' => $order->created_at->toIso8601String(),
            'creditedAt' => $order->credited_at?->toIso8601String(),
        ];
    }
}
