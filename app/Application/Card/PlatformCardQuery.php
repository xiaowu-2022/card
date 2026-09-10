<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\UserCard;

final class PlatformCardQuery
{
    /** @return array<string,mixed> */
    public function get(): array
    {
        return [
            'orders' => CardIssueOrder::query()->with(['user:id,tenant_id,email', 'product:id,name'])
                ->latest('created_at')->limit(100)->get()->map(fn (CardIssueOrder $order): array => [
                    'id' => $order->id,
                    'tenantId' => $order->tenant_id,
                    'userEmail' => $order->user->email,
                    'productName' => $order->product->name,
                    'openingFee' => $order->opening_fee,
                    'initialLoadAmount' => $order->initial_load_amount,
                    'status' => $order->status->value,
                    'requestedAt' => $order->requested_at->toIso8601String(),
                ])->all(),
            'cards' => UserCard::query()->with(['user:id,tenant_id,email', 'product:id,name'])
                ->latest('created_at')->limit(100)->get()->map(fn (UserCard $card): array => [
                    'id' => $card->id,
                    'tenantId' => $card->tenant_id,
                    'userEmail' => $card->user->email,
                    'productName' => $card->product->name,
                    'maskedPan' => $card->masked_pan,
                    'currency' => $card->card_currency,
                    'balance' => $card->provider_balance,
                    'providerStatus' => $card->provider_status,
                ])->all(),
        ];
    }
}
