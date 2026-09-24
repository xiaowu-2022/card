<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Models\UserCard;

final class TenantAdminCardQuery
{
    /** @return array<string,mixed> */
    public function get(string $tenantId): array
    {
        return [
            'loads' => app(AdminCardLoadsQuery::class)->get($tenantId),
            'cardholders' => ProviderCardholder::query()->where('tenant_id', $tenantId)->with('user:id,tenant_id,email')
                ->latest('updated_at')->limit(100)->get()->map(fn (ProviderCardholder $holder): array => [
                    'id' => $holder->id,
                    'userEmail' => $holder->user->email,
                    'status' => $holder->status->value,
                    'safeReason' => $holder->safe_reason,
                    'updatedAt' => $holder->updated_at->toIso8601String(),
                ])->all(),
            'orders' => CardIssueOrder::query()->where('tenant_id', $tenantId)->with(['user:id,tenant_id,email', 'product:id,name'])
                ->latest('created_at')->limit(100)->get()->map(fn (CardIssueOrder $order): array => [
                    'id' => $order->id,
                    'userEmail' => $order->user->email,
                    'productName' => $order->product->name,
                    'openingFee' => $order->opening_fee,
                    'initialLoadAmount' => $order->initial_load_amount,
                    'status' => $order->status->value,
                    'requestedAt' => $order->requested_at->toIso8601String(),
                ])->all(),
            'cards' => UserCard::query()->where('tenant_id', $tenantId)->with(['user:id,tenant_id,email', 'product:id,name'])
                ->latest('created_at')->limit(100)->get()->map(fn (UserCard $card): array => [
                    'id' => $card->id,
                    'userEmail' => $card->user->email,
                    'productName' => $card->product->name,
                    'maskedPan' => $card->masked_pan,
                    'currency' => $card->card_currency,
                    'balance' => $card->availableBalance(), 'providerBalance' => $card->provider_balance, 'overflowBalance' => $card->overflowBalance(), 'balanceLimit' => $card->balance_limit,
                    'providerStatus' => $card->provider_status, 'formFactor' => $card->form_factor, 'produceStatus' => $card->produce_status, 'trackingNumber' => $card->tracking_number,
                ])->all(),
        ];
    }
}
