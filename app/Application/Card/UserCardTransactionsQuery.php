<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardTransaction;
use App\Domain\Card\Models\UserCard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final readonly class UserCardTransactionsQuery
{
    public function get(string $tenantId, string $userId, string $cardId, int $page): array
    {
        $rows = $this->rows($tenantId, $userId, $cardId)->offset(($page - 1) * 20)->limit(21)->get();

        return ['page' => $page, 'hasMore' => $rows->count() > 20,
            'items' => $rows->take(20)->map($this->item(...))->all()];
    }

    public function items(string $tenantId, string $userId, string $cardId, array $ids): array
    {
        return $this->rows($tenantId, $userId, $cardId)->whereIn('card_transactions.id', $ids)->get()->map($this->item(...))->all();
    }

    private function rows(string $tenantId, string $userId, string $cardId): Builder
    {
        $card = UserCard::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($cardId)->firstOrFail();

        return CardTransaction::query()->where('card_transactions.tenant_id', $tenantId)
            ->where('card_transactions.user_id', $userId)->where('card_transactions.card_id', $cardId)
            ->leftJoin('card_management_orders as o', function ($join): void {
                $join->on('o.tenant_id', '=', 'card_transactions.tenant_id')->on('o.user_id', '=', 'card_transactions.user_id')
                    ->on('o.card_id', '=', 'card_transactions.card_id')->on('o.provider_transaction_id', '=', 'card_transactions.provider_transaction_id')
                    ->where('o.status', 'SUCCEEDED');
            })->leftJoin('ledger_entries as e', function ($join): void {
                $join->on('e.id', '=', 'o.settlement_entry_id')->on('e.tenant_id', '=', 'o.tenant_id')
                    ->on('e.reference_id', '=', 'o.id')->where('e.reference_type', 'CARD_MANAGEMENT_ORDER')->whereNotNull('e.sealed_at');
            })->select('card_transactions.*')->selectRaw('COALESCE(e.posted_at, card_transactions.created_at) AS display_at, e.id AS completed_entry_id')
            ->selectRaw('? AS last4', [$card->last4])->orderByDesc('display_at')->orderBy('card_transactions.id');
    }

    private function item(CardTransaction $row): array
    {
        return ['id' => hash('sha256', $row->card_id."\0".$row->provider_transaction_id), 'cardId' => $row->card_id,
            'last4' => $row->last4, 'amount' => $row->amount, 'currency' => $row->currency, 'type' => $row->type,
            'state' => $row->state, 'displayAt' => CarbonImmutable::parse($row->display_at)->utc()->toIso8601String(),
            'timeKind' => $row->completed_entry_id ? 'completed' : 'recorded', 'merchant' => $row->merchant];
    }
}
