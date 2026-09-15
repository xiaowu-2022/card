<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardTransaction;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProvider\DTOs\ProviderCardTransactionDTO;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Persists a verified read model only; never changes card or financial state. */
final readonly class RecordCardTransactionsAction
{
    /** @param array<ProviderCardTransactionDTO> $items */
    public function execute(UserCard $card, array $items, CarbonImmutable $startedAt): array
    {
        return DB::transaction(function () use ($card, $items, $startedAt): array {
            usort($items, fn ($a, $b) => strcmp($a->providerTransactionId, $b->providerTransactionId));
            $ids = [];
            foreach ($items as $item) {
                $now = CarbonImmutable::now();
                $fields = ['amount' => $item->amount, 'currency' => $item->currency, 'type' => $item->type,
                    'state' => $item->state, 'occurred_at' => $item->occurredAt, 'merchant' => $item->merchant,
                    'refresh_generation' => $card->refresh_generation, 'updated_at' => $now->format('Y-m-d H:i:s.uP')];
                DB::table('card_transactions')->insertOrIgnore($fields + ['id' => (string) Str::uuid(),
                    'tenant_id' => $card->tenant_id, 'user_id' => $card->user_id, 'card_id' => $card->id,
                    'provider_transaction_id' => $item->providerTransactionId, 'created_at' => $now->format('Y-m-d H:i:s.uP')]);
                $row = CardTransaction::query()->where('tenant_id', $card->tenant_id)->where('user_id', $card->user_id)
                    ->where('card_id', $card->id)->where('provider_transaction_id', $item->providerTransactionId)->lockForUpdate()->firstOrFail();
                // A response that raced with another observation cannot overwrite it.
                // The next explicit sync can refresh it; first recorded time never changes.
                if ($row->updated_at->lessThanOrEqualTo($startedAt)) {
                    $row->forceFill($fields)->save();
                }
                $ids[] = $row->id;
            }

            return $ids;
        });
    }
}
