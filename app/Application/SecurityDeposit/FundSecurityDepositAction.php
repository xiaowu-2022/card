<?php

namespace App\Application\SecurityDeposit;

use App\Application\Promotion\EarnDepositCommissionAction;
use App\Application\SecurityDeposit\DTOs\SecurityDepositFundingReceipt;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class FundSecurityDepositAction
{
    public function __construct(
        private KycStatusService $kycStatus,
        private LedgerWriter $ledger,
        private AuditLogger $audit,
        private EarnDepositCommissionAction $commissions,
    ) {}

    public function execute(string $tenantId, string $userId, string $requestId, mixed $expectedRemaining, ?string $auditRequestId = null): SecurityDepositFundingReceipt
    {
        if (! Str::isUuid($requestId)) {
            throw new DomainException('SECURITY_DEPOSIT_REQUEST_ID_INVALID', 'A valid request identifier is required.');
        }
        if (! is_string($expectedRemaining)) {
            throw new DomainException('SECURITY_DEPOSIT_EXPECTED_AMOUNT_INVALID', 'The expected remaining amount must be a decimal string.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $requestId, $expectedRemaining, $auditRequestId): SecurityDepositFundingReceipt {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->lockForUpdate()->first();
            if (! $wallet) {
                throw new DomainException('WALLET_NOT_ACTIVE', 'An active wallet is required.', 403);
            }
            $settings = $tenant->businessSettings()->lockForUpdate()->firstOrFail();
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$this->lockKey($tenantId, $wallet->id)]);

            $eventKey = "security_deposit:{$wallet->id}:{$requestId}:fund";
            $existing = LedgerEntry::query()->where('tenant_id', $tenantId)->where('event_key', $eventKey)->first();
            if ($existing) {
                return $this->receiptForExisting($existing, $tenantId, $wallet->id);
            }
            RefundSecurityDepositAction::assertNoPending($tenantId, $userId);

            if ($tenant->status !== TenantStatus::Active) {
                throw new DomainException('TENANT_NOT_ACTIVE', 'Security deposit funding requires an active tenant.', 403);
            }
            if ($user->status !== UserStatus::Active) {
                throw new DomainException('USER_NOT_ACTIVE', 'Security deposit funding requires an active account.', 403);
            }
            if ($wallet->status !== WalletStatus::Active) {
                throw new DomainException('WALLET_NOT_ACTIVE', 'Security deposit funding requires an active wallet.', 403);
            }
            if ($this->kycStatus->forUser($tenantId, $userId) !== KycUserStatus::Approved) {
                throw new DomainException('KYC_NOT_APPROVED', 'Approved identity verification is required.', 403);
            }

            $asset = strtoupper((string) $settings->required_security_deposit_asset);
            if ($asset !== strtoupper((string) $tenant->default_asset) || $asset !== $wallet->asset_code) {
                throw new DomainException('SECURITY_DEPOSIT_ASSET_MISMATCH', 'Security deposit and wallet assets do not match.');
            }
            $accounts = LedgerAccount::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('wallet_id', $wallet->id)
                ->whereIn('account_type', [LedgerAccountType::UserAvailable->value, LedgerAccountType::UserSecurityDeposit->value])
                ->get()->keyBy(fn (LedgerAccount $account): string => $account->account_type->value);
            /** @var LedgerAccount|null $availableAccount */
            $availableAccount = $accounts->get(LedgerAccountType::UserAvailable->value);
            /** @var LedgerAccount|null $depositAccount */
            $depositAccount = $accounts->get(LedgerAccountType::UserSecurityDeposit->value);
            if (! $availableAccount || ! $depositAccount || $availableAccount->asset_code !== $asset || $depositAccount->asset_code !== $asset) {
                throw new DomainException('SECURITY_DEPOSIT_ASSET_MISMATCH', 'Security deposit accounts are unavailable or use a different asset.');
            }

            try {
                $expected = Money::of($expectedRemaining, $asset);
            } catch (InvalidArgumentException) {
                throw new DomainException('SECURITY_DEPOSIT_EXPECTED_AMOUNT_INVALID', 'The expected remaining amount is invalid.');
            }
            $required = Money::of($settings->required_security_deposit_amount, $asset);
            $current = Money::of($depositAccount->balance, $asset);
            $remaining = $required->subtract($current);
            if ($remaining->isNegative()) {
                $remaining = Money::of('0', $asset);
            }
            if ($expected->compare($remaining) !== 0) {
                throw new DomainException('SECURITY_DEPOSIT_AMOUNT_CHANGED', 'The security deposit amount changed. Please review the latest amount.', 409);
            }
            if ($remaining->isZero()) {
                throw new DomainException('SECURITY_DEPOSIT_ALREADY_SATISFIED', 'The security deposit requirement is already met.', 409);
            }
            $available = Money::of($availableAccount->balance, $asset);
            if ($available->compare($remaining) < 0) {
                throw new DomainException('INSUFFICIENT_AVAILABLE_BALANCE', 'Your available balance is not enough to complete the security deposit.');
            }

            $entry = $this->ledger->post(new LedgerPostingPlan(
                $tenantId,
                $asset,
                $eventKey,
                'SECURITY_DEPOSIT_FUND',
                'SECURITY_DEPOSIT_WALLET',
                $wallet->id,
                null,
                [
                    new LedgerPostingInstruction($availableAccount->id, Money::of('-'.$remaining->amount(), $asset)),
                    new LedgerPostingInstruction($depositAccount->id, $remaining),
                ],
            ));
            $this->commissions->execute($tenantId, $userId, $entry);
            $this->audit->record($tenantId, 'USER', $userId, 'SECURITY_DEPOSIT_FUNDED', 'wallet', $wallet->id, null, [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'wallet_id' => $wallet->id,
                'ledger_entry_id' => $entry->id,
                'amount' => $remaining->amount(),
                'asset' => $asset,
            ], $auditRequestId);

            return new SecurityDepositFundingReceipt($entry->id, $wallet->id, $remaining->amount(), $asset, false);
        }, 3);
    }

    private function receiptForExisting(LedgerEntry $entry, string $tenantId, string $walletId): SecurityDepositFundingReceipt
    {
        if ($entry->sealed_at === null || $entry->event_type !== 'SECURITY_DEPOSIT_FUND' || $entry->reference_type !== 'SECURITY_DEPOSIT_WALLET'
            || $entry->reference_id !== $walletId || $entry->tenant_id !== $tenantId) {
            throw new DomainException('SECURITY_DEPOSIT_IDEMPOTENCY_CONFLICT', 'The request identifier conflicts with an existing financial event.', 409);
        }
        $posting = DB::table('ledger_postings as postings')->join('ledger_accounts as accounts', 'accounts.id', '=', 'postings.ledger_account_id')
            ->where('postings.ledger_entry_id', $entry->id)->where('accounts.account_type', LedgerAccountType::UserSecurityDeposit->value)
            ->where('accounts.wallet_id', $walletId)->where('accounts.tenant_id', $tenantId)->select('postings.delta')->first();
        if (! $posting || ! Money::of($posting->delta, $entry->asset_code)->isPositive()) {
            throw new DomainException('SECURITY_DEPOSIT_IDEMPOTENCY_CONFLICT', 'The existing financial event is not a valid deposit funding result.', 409);
        }

        return new SecurityDepositFundingReceipt($entry->id, $walletId, $posting->delta, $entry->asset_code, true);
    }

    private function lockKey(string $tenantId, string $walletId): int
    {
        /** @var array{high:int,low:int} $words */
        $words = unpack('Nhigh/Nlow', substr(hash('sha256', "security-deposit-v1\0{$tenantId}\0{$walletId}", true), 0, 8));

        return ($words['high'] << 32) | $words['low'];
    }
}
