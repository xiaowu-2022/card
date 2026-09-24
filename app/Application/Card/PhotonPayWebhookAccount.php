<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Support\Errors\DomainException;
use Illuminate\Support\Str;

final class PhotonPayWebhookAccount
{
    /** Unverified identifiers select a stored verification key only; no business changes occur here. */
    public function resolve(#[\SensitiveParameter] string $body, ?string $accountId): CardProviderReference
    {
        if ($accountId !== null) {
            $account = CardProviderReference::find($accountId);
        } else {
            $json = strlen($body) <= 2097152 ? json_decode($body, true, 32) : null;
            if (! is_array($json)) {
                $this->reject();
            }
            $accounts = [];
            foreach ([['cardId', UserCard::class, 'provider_card_id'], ['cardholderId', ProviderCardholder::class, 'provider_cardholder_id'], ['requestId', CardIssueOrder::class, 'id']] as [$field, $model, $column]) {
                $value = $json[$field] ?? null;
                if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_-]{1,180}$/D', $value)) {
                    continue;
                }
                if ($column === 'id' && ! Str::isUuid($value)) {
                    continue;
                }
                foreach ($model::where('provider', 'PHOTONPAY')->where($column, $value)->pluck('card_product_id') as $productId) {
                    $accounts[] = CardProduct::whereKey($productId)->value('card_provider_reference_id');
                }
            }
            $accounts = array_values(array_unique($accounts));
            if (count($accounts) !== 1 || ! $accounts[0]) {
                $this->reject();
            }
            $account = CardProviderReference::find($accounts[0]);
        }
        if (! $account || ! $account->photonpay_webhook_key_encrypted || $account->photonpay_migration_error) {
            $this->reject();
        }

        return $account;
    }

    private function reject(): never
    {
        throw new DomainException('CARD_WEBHOOK_UNMAPPED', 'Notification account mapping is unavailable or ambiguous.', 404);
    }
}
