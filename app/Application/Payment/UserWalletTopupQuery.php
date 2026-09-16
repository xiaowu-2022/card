<?php

namespace App\Application\Payment;

use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;

final readonly class UserWalletTopupQuery
{
    public function __construct(private BlockchainGatewayInterface $gateway) {}

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $userId): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->with('businessSettings')->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', Tenant::query()->whereKey($tenantId)->value('default_asset'))->first();
        $available = $wallet ? LedgerAccount::query()->where('tenant_id', $tenantId)->where('wallet_id', $wallet->id)
            ->where('account_type', LedgerAccountType::UserAvailable->value)->first() : null;
        $enabled = $tenant->status->value === 'ACTIVE' && $user->status->value === 'ACTIVE'
            && $wallet?->status === WalletStatus::Active && $wallet->asset_code === 'USDT'
            && $tenant->businessSettings->allow_wallet_topup && $this->gateway->available()
            && (string) config('payment.trc20_deposit_address') !== '' && (string) config('payment.trc20_token_contract') !== '';

        return [
            'available' => $available ? ['amount' => $available->balance, 'asset' => $available->asset_code] : null,
            'wallet' => $wallet ? ['id' => $wallet->id, 'asset' => $wallet->asset_code] : null,
            'topupAvailable' => $enabled,
            'orders' => WalletTopupOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->latest()->limit(30)->get()->map(fn (WalletTopupOrder $order): array => $this->present($order))->all(),
            'mockSimulationAvailable' => app()->environment(['local', 'testing']),
        ];
    }

    /** @return array<string, mixed> */
    public function order(string $tenantId, string $userId, string $orderId): array
    {
        $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($orderId)->firstOrFail();

        return $this->present($order) + ['mockSimulationAvailable' => app()->environment(['local', 'testing'])];
    }

    /** @return array<string, mixed> */
    private function present(WalletTopupOrder $order): array
    {
        return [
            'id' => $order->id,
            'reference' => strtoupper(substr(str_replace('-', '', $order->id), 0, 12)),
            'amount' => $order->amount,
            'requestedAmount' => $order->requested_amount ?? $order->amount,
            'expectedAmount' => $order->expected_amount ?? $order->amount,
            'identificationIncrement' => $order->identification_increment,
            'asset' => $order->asset_code,
            'status' => match (true) {
                $order->status->value === 'CREDITED' => 'COMPLETED',
                $order->status->value === 'PAID' => 'ADDING_FUNDS',
                $order->status->value === 'PROCESSING' && $order->blockchain_detected_at !== null => 'CONFIRMING',
                $order->status->value === 'PENDING' && $order->payment_rail === 'TRC20_SHARED' => 'WAITING',
                $order->status->value === 'FAILED' => 'FAILED',
                $order->status->value === 'CANCELLED' => 'CANCELLED',
                $order->status->value === 'EXPIRED' => 'EXPIRED',
                default => 'PROCESSING',
            },
            'paymentReceived' => in_array($order->status->value, ['PAID', 'CREDITED'], true),
            'paymentDetected' => $order->blockchain_detected_at !== null,
            'network' => $order->network_code,
            'depositAddress' => $order->deposit_address,
            'expiresAt' => $order->expires_at?->toIso8601String(),
            'matchedTxHash' => $order->matched_tx_hash,
            'createdAt' => $order->created_at->toIso8601String(),
            'paidAt' => $order->paid_at?->toIso8601String(),
            'creditedAt' => $order->credited_at?->toIso8601String(),
        ];
    }
}
