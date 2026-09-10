<?php

namespace App\Application\Withdrawal;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Withdrawal\Models\WithdrawalOrder;
use App\Domain\Withdrawal\Services\WithdrawalAddressProtector;

final readonly class RevealWithdrawalAddressAction
{
    public function __construct(private WithdrawalAddressProtector $addresses, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $orderId, AdminUser $actor, ?string $requestId = null): string
    {
        $order = WithdrawalOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->with('destination')->firstOrFail();
        $address = $this->addresses->decrypt($order->destination->address_ciphertext);
        $this->audit->record($tenantId, 'ADMIN', $actor->id, 'WITHDRAWAL_ADDRESS_REVEALED', 'withdrawal_order', $order->id, null, [
            'destination_id' => $order->withdrawal_destination_id,
        ], $requestId);

        return $address;
    }
}
