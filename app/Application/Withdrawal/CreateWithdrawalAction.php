<?php

namespace App\Application\Withdrawal;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Withdrawal\Enums\WithdrawalDestinationStatus;
use App\Domain\Withdrawal\Enums\WithdrawalStatus;
use App\Domain\Withdrawal\Models\WithdrawalDestination;
use App\Domain\Withdrawal\Models\WithdrawalOrder;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CreateWithdrawalAction
{
    public function __construct(private KycStatusService $kycStatus, private LedgerWriter $ledger, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $userId, string $requestId, string $destinationId, mixed $amount, ?string $auditRequestId = null): WithdrawalOrder
    {
        if (! Str::isUuid($requestId) || ! Str::isUuid($destinationId) || ! is_string($amount)) {
            throw new DomainException('WITHDRAWAL_REQUEST_INVALID', 'The withdrawal request is invalid.');
        }
        try {
            $money = Money::of($amount, 'USDT');
        } catch (InvalidArgumentException) {
            throw new DomainException('WITHDRAWAL_AMOUNT_INVALID', 'Enter a valid positive USDT amount with at most 8 decimal places.');
        }
        if (! $money->isPositive()) {
            throw new DomainException('WITHDRAWAL_AMOUNT_INVALID', 'Withdrawal amount must be greater than zero.');
        }
        $requestHash = $this->requestHash($tenantId, $userId, $destinationId, $money->amount());

        return DB::transaction(function () use ($tenantId, $userId, $requestId, $destinationId, $money, $requestHash, $auditRequestId): WithdrawalOrder {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$this->lockKey($tenantId, $requestId)]);
            $existing = WithdrawalOrder::query()->where('tenant_id', $tenantId)->where('request_id', $requestId)->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new DomainException('IDEMPOTENCY_CONFLICT', 'This request identifier was already used with different withdrawal details.', 409);
                }

                return $existing;
            }

            $tenant = Tenant::query()->whereKey($tenantId)->with('businessSettings')->lockForUpdate()->firstOrFail();
            $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->lockForUpdate()->first();
            $destination = WithdrawalDestination::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($destinationId)->first();
            if ($tenant->status !== TenantStatus::Active) {
                throw new DomainException('TENANT_NOT_ACTIVE', 'Withdrawals require an active tenant.', 403);
            }
            if (! $tenant->businessSettings?->allow_withdrawal) {
                throw new DomainException('WITHDRAWALS_DISABLED', 'Withdrawals are not enabled for this tenant.', 403);
            }
            if ($user->status !== UserStatus::Active) {
                throw new DomainException('USER_NOT_ACTIVE', 'Withdrawals require an active account.', 403);
            }
            if (! $wallet || $wallet->status !== WalletStatus::Active || $wallet->asset_code !== 'USDT') {
                throw new DomainException('WITHDRAWAL_WALLET_UNAVAILABLE', 'An active USDT wallet is required.', 403);
            }
            if ($this->kycStatus->forUser($tenantId, $userId) !== KycUserStatus::Approved) {
                throw new DomainException('KYC_NOT_APPROVED', 'Approved identity verification is required.', 403);
            }
            if (! $destination || $destination->status !== WithdrawalDestinationStatus::Active || $destination->asset_code !== 'USDT' || $destination->network_code !== 'TRON') {
                throw new DomainException('WITHDRAWAL_DESTINATION_INVALID', 'Select an active USDT (TRC20) withdrawal address.');
            }
            $accounts = LedgerAccount::query()->where('tenant_id', $tenantId)->where('wallet_id', $wallet->id)
                ->whereIn('account_type', [LedgerAccountType::UserAvailable->value, LedgerAccountType::UserWithdrawalHold->value])
                ->get()->keyBy(fn (LedgerAccount $account): string => $account->account_type->value);
            $available = $accounts->get(LedgerAccountType::UserAvailable->value);
            $hold = $accounts->get(LedgerAccountType::UserWithdrawalHold->value);
            if (! $available || ! $hold || $available->asset_code !== 'USDT' || $hold->asset_code !== 'USDT') {
                throw new DomainException('WITHDRAWAL_ACCOUNTS_UNAVAILABLE', 'Withdrawal accounts are unavailable.', 409);
            }
            if (Money::of($available->balance, 'USDT')->compare($money) < 0) {
                throw new DomainException('INSUFFICIENT_AVAILABLE_BALANCE', 'Your available balance is not enough for this withdrawal.');
            }

            $order = WithdrawalOrder::query()->create([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'wallet_id' => $wallet->id, 'withdrawal_destination_id' => $destination->id,
                'request_id' => $requestId, 'request_hash' => $requestHash, 'asset_code' => 'USDT', 'network_code' => 'TRON',
                'amount' => $money->amount(), 'status' => WithdrawalStatus::Pending, 'requested_at' => now(),
            ]);
            $entry = $this->ledger->post(new LedgerPostingPlan(
                $tenantId, 'USDT', "withdrawal:{$order->id}:hold", 'WITHDRAWAL_HOLD', 'WITHDRAWAL_ORDER', $order->id, null,
                [new LedgerPostingInstruction($available->id, Money::of('-'.$money->amount(), 'USDT')), new LedgerPostingInstruction($hold->id, $money)],
            ));
            $order->hold_ledger_entry_id = $entry->id;
            $order->save();
            $this->audit->record($tenantId, 'USER', $userId, 'WITHDRAWAL_REQUESTED', 'withdrawal_order', $order->id, null, [
                'amount' => $money->amount(), 'asset' => 'USDT', 'network' => 'TRON', 'destination' => $destination->masked_address, 'ledger_entry_id' => $entry->id,
            ], $auditRequestId);

            return $order;
        }, 3);
    }

    private function requestHash(string $tenantId, string $userId, string $destinationId, string $amount): string
    {
        return hash('sha256', "withdrawal-v1\0{$tenantId}\0{$userId}\0{$destinationId}\0{$amount}\0USDT\0TRON");
    }

    private function lockKey(string $tenantId, string $requestId): int
    {
        /** @var array{high:int,low:int} $words */
        $words = unpack('Nhigh/Nlow', substr(hash('sha256', "withdrawal-request-v1\0{$tenantId}\0{$requestId}", true), 0, 8));

        return ($words['high'] << 32) | $words['low'];
    }
}
