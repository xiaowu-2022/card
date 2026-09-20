<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\UserCard;

final readonly class UserCardOverviewQuery
{
    public function get(string $tenantId, string $userId): array
    {
        $cards = UserCard::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereNull('archived_at');

        return ['count' => (clone $cards)->count(), 'items' => (clone $cards)->latest('created_at')->limit(2)->get()->map(fn ($card) => [
            'id' => $card->id, 'last4' => $card->last4, 'balance' => $card->availableBalance(),
            'state' => match ($card->provider_status) {
                'normal' => 'Normal', 'frozen' => 'Frozen', 'expired' => 'Expired', default => 'Awaiting confirmation'
            },
        ])->all(), 'pending' => CardManagementOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereIn('status', ['PROCESSING', 'UNKNOWN', 'QUOTING'])->count()];
    }
}
