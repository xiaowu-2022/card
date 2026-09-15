<?php

namespace App\Application\Payment;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Payment\Contracts\Trc20ChainReader;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class VerifyPlatformTopupAction
{
    public function __construct(private BlockchainGatewayInterface $gateway, private ProcessIncomingTrc20TransferAction $process,
        private AuthorizationService $authorization, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $orderId, string $txHash, string $requestId, AdminUser $actor): string
    {
        $actor = $actor->fresh();
        if (! $actor || $actor->status !== AdminUserStatus::Active
            || ! $this->authorization->allows($actor, ScopeType::Platform, null, 'wallet_topups.verify')) {
            throw new DomainException('FORBIDDEN', 'Platform verification permission is required.', 403);
        }
        if (! Str::isUuid($requestId) || ! preg_match('/^[a-f0-9]{64}$/i', $txHash)) {
            throw new DomainException('TOPUP_VERIFICATION_INVALID', 'Enter a valid transaction hash and request identifier.');
        }
        $txHash = strtolower($txHash);
        $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->firstOrFail();
        if ($order->payment_rail !== 'TRC20_SHARED' || $order->asset_code !== 'USDT'
            || ! in_array($order->status->value, ['PENDING', 'PROCESSING', 'PAID', 'CREDITED', 'EXPIRED'], true)
            || ($order->matched_tx_hash !== null && $order->matched_tx_hash !== $txHash)) {
            throw new DomainException('TOPUP_VERIFICATION_NOT_ALLOWED', 'This order cannot be verified with that transaction.');
        }
        if (! $this->gateway->available()) {
            throw new DomainException('BLOCKCHAIN_MONITOR_UNAVAILABLE', 'Blockchain monitoring is unavailable.', 503);
        }
        DB::transaction(function () use ($tenantId, $orderId, $txHash, $requestId, $actor): void {
            DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['platform-topup-check:'.$actor->id.':'.$requestId]);
            $intent = AuditLog::query()->where('action', 'PLATFORM_TOPUP_VERIFICATION_REQUESTED')
                ->where('actor_id', $actor->id)->where('request_id', $requestId)->first();
            if ($intent) {
                if ($intent->tenant_id !== $tenantId || $intent->resource_id !== $orderId || ($intent->after_data['txHash'] ?? null) !== $txHash) {
                    throw new DomainException('IDEMPOTENCY_CONFLICT', 'This verification request was already used for different details.', 409);
                }

                return;
            }
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'PLATFORM_TOPUP_VERIFICATION_REQUESTED', 'wallet_topup_order', $orderId,
                null, ['txHash' => $txHash], $requestId);
        }, 3);
        try {
            $transfers = $this->gateway instanceof Trc20ChainReader
                ? $this->gateway->lookup($txHash, $order->deposit_address)
                : $this->gateway->listIncomingUsdtTrc20Transfers($order->deposit_address);
            $result = 'UNMATCHED';
            foreach ($transfers as $transfer) {
                if (strtolower($transfer->txHash) !== $txHash) {
                    continue;
                }
                $matched = $this->process->execute($transfer, $tenantId, $orderId);
                if ($matched !== 'UNMATCHED') {
                    $result = $matched;
                }
            }
        } catch (\Throwable $error) {
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'PLATFORM_TOPUP_VERIFICATION_UNAVAILABLE', 'wallet_topup_order', $orderId,
                null, ['result' => 'UNCONFIRMED'], $requestId);
            throw $error;
        }
        $this->audit->record($tenantId, 'ADMIN', $actor->id, 'PLATFORM_TOPUP_VERIFICATION_COMPLETED', 'wallet_topup_order', $orderId,
            null, ['result' => $result], $requestId);

        return $result;
    }
}
