<?php

namespace App\Application\SecurityDeposit;

use App\Application\Card\ManageCardAction;
use App\Application\Card\RefreshManagedCardAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\UserCard;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\SecurityDeposit\Models\SecurityDepositRefundRequest;
use App\Domain\SecurityDeposit\Services\RefundCardPolicy;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Errors\DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class TimedDepositRefundAction
{
    public function __construct(private ManageCardAction $manage, private RefreshManagedCardAction $refresh, private LedgerWriter $ledger, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $userId, string $refundId): SecurityDepositRefundRequest
    {
        $refund = SecurityDepositRefundRequest::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($refundId)->firstOrFail();
        // No migration or worker may replay a pre-timer request under new refund rules.
        if ($refund->status !== 'CHECKING' || $refund->refund_wait_days === null) {
            return $refund;
        }
        try {
            if ($refund->cancel_requested_at !== null) {
                return $this->restore($refund);
            }
            $checks = [];
            $blocked = false;
            foreach ($this->cards($refund)->orderBy('id')->get() as $card) {
                try {
                    $order = $this->operation($refund, $card, 'FREEZE');
                    if ($order !== null && ! $order->terminal()) {
                        $order = $this->manage->sync($tenantId, $order->id);
                    }
                    if ($order !== null && $order->status !== 'SUCCEEDED') {
                        $blocked = true;

                        continue;
                    }
                    $fresh = $this->refresh->execute($tenantId, $userId, $card->id);
                    if ($fresh->provider_status === 'normal' && $order === null) {
                        $order = $this->manage->operate($tenantId, $userId, $card->id,
                            RefundCardPolicy::operationId($refundId, $card->id, 'FREEZE'), 'FREEZE', refundId: $refundId);
                        if ($order->status !== 'SUCCEEDED') {
                            $blocked = true;

                            continue;
                        }
                        $fresh = $this->refresh->execute($tenantId, $userId, $card->id);
                    }
                    if (! in_array($fresh->provider_status, ['frozen', 'cancelled'], true)) {
                        $blocked = true;

                        continue;
                    }
                    // Only frozen/cancelled status is required, not zero card balance.
                    $checks[$card->id] = $fresh->refresh_generation;
                } catch (\Throwable) {
                    // An unavailable card must not prevent freezing the other owned cards.
                    $blocked = true;
                }
            }
            if ($blocked) {
                return $this->progress($refund, 'blocked');
            }

            return DB::transaction(function () use ($refund, $checks): SecurityDepositRefundRequest {
                $current = $this->locked($refund);
                if ($current->status !== 'CHECKING' || $current->cancel_requested_at !== null) {
                    return $current;
                }
                if ($this->outstanding($current)) {
                    $current->update(['progress' => 'blocked']);

                    return $current;
                }
                $cards = $this->cards($current)->orderBy('id')->lockForUpdate()->get();
                if (count($checks) !== $cards->count()) {
                    throw new DomainException('DEPOSIT_CHECKS_CHANGED', 'Card checks changed. Please try again.', 409);
                }
                foreach ($cards as $card) {
                    if (! isset($checks[$card->id]) || $checks[$card->id] !== $card->refresh_generation || ! in_array($card->provider_status, ['frozen', 'cancelled'], true)) {
                        throw new DomainException('DEPOSIT_CHECKS_CHANGED', 'Card checks changed. Please try again.', 409);
                    }
                }
                if ($current->refund_eligible_at->isFuture()) {
                    $current->update(['progress' => 'waiting']);

                    return $current;
                }
                Wallet::query()->where('tenant_id', $current->tenant_id)->where('user_id', $current->user_id)->whereKey($current->wallet_id)->where('asset_code', 'USDT')->where('status', 'ACTIVE')->firstOrFail();
                $accounts = LedgerAccount::query()->where('tenant_id', $current->tenant_id)->where('user_id', $current->user_id)->where('wallet_id', $current->wallet_id)->get()->keyBy(fn ($a) => $a->account_type->value);
                $deposit = $accounts->get('USER_SECURITY_DEPOSIT');
                $available = $accounts->get('USER_AVAILABLE');
                if (! $deposit || ! $available || Money::of($deposit->balance, 'USDT')->compare(Money::of($current->amount, 'USDT')) !== 0) {
                    throw new DomainException('DEPOSIT_CHECKS_CHANGED', 'Security deposit checks changed. Please try again.', 409);
                }
                $entry = $this->ledger->post(new LedgerPostingPlan($current->tenant_id, 'USDT', 'deposit_refund:'.$current->id,
                    'SECURITY_DEPOSIT_REFUND', 'SECURITY_DEPOSIT_REFUND', $current->id, null, [
                        new LedgerPostingInstruction($deposit->id, Money::of('-'.$current->amount, 'USDT')),
                        new LedgerPostingInstruction($available->id, Money::of($current->amount, 'USDT')),
                    ]));
                $current->update(['status' => 'COMPLETED', 'progress' => 'completed', 'ledger_entry_id' => $entry->id,
                    'card_checks' => (object) $checks, 'completed_at' => now()]);
                $this->audit->record($current->tenant_id, 'SYSTEM', null, 'SECURITY_DEPOSIT_REFUNDED', 'security_deposit_refund', $current->id, null,
                    ['amount' => $current->amount, 'waiting_days' => $current->refund_wait_days]);

                return $current;
            }, 3);
        } catch (\Throwable) {
            // Keep principal and card restrictions intact on any unavailable/unknown result.
            return $this->progress($refund, $refund->fresh()->cancel_requested_at !== null ? 'restoring' : 'blocked');
        }
    }

    private function restore(SecurityDepositRefundRequest $refund): SecurityDepositRefundRequest
    {
        foreach ($this->cards($refund)->orderBy('id')->get() as $card) {
            $freeze = $this->operation($refund, $card, 'FREEZE');
            if ($freeze === null) {
                continue; // Originally frozen cards were never changed by this request.
            }
            if (! $freeze->terminal()) {
                $freeze = $this->manage->sync($refund->tenant_id, $freeze->id);
            }
            if (! $freeze->terminal()) {
                return $this->progress($refund, 'restoring');
            }
            if ($freeze->status !== 'SUCCEEDED') {
                continue;
            }
            $restore = $this->operation($refund, $card, 'UNFREEZE');
            if ($restore !== null) {
                if (! $restore->terminal()) {
                    $restore = $this->manage->sync($refund->tenant_id, $restore->id);
                }
                if ($restore->status !== 'SUCCEEDED') {
                    return $this->progress($refund, 'restoring');
                }
            }
            $fresh = $this->refresh->execute($refund->tenant_id, $refund->user_id, $card->id);
            if ($restore === null && $fresh->provider_status === 'frozen') {
                $restore = $this->manage->operate($refund->tenant_id, $refund->user_id, $card->id,
                    RefundCardPolicy::operationId($refund->id, $card->id, 'UNFREEZE'), 'UNFREEZE', refundId: $refund->id);
                if ($restore->status !== 'SUCCEEDED') {
                    return $this->progress($refund, 'restoring');
                }
                $fresh = $this->refresh->execute($refund->tenant_id, $refund->user_id, $card->id);
            }
            if (! in_array($fresh->provider_status, ['normal', 'cancelled'], true)) {
                return $this->progress($refund, 'restoring');
            }
        }

        return DB::transaction(function () use ($refund): SecurityDepositRefundRequest {
            $current = $this->locked($refund);
            if ($current->status === 'CHECKING' && $current->cancel_requested_at !== null) {
                $current->update(['status' => 'CANCELLED', 'progress' => 'cancelled']);
                $this->audit->record($current->tenant_id, 'SYSTEM', null, 'SECURITY_DEPOSIT_REFUND_CANCELLED', 'security_deposit_refund', $current->id);
            }

            return $current;
        });
    }

    private function operation(SecurityDepositRefundRequest $refund, UserCard $card, string $kind): ?CardManagementOrder
    {
        return CardManagementOrder::query()->where('tenant_id', $refund->tenant_id)->where('user_id', $refund->user_id)->where('card_id', $card->id)
            ->where('request_id', RefundCardPolicy::operationId($refund->id, $card->id, $kind))->where('kind', $kind)->first();
    }

    private function cards(SecurityDepositRefundRequest $refund): Builder
    {
        return UserCard::query()->where('tenant_id', $refund->tenant_id)->where('user_id', $refund->user_id);
    }

    private function progress(SecurityDepositRefundRequest $refund, string $progress): SecurityDepositRefundRequest
    {
        SecurityDepositRefundRequest::query()->where('tenant_id', $refund->tenant_id)->where('user_id', $refund->user_id)->whereKey($refund->id)
            ->where('status', 'CHECKING')->when($progress !== 'restoring', fn ($q) => $q->whereNull('cancel_requested_at'))->update(['progress' => $progress]);

        return $refund->fresh();
    }

    private function locked(SecurityDepositRefundRequest $refund): SecurityDepositRefundRequest
    {
        $tenant = Tenant::query()->whereKey($refund->tenant_id)->lockForUpdate()->firstOrFail();
        $user = User::query()->where('tenant_id', $refund->tenant_id)->whereKey($refund->user_id)->lockForUpdate()->firstOrFail();
        if ($tenant->status->value !== 'ACTIVE' || $user->status->value !== 'ACTIVE') {
            throw new DomainException('DEPOSIT_REFUND_UNAVAILABLE', 'Security deposit refunds require an active account.', 403);
        }

        return SecurityDepositRefundRequest::query()->where('tenant_id', $refund->tenant_id)->where('user_id', $refund->user_id)->whereKey($refund->id)->lockForUpdate()->firstOrFail();
    }

    private function outstanding(SecurityDepositRefundRequest $refund): bool
    {
        return CardIssueOrder::query()->where('tenant_id', $refund->tenant_id)->where('user_id', $refund->user_id)->whereIn('status', ['PROCESSING', 'UNKNOWN'])->exists()
            || CardManagementOrder::query()->where('tenant_id', $refund->tenant_id)->where('user_id', $refund->user_id)->whereIn('status', ['QUOTING', 'QUOTED', 'PROCESSING', 'UNKNOWN'])->exists();
    }
}
