<?php

namespace App\Application\User;

use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;

final class UserOperationRestrictions
{
    public const FIELDS = ['withdrawal_blocked', 'deposit_refund_blocked', 'card_transfer_blocked', 'wallet_transfer_blocked'];

    /** Final checks run inside the operation transaction after its Tenant/User locks. */
    public static function assertAllowed(string $tenantId, string $userId, string $field): void
    {
        if (! in_array($field, self::FIELDS, true)) {
            throw new \InvalidArgumentException('Unknown operation restriction.');
        }
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        if ($user->getAttribute($field)) {
            throw new DomainException('USER_OPERATION_RESTRICTED', 'Please contact support.', 403);
        }
    }

    public static function values(User $user): array
    {
        $result = [];
        foreach (self::FIELDS as $field) {
            $result[$field] = (bool) $user->getAttribute($field);
        }

        return $result;
    }
}
