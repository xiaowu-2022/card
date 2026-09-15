<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\UserCard;

final class PlatformCardQuery
{
    public function get(?string $company = null, ?string $search = null): array
    {
        $scope = function ($query, string $table) use ($company, $search) {
            return $query->join('tenants as t', 't.id', '=', $table.'.tenant_id')
                ->join('users as u', fn ($join) => $join->on('u.id', '=', $table.'.user_id')->on('u.tenant_id', '=', $table.'.tenant_id'))
                ->when($company, fn ($q) => $q->where($table.'.tenant_id', $company))
                ->when($search, fn ($q) => $q->where(function ($q) use ($search): void {
                    $pattern = '%'.addcslashes($search, '%_').'%';
                    $q->where('u.email', 'ilike', $pattern)->orWhere('u.account_id', 'like', $pattern)->orWhere('u.phone', 'like', $pattern);
                }))
                ->select($table.'.*', 't.name as company_name', 'u.email as user_email')
                ->with('product:id,name')->orderByDesc($table.'.created_at')->orderBy($table.'.id');
        };

        return [
            'orders' => $scope(CardIssueOrder::query(), 'card_issue_orders')->paginate(20, ['*'], 'orders_page')->withQueryString()->through(fn (CardIssueOrder $order): array => [
                'id' => $order->id, 'tenantId' => $order->tenant_id, 'companyName' => $order->company_name,
                'userEmail' => $order->user_email, 'productName' => $order->product->name,
                'openingFee' => $order->opening_fee, 'initialLoadAmount' => $order->initial_load_amount,
                'asset' => $order->wallet_asset, 'status' => $order->status->value,
                'requestedAt' => $order->requested_at->toIso8601String(),
            ]),
            'cards' => $scope(UserCard::query(), 'user_cards')->paginate(20, ['*'], 'cards_page')->withQueryString()->through(fn (UserCard $card): array => [
                'id' => $card->id, 'tenantId' => $card->tenant_id, 'companyName' => $card->company_name,
                'userEmail' => $card->user_email, 'productName' => $card->product->name,
                'maskedPan' => $card->masked_pan, 'currency' => $card->card_currency,
                'balance' => $card->provider_balance, 'providerStatus' => $card->provider_status,
                'balanceUpdatedAt' => $card->provider_balance_synced_at?->toIso8601String(),
            ]),
        ];
    }
}
