<?php

namespace App\Application\Payment;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\SecurityDeposit\Models\InitialDepositIntent;
use App\Jobs\AllocateInitialDeposit;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class CreditWalletTopupAction
{
    public function __construct(private LedgerWriter $ledger, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $orderId): LedgerEntry
    {
        return DB::transaction(function () use ($tenantId, $orderId): LedgerEntry {
            $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$this->lockKey($tenantId, $orderId)]);
            if ($order->status === WalletTopupStatus::Credited) {
                return LedgerEntry::query()->where('tenant_id', $tenantId)->whereKey($order->ledger_entry_id)->firstOrFail();
            }
            if ($order->status !== WalletTopupStatus::Paid) {
                throw new DomainException('TOPUP_NOT_PAID', 'Only a confirmed paid top-up can be credited.', 409);
            }

            $accounts = LedgerAccount::query()->where('tenant_id', $tenantId)
                ->where(function ($query) use ($order): void {
                    $query->where(fn ($user) => $user->where('wallet_id', $order->wallet_id)->where('account_type', LedgerAccountType::UserAvailable->value))
                        ->orWhere(fn ($tenant) => $tenant->whereNull('wallet_id')->where('asset_code', $order->asset_code)->where('account_type', LedgerAccountType::TenantTopupClearing->value));
                })->get()->keyBy(fn (LedgerAccount $account): string => $account->account_type->value);
            $available = $accounts->get(LedgerAccountType::UserAvailable->value);
            $clearing = $accounts->get(LedgerAccountType::TenantTopupClearing->value);
            if (! $available || ! $clearing) {
                throw new DomainException('TOPUP_SETTLEMENT_ACCOUNTS_MISSING', 'Top-up settlement accounts are unavailable.', 409);
            }
            $money = Money::of($order->amount, $order->asset_code);
            $entry = $this->ledger->post(new LedgerPostingPlan(
                $tenantId,
                $order->asset_code,
                "wallet_topup:{$order->id}:credit",
                'WALLET_TOPUP_CREDIT',
                'WALLET_TOPUP_ORDER',
                $order->id,
                null,
                [
                    new LedgerPostingInstruction($clearing->id, Money::of('-'.$money->amount(), $money->assetCode)),
                    new LedgerPostingInstruction($available->id, $money),
                ],
            ));
            $order->status = WalletTopupStatus::Credited;
            $order->ledger_entry_id = $entry->id;
            $order->credited_at = now();
            $order->save();
            if ($order->asset_code === 'USDT') {
                $intent = InitialDepositIntent::query()->firstOrCreate(
                    ['tenant_id' => $tenantId, 'user_id' => $order->user_id], ['topup_id' => $order->id, 'status' => 'PENDING'],
                );
                DB::afterCommit(function () use ($intent): void {
                    try {
                        AllocateInitialDeposit::dispatch($intent->tenant_id, $intent->id);
                    } catch (\Throwable) { /* Durable intent is retried by promotion:recover. Never undo an externally paid credit. */
                    }
                });
            }
            $this->audit->record($tenantId, 'SYSTEM', null, 'WALLET_TOPUP_CREDITED', 'wallet_topup_order', $order->id, null, [
                'asset' => $order->asset_code, 'status' => WalletTopupStatus::Credited->value, 'ledger_entry_id' => $entry->id,
            ]);

            return $entry;
        }, 3);
    }

    private function lockKey(string $tenantId, string $orderId): int
    {
        /** @var array{high:int,low:int} $words */
        $words = unpack('Nhigh/Nlow', substr(hash('sha256', "topup-credit-v1\0{$tenantId}\0{$orderId}", true), 0, 8));

        return ($words['high'] << 32) | $words['low'];
    }
}
