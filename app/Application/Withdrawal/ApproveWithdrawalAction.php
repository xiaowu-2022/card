<?php

namespace App\Application\Withdrawal;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Withdrawal\Enums\WithdrawalStatus;
use App\Domain\Withdrawal\Models\WithdrawalOrder;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ApproveWithdrawalAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(string $tenantId, string $orderId, AdminUser $actor, ?string $requestId = null): WithdrawalOrder
    {
        return DB::transaction(function () use ($tenantId, $orderId, $actor, $requestId): WithdrawalOrder {
            $order = WithdrawalOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->status === WithdrawalStatus::Approved) {
                return $order;
            }
            if ($order->status !== WithdrawalStatus::Pending) {
                throw new DomainException('WITHDRAWAL_NOT_PENDING', 'Only a pending withdrawal can be approved.', 409);
            }
            $order->update(['status' => WithdrawalStatus::Approved, 'reviewed_at' => now(), 'reviewed_by_admin_user_id' => $actor->id, 'safe_review_reason' => null]);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'WITHDRAWAL_APPROVED', 'withdrawal_order', $order->id, ['status' => 'PENDING'], ['status' => 'APPROVED'], $requestId);

            return $order;
        }, 3);
    }
}
