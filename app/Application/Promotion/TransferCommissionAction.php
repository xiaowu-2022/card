<?php

namespace App\Application\Promotion;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Promotion\Models\CommissionTransfer;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class TransferCommissionAction
{
    public function __construct(private CommissionAccounts $accounts, private LedgerWriter $ledger, private AuditLogger $audit, private KycStatusService $kyc) {}

    public function execute(string $tenantId, string $userId, string $requestId): CommissionTransfer
    {
        if (! Str::isUuid($requestId)) {
            throw new DomainException('PROMOTION_REQUEST_INVALID', 'A valid request identifier is required.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $requestId): CommissionTransfer {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            $existing = CommissionTransfer::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('request_id', $requestId)->first();
            if ($existing) {
                return $existing;
            }
            CommissionTransferEligibility::assertAllowed($tenantId, $userId);
            $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', 'USDT')->first();
            if ($tenant->status !== TenantStatus::Active || $user->status !== UserStatus::Active || $wallet?->status !== WalletStatus::Active || $this->kyc->forUser($tenantId, $userId) !== KycUserStatus::Approved) {
                throw new DomainException('PROMOTION_TRANSFER_UNAVAILABLE', 'An active verified wallet is required to receive commission.', 403);
            }
            $commission = $this->accounts->forUser($tenantId, $userId);
            $amount = Money::of($commission->balance, 'USDT');
            if (! $amount->isPositive()) {
                throw new DomainException('PROMOTION_NOTHING_TO_TRANSFER', 'There is no commission available to transfer.');
            }
            $available = LedgerAccount::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('wallet_id', $wallet->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
            $id = (string) Str::uuid();
            $entry = $this->ledger->post(new LedgerPostingPlan($tenantId, 'USDT', 'commission_transfer:'.$id, 'COMMISSION_TRANSFER', 'COMMISSION_TRANSFER', $id, null, [
                new LedgerPostingInstruction($commission->id, Money::of('-'.$amount->amount(), 'USDT')),
                new LedgerPostingInstruction($available->id, $amount),
            ]));
            $transfer = CommissionTransfer::query()->create(['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'request_id' => $requestId,
                'amount' => $amount->amount(), 'asset_code' => 'USDT', 'ledger_entry_id' => $entry->id]);
            $this->audit->record($tenantId, 'USER', $userId, 'COMMISSION_TRANSFERRED', 'commission_transfer', $id, null, ['amount' => $amount->amount(), 'asset' => 'USDT']);

            return $transfer;
        }, 3);
    }
}
