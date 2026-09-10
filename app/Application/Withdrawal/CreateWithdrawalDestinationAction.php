<?php

namespace App\Application\Withdrawal;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Withdrawal\Enums\WithdrawalDestinationStatus;
use App\Domain\Withdrawal\Models\WithdrawalDestination;
use App\Domain\Withdrawal\Services\WithdrawalAddressProtector;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class CreateWithdrawalDestinationAction
{
    public function __construct(private WithdrawalAddressProtector $protector, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $userId, string $address, ?string $label, ?string $requestId = null): WithdrawalDestination
    {
        $normalized = $this->protector->normalize($address);
        $label = $label === null || trim($label) === '' ? null : trim($label);

        return DB::transaction(function () use ($tenantId, $userId, $normalized, $label, $requestId): WithdrawalDestination {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            if ($tenant->status !== TenantStatus::Active || $user->status !== UserStatus::Active) {
                throw new DomainException('WITHDRAWAL_DESTINATION_UNAVAILABLE', 'A withdrawal address cannot be added while this account is restricted.', 403);
            }
            $hash = $this->protector->hash($tenantId, $userId, $normalized);
            $existing = WithdrawalDestination::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->where('asset_code', 'USDT')->where('network_code', 'TRON')->where('address_hash', $hash)->first();
            if ($existing) {
                return $existing;
            }
            $destination = WithdrawalDestination::query()->create([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'asset_code' => 'USDT', 'network_code' => 'TRON',
                'address_ciphertext' => $this->protector->encrypt($normalized), 'address_hash' => $hash,
                'masked_address' => $this->protector->mask($normalized), 'label' => $label, 'status' => WithdrawalDestinationStatus::Active,
            ]);
            $this->audit->record($tenantId, 'USER', $userId, 'WITHDRAWAL_DESTINATION_CREATED', 'withdrawal_destination', $destination->id, null, [
                'asset' => 'USDT', 'network' => 'TRON', 'masked_address' => $destination->masked_address,
            ], $requestId);

            return $destination;
        }, 3);
    }
}
