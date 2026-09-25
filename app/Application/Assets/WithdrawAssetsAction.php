<?php

namespace App\Application\Assets;

use App\Application\Partners\FeeValuation;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetWithdrawalOrder;
use App\Domain\Assets\WithdrawalFee;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Withdrawal\Services\WithdrawalAddressProtector;
use App\Infrastructure\Assets\ChainReader;
use App\Infrastructure\Assets\ChainRpc;
use App\Infrastructure\Assets\PublicChainNodes;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final readonly class WithdrawAssetsAction
{
    public function __construct(private AssetAccess $access, private AssetRails $rails, private LedgerWriter $ledger, private AuditLogger $audit, private WithdrawalAddressProtector $protector, private ChainReader $reader, private ChainRpc $rpc) {}

    public function create(string $tenantId, string $userId, string $railCode, string $amount, string $address, string $expectedFee, string $requestId, bool $confirmed): AssetWithdrawalOrder
    {
        AssetAccess::requestId($requestId);
        if (! $confirmed) {
            throw new DomainException('CONFIRMATION_REQUIRED', 'Explicit confirmation is required.');
        }
        // Retry lookup precedes provider/config checks. No RPC inside a transaction.
        $existing = AssetWithdrawalOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('request_id', $requestId)->first();
        if ($existing) {
            $this->same($existing, $railCode, $amount, $address, $expectedFee);

            return $existing;
        }
        [$rail,,$connection] = $this->rails->enabled($tenantId, $railCode, 'withdrawal');
        $address = trim($address);
        if ($rail->network === 'ETHEREUM') {
            if (! preg_match('/^0x[0-9a-fA-F]{40}$/', $address) || preg_match('/^0x0{40}$/i', $address)) {
                throw new DomainException('WITHDRAWAL_ADDRESS_INVALID', 'Enter a valid destination address.');
            }
            $address = strtolower($address);
        } else {
            $result = $this->rpc->call($connection, 'validateaddress', [$address]);
            if (($result['isvalid'] ?? false) !== true) {
                throw new DomainException('WITHDRAWAL_ADDRESS_INVALID', 'Enter a valid destination address.');
            }
            $address = $result['address'] ?? $address;
        }

        return DB::transaction(function () use ($tenantId, $userId, $railCode, $amount, $address, $expectedFee, $requestId) {
            AssetAccess::lock('asset-withdrawal:'.$tenantId.':'.$userId.':'.$requestId);
            $existing = AssetWithdrawalOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('request_id', $requestId)->first();
            if ($existing) {
                $this->same($existing, $railCode, $amount, $address, $expectedFee);

                return $existing;
            }
            [$tenant,$user] = $this->access->operational($tenantId, $userId);
            [$rail,$company] = $this->rails->enabled($tenantId, $railCode, 'withdrawal');
            $money = $this->rails->amount($amount, $rail->asset_code);
            $fee = WithdrawalFee::calculate($money->amount(), $company->withdrawal_fee_percent, $rail->asset_code);
            if (! BigDecimal::of($fee->amount())->isEqualTo($expectedFee)) {
                throw new DomainException('WITHDRAWAL_FEE_CHANGED', 'The withdrawal fee changed. Review the updated amount.', 409);
            }
            if (! $money->subtract($fee)->isPositive()) {
                throw new DomainException('AMOUNT_INVALID', 'The amount must exceed the withdrawal fee.');
            }
            $wallet = $this->access->wallet($tenant, $user, $rail->asset_code);
            $order = AssetWithdrawalOrder::query()->create(['tenant_id' => $tenantId, 'user_id' => $userId, 'wallet_id' => $wallet->id, 'asset_code' => $rail->asset_code, 'rail_code' => $railCode, 'network' => $rail->network, 'contract' => $rail->contract, 'address' => $address, 'address_hash' => $this->protector->hash($tenantId, $userId, $address), 'request_id' => $requestId, 'request_hash' => hash('sha256', $railCode.':'.$money->amount().':'.$fee->amount().':'.$address), 'amount' => $money->amount(), 'fee_amount' => $fee->amount(), 'fee_percent' => $company->withdrawal_fee_percent]);
            $entry = $this->post($order, 'hold', [['USER_AVAILABLE', '-'.$money->amount()], ['USER_WITHDRAWAL_HOLD', $money->amount()]]);
            $order->update(['hold_entry_id' => $entry->id]);
            $this->audit->record($tenantId, 'USER', $userId, 'ASSET_WITHDRAWAL_CREATED', 'asset_withdrawal_order', $order->id, null, ['amount' => $order->amount, 'asset' => $order->asset_code]);

            return $order;
        }, 3);
    }

    private function same(AssetWithdrawalOrder $order, string $rail, string $amount, string $address, string $fee): void
    {
        $address = $order->network === 'ETHEREUM' ? strtolower(trim($address)) : trim($address);
        if ($order->rail_code !== $rail || ! BigDecimal::of($order->amount)->isEqualTo($amount) || ! BigDecimal::of($order->fee_amount)->isEqualTo($fee) || ! hash_equals($order->address_hash, $this->protector->hash($order->tenant_id, $order->user_id, $address))) {
            throw new DomainException('IDEMPOTENCY_CONFLICT', 'This request identifier was already used with different details.', 409);
        }
    }

    public function review(string $tenantId, string $id, AdminUser $actor, bool $approve): AssetWithdrawalOrder
    {
        $this->access->platform($actor, 'withdrawals.review');

        return DB::transaction(function () use ($tenantId, $id, $actor, $approve) {
            $o = AssetWithdrawalOrder::query()->where('tenant_id', $tenantId)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($o->status === ($approve ? 'APPROVED' : 'REJECTED')) {
                return $o;
            }
            if ($o->status !== 'PENDING') {
                throw new DomainException('WITHDRAWAL_REVIEW_UNAVAILABLE', 'This withdrawal cannot be reviewed.', 409);
            }
            if (! $approve) {
                $this->post($o, 'release', [['USER_WITHDRAWAL_HOLD', '-'.$o->amount], ['USER_AVAILABLE', $o->amount]]);
            }
            $o->update(['status' => $approve ? 'APPROVED' : 'REJECTED', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, $approve ? 'ASSET_WITHDRAWAL_APPROVED' : 'ASSET_WITHDRAWAL_REJECTED', 'asset_withdrawal_order', $id);

            return $o;
        }, 3);
    }

    public function cancel(string $tenantId, string $userId, string $id): void
    {
        DB::transaction(function () use ($tenantId, $userId, $id) {
            $o = AssetWithdrawalOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($o->status === 'CANCELLED') {
                return;
            }
            // Once approved, an operator may already be sending externally; never release then.
            if ($o->status !== 'PENDING') {
                throw new DomainException('WITHDRAWAL_CANCEL_UNAVAILABLE', 'This withdrawal can no longer be cancelled.', 409);
            }
            $this->post($o, 'release', [['USER_WITHDRAWAL_HOLD', '-'.$o->amount], ['USER_AVAILABLE', $o->amount]]);
            $o->update(['status' => 'CANCELLED']);
            $this->audit->record($tenantId, 'USER', $userId, 'ASSET_WITHDRAWAL_CANCELLED', 'asset_withdrawal_order', $id);
        }, 3);
    }

    public function verify(string $tenantId, string $id, AdminUser $actor, string $txHash, string $requestId): AssetWithdrawalOrder
    {
        $this->access->platform($actor, 'withdrawals.review');
        AssetAccess::requestId($requestId);
        $o = DB::transaction(function () use ($tenantId, $id, $actor, $txHash, $requestId) {
            $o = AssetWithdrawalOrder::query()->where('tenant_id', $tenantId)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($o->status === 'COMPLETED') {
                return $o;
            }
            $pattern = $o->network === 'ETHEREUM' ? '/^0x[0-9a-f]{64}$/' : '/^[0-9a-f]{64}$/';
            if (! preg_match($pattern, strtolower($txHash))) {
                throw new DomainException('TRANSACTION_INVALID', 'Enter a valid transaction hash.');
            }
            if (! in_array($o->status, ['APPROVED', 'PROCESSING', 'UNKNOWN'], true) || ($o->submitted_tx_hash !== null && $o->submitted_tx_hash !== strtolower($txHash))) {
                throw new DomainException('WITHDRAWAL_PROOF_CONFLICT', 'The submitted transaction cannot be changed.', 409);
            }
            $o->update(['status' => 'PROCESSING', 'submitted_tx_hash' => strtolower($txHash)]);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'ASSET_WITHDRAWAL_VERIFICATION_REQUESTED', 'asset_withdrawal_order', $id, null, null, $requestId);

            return $o;
        }, 3);
        if ($o->status === 'COMPLETED') {
            return $o;
        }
        try {
            $proofs = $this->reader->transaction(PublicChainNodes::withdrawalConnection($o->network), $o->submitted_tx_hash);
        } catch (\Throwable) {
            $proofs = [];
        }
        $net = Money::of($o->amount, $o->asset_code)->subtract(Money::of($o->fee_amount, $o->asset_code));
        $matches = array_values(array_filter($proofs, fn ($p) => $p['address'] === $o->address && $p['contract'] === $o->contract && BigDecimal::of($p['amount'])->isEqualTo($net->amount()) && $p['occurred_at']->greaterThanOrEqualTo($o->created_at)));

        $feeQuote = count($matches) === 1 ? app(FeeValuation::class)->quote($o->asset_code, $o->fee_amount) : null;

        return DB::transaction(function () use ($tenantId, $id, $actor, $matches, $net, $requestId, $feeQuote) {
            $o = AssetWithdrawalOrder::query()->where('tenant_id', $tenantId)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($o->status === 'COMPLETED') {
                return $o;
            }
            if (count($matches) !== 1) {
                $o->update(['status' => 'UNKNOWN']);
                $this->audit->record($tenantId, 'ADMIN', $actor->id, 'ASSET_WITHDRAWAL_UNCONFIRMED', 'asset_withdrawal_order', $id, null, null, $requestId);

                return $o;
            }
            AssetAccess::lock('asset-payout:'.$o->network.':'.$matches[0]['event_id']);
            if (AssetWithdrawalOrder::query()->where('network', $o->network)->where('chain_event_id', $matches[0]['event_id'])->exists()) {
                throw new DomainException('WITHDRAWAL_PROOF_CONFLICT', 'This transfer has already been used.', 409);
            }
            $instructions = [['USER_WITHDRAWAL_HOLD', '-'.$o->amount], ['TENANT_WITHDRAWAL_CLEARING', $net->amount()]];
            if (Money::of($o->fee_amount, $o->asset_code)->isPositive()) {
                $instructions[] = ['TENANT_FEE_REVENUE', $o->fee_amount];
            }
            $entry = $this->post($o, 'settle', $instructions);
            $o->update(['status' => 'COMPLETED', 'chain_event_id' => $matches[0]['event_id'], 'ledger_entry_id' => $entry->id]);
            app(FeeValuation::class)->record($o, $feeQuote);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'ASSET_WITHDRAWAL_COMPLETED', 'asset_withdrawal_order', $id, null, ['amount' => $o->amount, 'asset' => $o->asset_code], $requestId);

            return $o->refresh();
        }, 3);
    }

    private function post(AssetWithdrawalOrder $o, string $phase, array $instructions): LedgerEntry
    {
        $this->access->settlementOwners($o->tenant_id, $o->user_id);
        $wallet = Wallet::query()->where('tenant_id', $o->tenant_id)->where('user_id', $o->user_id)->whereKey($o->wallet_id)->firstOrFail();
        $postings = [];
        foreach ($instructions as [$type,$amount]) {
            $account = str_starts_with($type, 'USER_') ? $this->access->account($wallet, $type) : $this->access->companyAccount($o->tenant_id, $o->asset_code, $type);
            $postings[] = new LedgerPostingInstruction($account->id, Money::of($amount, $o->asset_code));
        }

        return $this->ledger->post(new LedgerPostingPlan($o->tenant_id, $o->asset_code, 'asset_withdrawal:'.$o->id.':'.$phase, 'ASSET_WITHDRAWAL_'.strtoupper($phase), 'ASSET_WITHDRAWAL', $o->id, null, $postings));
    }
}
