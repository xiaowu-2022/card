<?php

namespace App\Application\Payment;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ConfirmPlatformTopupAction
{
    public function __construct(private AuthorizationService $authorization, private CreditWalletTopupAction $credit, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $orderId, string $requestId, AdminUser $actor, bool $confirmed = false): void
    {
        if (! $confirmed || ! Str::isUuid($requestId)) {
            throw new DomainException('TOPUP_CONFIRMATION_REQUIRED', 'Confirm receipt before crediting this order.');
        }
        DB::transaction(function () use ($tenantId, $orderId, $requestId, $actor): void {
            $actor = $actor->fresh();
            if (! $actor || $actor->status !== AdminUserStatus::Active
                || ! $this->authorization->allows($actor, ScopeType::Platform, null, 'wallet_topups.confirm')) {
                throw new DomainException('FORBIDDEN', 'Platform confirmation permission is required.', 403);
            }
            $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['platform-topup-confirm:'.$actor->id.':'.$requestId]);
            $used = WalletTopupOrder::query()->where('manual_confirmed_by', $actor->id)->where('manual_confirmation_request_id', $requestId)->first();
            if ($used && ($used->id !== $order->id || $used->tenant_id !== $tenantId)) {
                throw new DomainException('IDEMPOTENCY_CONFLICT', 'This confirmation request was already used for another order.', 409);
            }
            if ($order->payment_rail !== 'TRC20_SHARED' || $order->asset_code !== 'USDT') {
                throw new DomainException('TOPUP_CONFIRMATION_NOT_ALLOWED', 'This order cannot be manually confirmed.');
            }
            if ($order->status === WalletTopupStatus::Credited) {
                return; // Chain and manual confirmation race on this same aggregate lock.
            }
            if (! in_array($order->status->value, ['PENDING', 'PROCESSING', 'UNKNOWN'], true)) {
                throw new DomainException('TOPUP_CONFIRMATION_NOT_ALLOWED', 'This order cannot be manually confirmed.');
            }
            $before = $order->status->value;
            $order->manual_confirmed_at = now();
            $order->manual_confirmed_by = $actor->id;
            $order->manual_confirmation_request_id = $requestId;
            $order->paid_at = now();
            $order->status = WalletTopupStatus::Paid;
            $order->save();
            // Preserve provider/chain evidence as-is; no fabricated hash or provider success.
            $entry = $this->credit->execute($tenantId, $orderId);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'PLATFORM_TOPUP_MANUALLY_CONFIRMED', 'wallet_topup_order', $orderId,
                ['status' => $before], ['amount' => $order->amount, 'asset' => 'USDT', 'source' => 'PLATFORM_MANUAL', 'ledger_entry_id' => $entry->id], $requestId);
        }, 3);
    }
}
