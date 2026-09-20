<?php

namespace App\Application\Card;

use App\Domain\Card\Models\UserCard;
use App\Domain\Tenant\Models\Tenant;

final readonly class PlatformCardTransactionsQuery
{
    public function __construct(private UserCardTransactionsQuery $transactions) {}

    public function get(string $tenantId, string $cardId, int $page): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        $card = UserCard::query()->where('tenant_id', $tenantId)->whereKey($cardId)->firstOrFail();

        return [
            ...$this->transactions->get($tenantId, $card->user_id, $card->id, $page, admin: true),
            'timezone' => $tenant->timezone,
        ];
    }
}
