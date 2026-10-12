<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\UserCard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
                ->with('product:id,name,balance_limit')->orderByDesc($table.'.created_at')->orderBy($table.'.id');
        };

        $result = [
            'loads' => app(AdminCardLoadsQuery::class)->get($company, $search),
            'orders' => $scope(CardIssueOrder::query(), 'card_issue_orders')->paginate(20, ['*'], 'orders_page')->withQueryString()->through(fn (CardIssueOrder $order): array => [
                'id' => $order->id, 'tenantId' => $order->tenant_id, 'companyName' => $order->company_name,
                'userId' => $order->user_id, 'userEmail' => $order->user_email, 'productName' => $order->product->name,
                'openingFee' => $order->opening_fee, 'initialLoadAmount' => $order->initial_load_amount,
                'asset' => $order->wallet_asset, 'status' => $order->status->value,
                'requestedAt' => $order->requested_at->toIso8601String(),
            ]),
            'cards' => $scope(UserCard::query(), 'user_cards')->selectSub(
                DB::table('card_transaction_sync_items')->selectRaw('MAX(updated_at)')
                    ->whereColumn('card_id', 'user_cards.id')->whereColumn('tenant_id', 'user_cards.tenant_id')->where('status', 'SUCCEEDED'),
                'last_transaction_sync_at'
            )->paginate(20, ['*'], 'cards_page')->withQueryString()->through(fn (UserCard $card): array => [
                'id' => $card->id, 'tenantId' => $card->tenant_id, 'companyName' => $card->company_name,
                'userId' => $card->user_id, 'userEmail' => $card->user_email, 'productName' => $card->product->name,
                'maskedPan' => $card->masked_pan, 'currency' => $card->card_currency,
                'balance' => $card->availableBalance(), 'providerBalance' => $card->provider_balance, 'overflowBalance' => $card->overflowBalance(), 'balanceLimit' => $card->balance_limit, 'effectiveBalanceLimit' => $card->effectiveBalanceLimit(), 'providerStatus' => $card->provider_status, 'formFactor' => $card->form_factor, 'produceStatus' => $card->produce_status, 'trackingNumber' => $card->tracking_number,
                'balanceUpdatedAt' => $card->provider_balance_synced_at?->toIso8601String(),
                'lastTransactionSyncAt' => $card->last_transaction_sync_at ? CarbonImmutable::parse($card->last_transaction_sync_at)->toIso8601String() : null,
            ]),
        ];
        $actor = request()->user('platform_admin');
        $allows = fn ($permission) => $actor && app(\App\Domain\Admin\Services\AuthorizationService::class)->allows($actor, \App\Domain\Admin\Enums\ScopeType::Platform, null, $permission);
        $canSummarize = $allows('users.read') && $allows('wallet.read') && $allows('ledger.read') && $allows('cards.read');
        $identities = $allows('users.read') ? app(\App\Application\User\PlatformUserListIdentity::class)->forUsers(
            collect($result['cards']->items())->map(fn ($row) => (object) ['tenant_id' => $row['tenantId'], 'id' => $row['userId']])->unique(fn ($u) => $u->tenant_id.':'.$u->id)->all()
        ) : [];
        $summaries = [];
        $cardIds = collect($result['cards']->items())->pluck('id')->all();
        $fees = DB::table('card_issue_orders')->where('status', 'SUCCEEDED')
            ->whereIn('id', DB::table('user_cards')->whereIn('id', collect($result['cards']->items())->pluck('id'))->select('card_issue_order_id'))
            ->get(['id', 'tenant_id', 'user_id', 'opening_fee', 'wallet_asset'])->keyBy(fn ($i) => $i->tenant_id.':'.$i->user_id.':'.$i->id);
        $issueIds = DB::table('user_cards')->whereIn('id', collect($result['cards']->items())->pluck('id'))->pluck('card_issue_order_id', 'id');
        $result['cards']->through(function ($row) use ($identities, &$summaries, $fees, $issueIds, $cardIds, $canSummarize) {
            $key = $row['tenantId'].':'.$row['userId'];
            if ($canSummarize && ! isset($summaries[$key])) {
                $reader = app(\App\Application\User\PlatformUserCardsSummary::class);
                $summaries[$key] = $reader->query($row['tenantId'], $row['userId'], false)->whereIn('id', $cardIds)->get()->merge($reader->query($row['tenantId'], $row['userId'], true)->whereIn('id', $cardIds)->get())->keyBy('id');
            }
            $summary = $canSummarize ? $summaries[$key]->get($row['id']) : null;
            $fee = $fees->get($key.':'.$issueIds->get($row['id']));

            return [...$row, ...($identities[$key] ?? []), 'funded' => $summary?->funded, 'consumed' => $summary?->consumed,
                'openingFee' => $fee?->opening_fee, 'openingFeeAsset' => $fee?->wallet_asset];
        });

        return $result;
    }
}
