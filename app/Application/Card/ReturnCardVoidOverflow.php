<?php

namespace App\Application\Card;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReturnCardVoidOverflow
{
    public function execute(object $item, AdminUser $actor): void
    {
        DB::transaction(function () use ($item, $actor): void {
            abort_unless(CardVoidPolicy::allowed($actor->fresh()), 403);
            $card = app(CardManagementAccess::class)->card($item->tenant_id, $item->user_id, $item->card_id, lock: true);
            $current = DB::table('card_void_items')->where('id', $item->id)->where('lease_token', $item->lease_token)->where('lease_until', '>', now())->lockForUpdate()->first();
            abort_unless($current && $current->stage === 'OVERFLOW' && $current->status === 'RUNNING'
                && DB::table('card_void_batches')->where('id', $current->batch_id)->where('actor_id', $actor->id)->exists(), 409);
            $request = CardVoidPolicy::operationId($current, 'OVERFLOW');
            if (DB::table('card_overflow_movements')->where('tenant_id', $item->tenant_id)->where('card_id', $item->card_id)->where('request_id', $request)->exists()) {
                return;
            }
            abort_unless($card->provider_balance !== null && Money::of($card->provider_balance, 'USD')->isZero(), 409);
            $wallet = app(CardManagementAccess::class)->wallet($item->tenant_id, $item->user_id);
            $overflow = LedgerAccount::where('tenant_id', $item->tenant_id)->where('user_id', $item->user_id)->where('card_id', $item->card_id)
                ->where('account_type', 'USER_CARD_OVERFLOW')->where('asset_code', 'USDT')->first();
            if (! $overflow || Money::of($overflow->balance, 'USDT')->isZero()) {
                return;
            }
            $available = LedgerAccount::where('tenant_id', $item->tenant_id)->where('user_id', $item->user_id)->where('wallet_id', $wallet->id)
                ->where('account_type', 'USER_AVAILABLE')->where('asset_code', 'USDT')->firstOrFail();
            $amount = Money::of($overflow->balance, 'USDT');
            $id = (string) Str::uuid();
            $entry = app(LedgerWriter::class)->post(new LedgerPostingPlan($item->tenant_id, 'USDT', 'card_overflow:'.$id,
                'CARD_OVERFLOW_RETURN', 'CARD_OVERFLOW_MOVEMENT', $id, null, [
                    new LedgerPostingInstruction($overflow->id, Money::of('0', 'USDT')->subtract($amount)),
                    new LedgerPostingInstruction($available->id, $amount),
                ]));
            DB::table('card_overflow_movements')->insert(['id' => $id, 'tenant_id' => $item->tenant_id, 'user_id' => $item->user_id,
                'card_id' => $item->card_id, 'void_item_id' => $item->id, 'order_id' => null, 'kind' => 'RETURN', 'amount' => $amount->amount(),
                'request_id' => $request, 'ledger_entry_id' => $entry->id, 'actor_id' => $actor->id, 'note' => null, 'created_at' => now()]);
            app(AuditLogger::class)->record($item->tenant_id, 'ADMIN', $actor->id, 'CARD_VOID_OVERFLOW_RETURNED', 'user_card', $item->card_id,
                null, ['amount' => $amount->amount(), 'void_item_id' => $item->id, 'movement_id' => $id], $request);
        });
    }
}
