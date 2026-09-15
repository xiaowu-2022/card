<?php

namespace App\Application\Withdrawal;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Domain\Withdrawal\Enums\BlockchainVerificationOutcome;
use App\Domain\Withdrawal\Enums\WithdrawalStatus;
use App\Domain\Withdrawal\Models\WithdrawalOrder;
use App\Domain\Withdrawal\Models\WithdrawalTransactionAttempt;
use App\Domain\Withdrawal\Services\WithdrawalAddressProtector;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class VerifyWithdrawalTransactionAction
{
    public function __construct(
        private BlockchainGatewayInterface $gateway,
        private WithdrawalAddressProtector $addresses,
        private LedgerWriter $ledger,
        private AuditLogger $audit,
    ) {}

    public function execute(string $tenantId, string $orderId, AdminUser $actor, string $txHash, ?string $requestId = null): WithdrawalOrder
    {
        $txHash = strtolower(trim($txHash));
        if (preg_match('/^[a-f0-9]{64}$/', $txHash) !== 1) {
            throw new DomainException('WITHDRAWAL_TX_HASH_INVALID', 'Enter a valid TRON transaction hash.');
        }
        if (! $this->gateway->available()) {
            throw new DomainException('BLOCKCHAIN_VERIFICATION_UNAVAILABLE', 'Blockchain verification is currently unavailable.', 503);
        }

        /** @var array{order:WithdrawalOrder,attempt:WithdrawalTransactionAttempt,address:string} $prepared */
        $prepared = DB::transaction(function () use ($tenantId, $orderId, $txHash, $actor, $requestId): array {
            $order = WithdrawalOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->with('destination')->lockForUpdate()->firstOrFail();
            if ($order->status === WithdrawalStatus::Succeeded) {
                if ($order->submitted_tx_hash !== $txHash) {
                    throw new DomainException('WITHDRAWAL_ALREADY_SUCCEEDED', 'This withdrawal is already complete.', 409);
                }

                return ['order' => $order, 'attempt' => $order->attempts()->where('tx_hash', $txHash)->firstOrFail(), 'address' => ''];
            }
            if ($order->status === WithdrawalStatus::Verifying && $order->submitted_tx_hash !== $txHash) {
                throw new DomainException('WITHDRAWAL_VERIFICATION_IN_PROGRESS', 'Finish checking the submitted transaction before using another hash.', 409);
            }
            if (! in_array($order->status, [WithdrawalStatus::Approved, WithdrawalStatus::Verifying], true)) {
                throw new DomainException('WITHDRAWAL_NOT_APPROVED', 'Only an approved withdrawal can verify a transaction.', 409);
            }
            $existingAttempt = WithdrawalTransactionAttempt::query()->where('network_code', 'TRON')->where('tx_hash', $txHash)->first();
            if ($existingAttempt && $existingAttempt->withdrawal_order_id !== $order->id) {
                throw new DomainException('WITHDRAWAL_TX_HASH_ALREADY_USED', 'This transaction hash is already assigned to another withdrawal.', 409);
            }
            if (! $existingAttempt) {
                DB::table('withdrawal_transaction_attempts')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'withdrawal_order_id' => $order->id,
                    'network_code' => 'TRON', 'tx_hash' => $txHash, 'verification_status' => 'PENDING',
                    'last_checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $existingAttempt = WithdrawalTransactionAttempt::query()->where('network_code', 'TRON')->where('tx_hash', $txHash)->firstOrFail();
            }
            $attempt = $existingAttempt;
            if ($attempt->withdrawal_order_id !== $order->id) {
                throw new DomainException('WITHDRAWAL_TX_HASH_ALREADY_USED', 'This transaction hash is already assigned to another withdrawal.', 409);
            }
            $order->update(['status' => WithdrawalStatus::Verifying, 'submitted_tx_hash' => $txHash]);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'WITHDRAWAL_VERIFICATION_REQUESTED', 'withdrawal_order', $order->id, null, ['attempt_id' => $attempt->id], $requestId);

            return ['order' => $order, 'attempt' => $attempt, 'address' => $this->addresses->decrypt($order->destination->address_ciphertext)];
        }, 3);

        if ($prepared['order']->status === WithdrawalStatus::Succeeded) {
            return $prepared['order'];
        }
        try {
            $verification = $this->gateway->verifyUsdtTrc20Transfer($txHash, $prepared['address'], Money::of($prepared['order']->receive_amount, 'USDT')->amount());
        } catch (\Throwable $exception) {
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'WITHDRAWAL_VERIFICATION_UNAVAILABLE', 'withdrawal_order', $orderId, null, ['attempt_id' => $prepared['attempt']->id], $requestId);
            throw $exception;
        }

        return DB::transaction(function () use ($tenantId, $orderId, $txHash, $verification, $actor, $requestId): WithdrawalOrder {
            $order = WithdrawalOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $attempt = WithdrawalTransactionAttempt::query()->where('withdrawal_order_id', $order->id)->where('tx_hash', $txHash)->lockForUpdate()->firstOrFail();
            if ($order->status === WithdrawalStatus::Succeeded) {
                return $order;
            }
            if ($order->status !== WithdrawalStatus::Verifying || $order->submitted_tx_hash !== $txHash) {
                throw new DomainException('WITHDRAWAL_VERIFICATION_STALE', 'The withdrawal verification state changed.', 409);
            }
            $attempt->last_checked_at = now();
            if ($verification->outcome === BlockchainVerificationOutcome::Pending
                || ($verification->outcome === BlockchainVerificationOutcome::Confirmed
                    && $verification->confirmations < (int) config('withdrawal.minimum_confirmations'))) {
                $attempt->verification_status = 'PENDING';
                $attempt->save();

                $this->audit->record($tenantId, 'ADMIN', $actor->id, 'WITHDRAWAL_VERIFICATION_PENDING', 'withdrawal_order', $orderId, null, ['attempt_id' => $attempt->id], $requestId);

                return $order;
            }
            if ($verification->outcome !== BlockchainVerificationOutcome::Confirmed) {
                $attempt->verification_status = $verification->outcome === BlockchainVerificationOutcome::Failed ? 'FAILED' : 'MISMATCH';
                $attempt->safe_failure_code = $verification->outcome->value;
                $attempt->save();
                $order->update(['status' => WithdrawalStatus::Approved, 'submitted_tx_hash' => null]);
                $this->audit->record($tenantId, 'ADMIN', $actor->id, 'WITHDRAWAL_VERIFICATION_REJECTED', 'withdrawal_order', $orderId, null, ['attempt_id' => $attempt->id, 'result' => $attempt->verification_status], $requestId);

                return $order;
            }

            $accounts = LedgerAccount::query()->where('tenant_id', $tenantId)->where(function ($query) use ($order): void {
                $query->where(fn ($user) => $user->where('wallet_id', $order->wallet_id)->where('account_type', LedgerAccountType::UserWithdrawalHold->value))
                    ->orWhere(fn ($tenant) => $tenant->whereNull('wallet_id')->where('asset_code', 'USDT')->whereIn('account_type', [LedgerAccountType::TenantWithdrawalClearing->value, LedgerAccountType::TenantFeeRevenue->value]));
            })->get()->keyBy(fn ($account) => $account->account_type->value);
            $hold = $accounts->get(LedgerAccountType::UserWithdrawalHold->value);
            $clearing = $accounts->get(LedgerAccountType::TenantWithdrawalClearing->value);
            $revenue = $accounts->get(LedgerAccountType::TenantFeeRevenue->value);
            $fee = Money::of($order->fee_amount, 'USDT');
            if (! $hold || ! $clearing || ($fee->isPositive() && ! $revenue)) {
                throw new DomainException('WITHDRAWAL_SETTLEMENT_ACCOUNTS_MISSING', 'Withdrawal settlement accounts are unavailable.', 409);
            }
            $money = Money::of($order->amount, 'USDT');
            $postings = [
                new LedgerPostingInstruction($hold->id, Money::of('-'.$money->amount(), 'USDT')),
                new LedgerPostingInstruction($clearing->id, Money::of($order->receive_amount, 'USDT')),
            ];
            if ($fee->isPositive()) {
                $postings[] = new LedgerPostingInstruction($revenue->id, $fee);
            }
            $entry = $this->ledger->post(new LedgerPostingPlan(
                $tenantId, 'USDT', "withdrawal:{$order->id}:settle", 'WITHDRAWAL_SETTLE', 'WITHDRAWAL_ORDER', $order->id, null,
                $postings,
            ));
            $attempt->verification_status = 'CONFIRMED';
            $attempt->safe_failure_code = null;
            $attempt->save();
            $order->update([
                'status' => WithdrawalStatus::Succeeded, 'settlement_ledger_entry_id' => $entry->id,
                'blockchain_confirmed_at' => now(), 'submitted_tx_hash' => $txHash,
            ]);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'WITHDRAWAL_SUCCEEDED', 'withdrawal_order', $order->id, null, [
                'amount' => $order->amount, 'asset' => 'USDT', 'network' => 'TRON', 'tx_hash' => $txHash, 'ledger_entry_id' => $entry->id,
                'fee_amount' => $order->fee_amount, 'receive_amount' => $order->receive_amount,
            ], $requestId);

            return $order;
        }, 3);
    }
}
