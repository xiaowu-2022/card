<?php

namespace App\Application\Payment;

use App\Application\Assets\TronDepositConfiguration;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Withdrawal\DTOs\IncomingBlockchainTransfer;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ProcessIncomingTrc20TransferAction
{
    public function __construct(private CreditWalletTopupAction $credit) {}

    public function execute(IncomingBlockchainTransfer $transfer, ?string $tenantId = null, ?string $orderId = null, ?\DateTimeImmutable $notBefore = null): string
    {
        $normalized = $this->normalize($transfer);
        if ($normalized === null) {
            return 'UNMATCHED';
        }

        [$txHash, $amount, $destination] = $normalized;
        $snapshot = $this->candidateSnapshot($transfer, $txHash, $amount, $destination);
        if (! $snapshot || (($tenantId === null) !== ($orderId === null))
            || ($tenantId !== null && ($snapshot->tenant_id !== $tenantId || $snapshot->id !== $orderId))
            || ($notBefore !== null && $snapshot->created_at < $notBefore)) {
            return 'UNMATCHED';
        }

        $order = DB::transaction(function () use ($transfer, $txHash, $amount, $destination, $snapshot): ?WalletTopupOrder {
            $candidate = WalletTopupOrder::query()->where('tenant_id', $snapshot->tenant_id)->whereKey($snapshot->id)->lockForUpdate()->first();
            if (! $candidate) {
                return null;
            }
            if (! in_array($candidate->status->value, ['PENDING', 'PROCESSING', 'PAID', 'CREDITED', 'EXPIRED'], true)) {
                return null;
            }
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$this->transferLockKey($txHash, $transfer->transferIndex)]);
            $matched = WalletTopupOrder::query()->where('network_code', 'TRON')->where('matched_tx_hash', $txHash)
                ->where('matched_transfer_index', $transfer->transferIndex)->lockForUpdate()->first();
            if ($matched) {
                return $matched->id === $snapshot->id && $matched->tenant_id === $snapshot->tenant_id
                    && $matched->expected_amount === $amount && $matched->deposit_address === $destination
                    ? $matched
                    : null;
            }

            $active = in_array($candidate->status, [WalletTopupStatus::Pending, WalletTopupStatus::Processing], true);
            $unambiguousExpired = $candidate->status === WalletTopupStatus::Expired
                && ! WalletTopupOrder::query()->where('payment_rail', 'TRC20_SHARED')
                    ->where('deposit_address', $destination)->where('expected_amount', $amount)
                    ->where('created_at', '>', $candidate->created_at)->exists();
            if ((! $active && ! $unambiguousExpired) || $candidate->matched_tx_hash !== null
                || $candidate->created_at > $transfer->occurredAt || $candidate->expires_at < $transfer->occurredAt) {
                return null;
            }

            $candidate->status = WalletTopupStatus::Processing;
            $candidate->matched_tx_hash = $txHash;
            $candidate->matched_transfer_index = $transfer->transferIndex;
            $candidate->blockchain_detected_at = now();
            $candidate->save();
            PaymentProviderTransaction::query()->where('tenant_id', $candidate->tenant_id)
                ->where('wallet_topup_order_id', $candidate->id)->update([
                    'status' => PaymentProviderTransactionStatus::Processing->value,
                    'provider_transaction_id' => "{$txHash}:{$transfer->transferIndex}",
                    'updated_at' => now(),
                ]);

            return $candidate;
        }, 3);

        if (! $order) {
            return 'UNMATCHED';
        }
        if ($order->status === WalletTopupStatus::Credited) {
            return 'CREDITED';
        }
        if ($transfer->confirmations < max(1, (int) config('payment.trc20_required_confirmations'))) {
            return 'CONFIRMING';
        }

        $shouldCredit = DB::transaction(function () use ($order, $transfer, $txHash): ?WalletTopupOrder {
            $locked = WalletTopupOrder::query()->where('tenant_id', $order->tenant_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === WalletTopupStatus::Credited) {
                return null;
            }
            if (! in_array($locked->status, [WalletTopupStatus::Processing, WalletTopupStatus::Paid], true)) {
                return null;
            }
            if ($locked->matched_tx_hash !== $txHash || $locked->matched_transfer_index !== $transfer->transferIndex) {
                return null;
            }
            if ($locked->status !== WalletTopupStatus::Paid) {
                $locked->status = WalletTopupStatus::Paid;
                $locked->paid_at = now();
                $locked->blockchain_confirmed_at = now();
                $locked->save();
                PaymentProviderTransaction::query()->where('tenant_id', $locked->tenant_id)
                    ->where('wallet_topup_order_id', $locked->id)->update([
                        'status' => PaymentProviderTransactionStatus::Succeeded->value,
                        'updated_at' => now(),
                    ]);
            }

            return $locked;
        }, 3);

        if ($shouldCredit) {
            $this->credit->execute($shouldCredit->tenant_id, $shouldCredit->id);
        }

        return $order->fresh()->status === WalletTopupStatus::Credited ? 'CREDITED' : 'PAID';
    }

    private function candidateSnapshot(IncomingBlockchainTransfer $transfer, string $txHash, string $amount, string $destination): ?WalletTopupOrder
    {
        $matched = WalletTopupOrder::query()->where('network_code', 'TRON')->where('matched_tx_hash', $txHash)
            ->where('matched_transfer_index', $transfer->transferIndex)->first();
        if ($matched) {
            return $matched;
        }
        $active = WalletTopupOrder::query()->where('payment_rail', 'TRC20_SHARED')
            ->where('deposit_address', $destination)->where('expected_amount', $amount)
            ->whereIn('status', [WalletTopupStatus::Pending->value, WalletTopupStatus::Processing->value])
            ->whereNull('matched_tx_hash')->where('created_at', '<=', $transfer->occurredAt)
            ->where('expires_at', '>=', $transfer->occurredAt)->latest('created_at')->first();
        if ($active) {
            return $active;
        }

        return WalletTopupOrder::query()->where('payment_rail', 'TRC20_SHARED')
            ->where('deposit_address', $destination)->where('expected_amount', $amount)
            ->where('status', WalletTopupStatus::Expired->value)->whereNull('matched_tx_hash')
            ->where('created_at', '<=', $transfer->occurredAt)->where('expires_at', '>=', $transfer->occurredAt)
            ->latest('created_at')->first();
    }

    /** @return array{string,string,string}|null */
    private function normalize(IncomingBlockchainTransfer $transfer): ?array
    {
        $txHash = strtolower(trim($transfer->txHash));
        $destination = trim($transfer->destination);
        if (strtoupper(trim($transfer->network)) !== 'TRON' || preg_match('/^[a-f0-9]{64}$/', $txHash) !== 1 || $transfer->transferIndex < 0
            || ! app(TronDepositConfiguration::class)->accepts($destination)
            || ! hash_equals((string) config('payment.trc20_token_contract'), trim($transfer->tokenContract))) {
            return null;
        }
        try {
            $amount = Money::of($transfer->amount, 'USDT');
        } catch (Throwable) {
            return null;
        }

        return $amount->isPositive() ? [$txHash, $amount->amount(), $destination] : null;
    }

    private function transferLockKey(string $txHash, int $index): int
    {
        /** @var array{high:int,low:int} $words */
        $words = unpack('Nhigh/Nlow', substr(hash('sha256', "trc20-transfer-v1\0{$txHash}\0{$index}", true), 0, 8));

        return ($words['high'] << 32) | $words['low'];
    }
}
