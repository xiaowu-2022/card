<?php

namespace App\Application\Card;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Ledger\ValueObjects\Money;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ArchiveClearedUserCardAction
{
    public function __construct(private CardManagementAccess $access, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $userId, string $cardId, string $requestId): void
    {
        DB::transaction(function () use ($tenantId, $userId, $cardId, $requestId): void {
            $card = $this->access->card($tenantId, $userId, $cardId, lock: true);
            if ($card->archived_at !== null) {
                return;
            }
            $intent = AuditLog::query()->where('tenant_id', $tenantId)->where('actor_type', 'USER')->where('actor_id', $userId)
                ->where('resource_type', 'user_card')->where('resource_id', $cardId)->where('request_id', $requestId)
                ->where('action', 'CARD_CLEANUP_REQUESTED')->sole();
            $expected = Money::of($intent->after_data['balance_before_cancellation'], 'USD');
            $returned = CardManagementOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('card_id', $cardId)
                ->where('kind', 'CANCEL_RETURN')->where('status', 'SUCCEEDED')
                ->where(fn ($q) => $q->whereNotNull('settlement_entry_id')->orWhere('arrival_amount', '0'))->sum('debit_amount');
            if ($card->provider_status !== 'cancelled' || $card->provider_balance === null
                || ! Money::of($card->provider_balance, 'USD')->isZero()
                || ! Money::of($card->overflowBalance(), 'USD')->isZero()
                || $card->provider_balance_synced_at === null || $card->provider_balance_synced_at->lt(now()->subMinutes(5))
                || Money::of((string) $returned, 'USD')->compare($expected) < 0
                || CardManagementOrder::query()->where('tenant_id', $tenantId)->where('card_id', $cardId)
                    ->whereNotIn('status', ['SUCCEEDED', 'FAILED', 'EXPIRED'])->exists()) {
                throw new DomainException('CARD_CLEANUP_UNCONFIRMED', 'Card cancellation and balance return must be confirmed before removal.', 409);
            }
            $card->forceFill(['archived_at' => now()])->save();
            $this->audit->record($tenantId, 'USER', $userId, 'USER_CARD_ARCHIVED', 'user_card', $cardId, null,
                ['source' => 'USER_CONFIRMED_SANDBOX_CLEANUP', 'archived_at' => $card->archived_at->toIso8601String()], $requestId);
        });
    }
}
