<?php

namespace App\Application\Promotion;

use App\Domain\SecurityDeposit\Models\SecurityDepositRefundRequest;
use App\Support\Errors\DomainException;

final class CommissionTransferEligibility
{
    public static function refundRestricted(string $tenantId, string $userId): bool
    {
        return SecurityDepositRefundRequest::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->whereIn('status', ['CHECKING', 'COMPLETED'])->exists();
    }

    /** Call after locking Tenant then User, shared with refund request/cancel/settle. */
    public static function assertAllowed(string $tenantId, string $userId): void
    {
        if (self::refundRestricted($tenantId, $userId)) {
            throw new DomainException('COMMISSION_REFUND_RESTRICTED',
                'During and after a deposit refund, commission can still be earned but cannot be transferred to your wallet or withdrawn. Existing wallet balance can still be withdrawn.', 409);
        }
    }
}
