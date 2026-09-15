<?php

namespace App\Domain\SecurityDeposit\Services;

use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\SecurityDeposit\Models\SecurityDepositRefundRequest;
use App\Support\Errors\DomainException;
use Ramsey\Uuid\Uuid;

final class RefundCardPolicy
{
    public static function blocked(string $tenantId, string $userId, ?string $cardId = null): bool
    {
        return SecurityDepositRefundRequest::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where(fn ($query) => $query->where('status', 'CHECKING')->when($cardId !== null, fn ($q) => $q->orWhere(fn ($completed) => $completed->where('status', 'COMPLETED')->whereNotNull('refund_wait_days')->whereRaw('jsonb_exists(card_checks, ?)', [$cardId]))))->exists();
    }

    public static function assertAllowed(string $tenantId, string $userId, ?string $cardId = null): void
    {
        if (self::blocked($tenantId, $userId, $cardId)) {
            throw new DomainException('DEPOSIT_CARD_READ_ONLY', 'Cards are locked for the security deposit refund. Only transaction history is available.', 409);
        }
    }

    public static function operationId(string $refundId, string $cardId, string $kind): string
    {
        return Uuid::uuid5($refundId, $cardId.':'.$kind)->toString();
    }

    /** A trusted worker may only freeze/restore for its persisted request, never a client flag. */
    public static function assertWorker(string $tenantId, string $userId, string $refundId, string $cardId, string $kind, string $requestId): void
    {
        $refund = SecurityDepositRefundRequest::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->whereKey($refundId)->where('status', 'CHECKING')->whereNotNull('refund_wait_days')->firstOrFail();
        $allowed = $refund->cancel_requested_at === null ? 'FREEZE' : 'UNFREEZE';
        if ($kind !== $allowed || $requestId !== self::operationId($refundId, $cardId, $kind)) {
            throw new DomainException('DEPOSIT_CARD_READ_ONLY', 'Cards are locked for the security deposit refund. Only transaction history is available.', 409);
        }
        if ($kind === 'UNFREEZE' && ! CardManagementOrder::query()
            ->where('tenant_id', $tenantId)->where('user_id', $userId)->where('card_id', $cardId)
            ->where('request_id', self::operationId($refundId, $cardId, 'FREEZE'))->where('kind', 'FREEZE')->where('status', 'SUCCEEDED')->exists()) {
            throw new DomainException('DEPOSIT_CARD_READ_ONLY', 'Cards are locked for the security deposit refund. Only transaction history is available.', 409);
        }
    }
}
