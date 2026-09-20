<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardTransaction;
use App\Domain\Card\Models\UserCard;
use App\Domain\Ledger\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class UserCardTransactionsQuery
{
    public function get(string $tenantId, string $userId, string $cardId, int $page, bool $admin = false): array
    {
        $provider = $this->rows($tenantId, $userId, $cardId)->reorder()
            ->where(fn ($q) => $q->whereNull('o.id')->orWhere('o.manual_funding_amount', 0))
            ->select('card_transactions.id', 'card_transactions.card_id', 'card_transactions.provider_transaction_id', 'card_transactions.amount', 'card_transactions.currency', 'card_transactions.type', 'card_transactions.state', 'card_transactions.merchant')
            ->selectRaw('COALESCE(e.posted_at, card_transactions.created_at) AS display_at, e.id AS completed_entry_id');
        $loads = DB::table('card_management_orders as o')->join('ledger_entries as e', 'e.id', '=', 'o.settlement_entry_id')
            ->where('o.tenant_id', $tenantId)->where('o.user_id', $userId)->where('o.card_id', $cardId)
            ->where('e.tenant_id', $tenantId)->whereNotNull('e.sealed_at')->where('o.status', 'SUCCEEDED')->where('o.kind', 'LOAD')->where('o.manual_funding_amount', '>', 0)
            ->selectRaw("o.id,o.card_id,'local-load:'||o.id AS provider_transaction_id,(o.arrival_amount+o.manual_funding_amount) AS amount,'USD' AS currency,'transfer_in' AS type,'completed' AS state,NULL::text AS merchant,e.posted_at AS display_at,e.id AS completed_entry_id");
        $spends = DB::table('card_overflow_movements as m')->join('ledger_entries as e', 'e.id', '=', 'm.ledger_entry_id')
            ->where('m.tenant_id', $tenantId)->where('m.user_id', $userId)->where('m.card_id', $cardId)->where('m.kind', 'SPEND')
            ->where('e.tenant_id', $tenantId)->whereNotNull('e.sealed_at')
            ->selectRaw("m.id,m.card_id,'local-spend:'||m.id AS provider_transaction_id,m.amount,'USD' AS currency,'purchase' AS type,'completed' AS state,NULL::text AS merchant,e.posted_at AS display_at,e.id AS completed_entry_id");
        $last4 = UserCard::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($cardId)->value('last4');
        $rows = DB::query()->fromSub($provider->toBase()->unionAll($loads)->unionAll($spends), 'activity')
            ->select('activity.*')->selectRaw('? AS last4', [$last4])->orderByDesc('display_at')->orderBy('id')
            ->offset(($page - 1) * 20)->limit(21)->get();

        return ['page' => $page, 'hasMore' => $rows->count() > 20,
            'items' => $rows->take(20)->map(function ($row) use ($admin, $tenantId, $cardId): array {
                $item = $this->item($row);
                if ($admin && str_starts_with($row->provider_transaction_id, 'local-spend:')) {
                    $record = DB::table('card_overflow_movements as m')->leftJoin('admin_users as a', 'a.id', '=', 'm.actor_id')
                        ->where('m.tenant_id', $tenantId)->where('m.card_id', $cardId)->where('m.id', $row->id)->first(['m.note', 'a.email']);
                    $item['operator'] = $record?->email;
                    $item['note'] = $record?->note;
                }

                return $item;
            })->all()];
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
            ->selectRaw("CASE WHEN o.manual_funding_amount > 0 THEN 'local-load:'||o.id ELSE card_transactions.provider_transaction_id END AS provider_transaction_id, CASE WHEN o.manual_funding_amount > 0 THEN o.arrival_amount+o.manual_funding_amount ELSE card_transactions.amount END AS amount")
            ->selectRaw('? AS last4', [$card->last4])->orderByDesc('display_at')->orderBy('card_transactions.id');
    }

    private function item(object $row): array
    {
        return ['id' => hash('sha256', $row->card_id."\0".$row->provider_transaction_id), 'cardId' => $row->card_id,
            'last4' => $row->last4, 'amount' => Money::of((string) $row->amount, 'USD')->amount(), 'currency' => $row->currency, 'type' => $row->type,
            'state' => $row->state, 'displayAt' => CarbonImmutable::parse($row->display_at)->utc()->toIso8601String(),
            'timeKind' => $row->completed_entry_id ? 'completed' : 'recorded', 'merchant' => $row->merchant];
    }
}
