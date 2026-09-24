<?php

namespace App\Application\Card;

use App\Domain\Card\Models\UserCard;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Errors\DomainException;

final readonly class CardManagementAccess
{
    public function __construct(private CardProviderInterface $provider) {}

    public function card(string $tenantId, string $userId, string $cardId, bool $lock = false, bool $requireActive = true, bool $requireProvider = true): UserCard
    {
        $tenant = Tenant::query()->whereKey($tenantId)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $card = UserCard::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($cardId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        if ($requireProvider) {
            LiveCardReferenceGuard::forProduct($card->product, $card->provider_card_id);
        }
        if ($requireActive && ($tenant->status->value !== 'ACTIVE' || $user->status->value !== 'ACTIVE')) {
            throw new DomainException('CARD_MANAGEMENT_RESTRICTED', 'Card management requires an active account.', 403);
        }
        if ($requireProvider && ($card->provider !== 'PHOTONPAY' || $card->card_currency !== 'USD' || ! app(CardProductProviderRouter::class)->forCard($card)->available())) {
            throw new DomainException('CARD_MANAGEMENT_UNAVAILABLE', 'Card management is currently unavailable.', 503);
        }

        return $card;
    }

    public function wallet(string $tenantId, string $userId, bool $requireActive = true): Wallet
    {
        $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', 'USDT')->firstOrFail();
        if ($wallet->asset_code !== 'USDT' || ($requireActive && $wallet->status->value !== 'ACTIVE')) {
            throw new DomainException('CARD_MANAGEMENT_UNAVAILABLE', 'Card management is currently unavailable.', 409);
        }

        return $wallet;
    }
}
