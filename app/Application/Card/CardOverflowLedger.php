<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\UserCard;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CardOverflowLedger
{
    // Caller holds the tenant, user, card and business-order locks in an outer transaction.
    public function fund(CardManagementOrder $order): void
    {
        if ($order->status !== 'SUCCEEDED' || $order->kind !== 'LOAD' || ! Money::of($order->manual_funding_amount, 'USDT')->isPositive()
            || DB::table('card_overflow_movements')->where('tenant_id', $order->tenant_id)->where('order_id', $order->id)->exists()) {
            return;
        }
        $card = UserCard::query()->where('tenant_id', $order->tenant_id)->where('user_id', $order->user_id)->whereKey($order->card_id)->firstOrFail();
        $this->post($card, $order->wallet_id, 'FUNDING', $order->manual_funding_amount, $order->id, $order->id);
    }

    public function spend(UserCard $card, string $walletId, string $amount, string $requestId, string $actorId, string $note): void
    {
        $this->post($card, $walletId, 'SPEND', $amount, $requestId, actorId: $actorId, note: $note);
    }

    private function post(UserCard $card, string $walletId, string $kind, string $amount, string $requestId, ?string $orderId = null, ?string $actorId = null, ?string $note = null): void
    {
        $account = LedgerAccount::query()->where('tenant_id', $card->tenant_id)->where('user_id', $card->user_id)->where('card_id', $card->id)->where('account_type', 'USER_CARD_OVERFLOW')->first();
        if (! $account) {
            $account = new LedgerAccount;
            $account->forceFill(['tenant_id' => $card->tenant_id, 'user_id' => $card->user_id, 'wallet_id' => $walletId,
                'card_id' => $card->id, 'account_type' => 'USER_CARD_OVERFLOW', 'asset_code' => 'USDT', 'balance' => '0', 'status' => 'ACTIVE'])->save();
        }
        $clearing = LedgerAccount::query()->where('tenant_id', $card->tenant_id)->whereNull('wallet_id')->whereNull('user_id')
            ->where('asset_code', 'USDT')->where('account_type', 'TENANT_CARD_FUNDING_CLEARING')->firstOrFail();
        $id = (string) Str::uuid();
        $delta = Money::of(($kind === 'SPEND' ? '-' : '').$amount, 'USDT');
        $entry = app(LedgerWriter::class)->post(new LedgerPostingPlan($card->tenant_id, 'USDT', 'card_overflow:'.$id,
            'CARD_OVERFLOW_'.$kind, 'CARD_OVERFLOW_MOVEMENT', $id, null, [
                new LedgerPostingInstruction($account->id, $delta),
                new LedgerPostingInstruction($clearing->id, Money::of('0', 'USDT')->subtract($delta)),
            ]));
        DB::table('card_overflow_movements')->insert(['id' => $id, 'tenant_id' => $card->tenant_id, 'user_id' => $card->user_id,
            'card_id' => $card->id, 'order_id' => $orderId, 'kind' => $kind, 'amount' => $amount, 'request_id' => $requestId,
            'ledger_entry_id' => $entry->id, 'actor_id' => $actorId, 'note' => $note, 'created_at' => now()]);
    }
}
