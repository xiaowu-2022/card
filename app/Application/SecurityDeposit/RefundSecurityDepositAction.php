<?php

namespace App\Application\SecurityDeposit;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\SecurityDeposit\Models\SecurityDepositRefundRequest;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Jobs\ProcessSecurityDepositRefund;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RefundSecurityDepositAction
{
    public function __construct(private TimedDepositRefundAction $timed, private AuditLogger $audit) {}

    public static function assertNoPending(string $tenantId, string $userId): void
    {
        if (SecurityDepositRefundRequest::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'CHECKING')->exists()) {
            throw new DomainException('DEPOSIT_REFUND_PENDING', 'Cancel the pending security deposit refund before continuing.', 409);
        }
    }

    public function request(string $tenantId, string $userId, string $requestId): SecurityDepositRefundRequest
    {
        if (! Str::isUuid($requestId)) {
            throw new DomainException('REFUND_REQUEST_INVALID', 'A valid request identifier is required.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $requestId): SecurityDepositRefundRequest {
            $this->lockOwner($tenantId, $userId);
            $existing = SecurityDepositRefundRequest::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('request_id', $requestId)->first();
            if ($existing) {
                return $existing;
            }
            self::assertNoPending($tenantId, $userId);
            $days = Tenant::query()->whereKey($tenantId)->firstOrFail()->businessSettings->security_deposit_refund_wait_days;
            if ($days === null) {
                throw new DomainException('DEPOSIT_REFUND_NOT_CONFIGURED', 'The company has not configured the deposit refund waiting period. Please contact support.', 409);
            }
            $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', 'USDT')->where('status', 'ACTIVE')->firstOrFail();
            $amount = LedgerAccount::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('wallet_id', $wallet->id)->where('account_type', 'USER_SECURITY_DEPOSIT')->value('balance');
            if (! is_string($amount) || ! Money::of($amount, 'USDT')->isPositive()) {
                throw new DomainException('DEPOSIT_NOTHING_TO_REFUND', 'There is no security deposit to refund.');
            }
            $started = now()->startOfSecond();
            $refund = SecurityDepositRefundRequest::query()->create(['tenant_id' => $tenantId, 'user_id' => $userId,
                'wallet_id' => $wallet->id, 'request_id' => $requestId, 'amount' => $amount, 'asset_code' => 'USDT', 'status' => 'CHECKING',
                'refund_wait_days' => $days, 'refund_eligible_at' => $started->copy()->addSeconds($days * 86400), 'created_at' => $started, 'progress' => 'freezing']);
            $this->audit->record($tenantId, 'USER', $userId, 'SECURITY_DEPOSIT_REFUND_REQUESTED', 'security_deposit_refund', $refund->id, null, ['amount' => $amount]);
            ProcessSecurityDepositRefund::dispatch($tenantId, $refund->id)->afterCommit();

            return $refund;
        }, 3);
    }

    public function cancel(string $tenantId, string $userId, string $refundId): void
    {
        DB::transaction(function () use ($tenantId, $userId, $refundId): void {
            $this->lockOwner($tenantId, $userId);
            $refund = SecurityDepositRefundRequest::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($refundId)->lockForUpdate()->firstOrFail();
            if ($refund->status === 'CANCELLED') {
                return;
            }
            if ($refund->status !== 'CHECKING') {
                throw new DomainException('DEPOSIT_REFUND_COMPLETED', 'The security deposit refund has already completed.', 409);
            }
            if ($refund->refund_wait_days !== null) {
                if ($refund->cancel_requested_at === null) {
                    $refund->update(['cancel_requested_at' => now(), 'progress' => 'restoring']);
                    $this->audit->record($tenantId, 'USER', $userId, 'SECURITY_DEPOSIT_REFUND_CANCEL_REQUESTED', 'security_deposit_refund', $refund->id);
                }
                ProcessSecurityDepositRefund::dispatch($tenantId, $refund->id)->afterCommit();

                return;
            }
            $refund->update(['status' => 'CANCELLED']);
            $this->audit->record($tenantId, 'USER', $userId, 'SECURITY_DEPOSIT_REFUND_CANCELLED', 'security_deposit_refund', $refund->id);
        });
    }

    public function settle(string $tenantId, string $userId, string $refundId): SecurityDepositRefundRequest
    {
        return $this->timed->execute($tenantId, $userId, $refundId);
    }

    private function lockOwner(string $tenantId, string $userId): void
    {
        $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
        if ($tenant->status->value !== 'ACTIVE' || $user->status->value !== 'ACTIVE') {
            throw new DomainException('DEPOSIT_REFUND_UNAVAILABLE', 'Security deposit refunds require an active account.', 403);
        }
    }
}
