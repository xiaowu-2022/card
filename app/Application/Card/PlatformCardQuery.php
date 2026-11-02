<?php

namespace App\Application\Card;

use App\Application\User\PlatformUserSummary;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\UserCard;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class PlatformCardQuery
{
    public function get(?string $company = null, ?string $search = null): array
    {
        return [
            'loads' => app(AdminCardLoadsQuery::class)->get($company, $search),
            'orders' => $this->scope(CardIssueOrder::query(), 'card_issue_orders', $company, $search)->paginate(20, ['*'], 'orders_page')->withQueryString()->through(fn (CardIssueOrder $order): array => [
                'id' => $order->id, 'tenantId' => $order->tenant_id, 'companyName' => $order->company_name,
                'userId' => $order->user_id, 'userEmail' => $order->user_email, 'productName' => $order->product->name,
                'openingFee' => $order->opening_fee, 'initialLoadAmount' => $order->initial_load_amount,
                'asset' => $order->wallet_asset, 'status' => $order->status->value,
                'requestedAt' => $order->requested_at->toIso8601String(),
            ]),
            'cards' => $this->cards($company, $search),
        ];
    }

    public function forUser(string $tenant, string $user, int $page): LengthAwarePaginator
    {
        abort_unless(DB::table('users')->where('tenant_id', $tenant)->where('id', $user)->exists(), 404);

        return PlatformUserSummary::page($this->cards($tenant, null, $user, 'page', $page));
    }

    private function scope(Builder $query, string $table, ?string $company, ?string $search): Builder
    {
        return $query->join('tenants as t', 't.id', '=', $table.'.tenant_id')
            ->join('users as u', fn ($join) => $join->on('u.id', '=', $table.'.user_id')->on('u.tenant_id', '=', $table.'.tenant_id'))
            ->when($company, fn ($q) => $q->where($table.'.tenant_id', $company))
            ->when($search, fn ($q) => $q->where(function ($q) use ($search): void {
                $pattern = '%'.addcslashes($search, '%_').'%';
                $q->where('u.email', 'ilike', $pattern)->orWhere('u.account_id', 'like', $pattern)->orWhere('u.phone', 'like', $pattern);
            }))
            ->select($table.'.*', 't.name as company_name', 'u.email as user_email')
            ->with('product:id,name,balance_limit')->orderByDesc($table.'.created_at')->orderBy($table.'.id');
    }

    private function cards(?string $company, ?string $search, ?string $user = null, string $pageName = 'cards_page', ?int $page = null): LengthAwarePaginator
    {
        $cards = $this->scope(UserCard::query(), 'user_cards', $company, $search)
            ->when($user, fn ($q) => $q->where('user_cards.user_id', $user))->selectSub(
                DB::table('card_transaction_sync_items')->selectRaw('MAX(updated_at)')
                    ->whereColumn('card_id', 'user_cards.id')->whereColumn('tenant_id', 'user_cards.tenant_id')->where('status', 'SUCCEEDED'),
                'last_transaction_sync_at'
            )->paginate(20, ['*'], $pageName, $page)->withQueryString()->through(fn (UserCard $card): array => [
                'id' => $card->id, 'tenantId' => $card->tenant_id, 'companyName' => $card->company_name,
                'userId' => $card->user_id, 'userEmail' => $card->user_email, 'productName' => $card->product->name,
                'maskedPan' => $card->masked_pan, 'currency' => $card->card_currency,
                'balance' => $card->availableBalance(), 'providerBalance' => $card->provider_balance, 'overflowBalance' => $card->overflowBalance(), 'balanceLimit' => $card->balance_limit, 'effectiveBalanceLimit' => $card->effectiveBalanceLimit(), 'providerStatus' => $card->provider_status, 'formFactor' => $card->form_factor, 'produceStatus' => $card->produce_status, 'trackingNumber' => $card->tracking_number,
                'balanceUpdatedAt' => $card->provider_balance_synced_at?->toIso8601String(),
                'lastTransactionSyncAt' => $card->last_transaction_sync_at ? CarbonImmutable::parse($card->last_transaction_sync_at)->toIso8601String() : null,
            ]);
        $actor = request()->user('platform_admin');
        $allows = fn ($permission) => $actor && app(\App\Domain\Admin\Services\AuthorizationService::class)->allows($actor, \App\Domain\Admin\Enums\ScopeType::Platform, null, $permission);
        $canSummarize = $allows('users.read') && $allows('wallet.read') && $allows('ledger.read') && $allows('cards.read');
        $identities = $allows('users.read') ? app(\App\Application\User\PlatformUserListIdentity::class)->forUsers(
            collect($cards->items())->map(fn ($row) => (object) ['tenant_id' => $row['tenantId'], 'id' => $row['userId']])->unique(fn ($u) => $u->tenant_id.':'.$u->id)->all()
        ) : [];
        $summaries = [];
        $cardIds = collect($cards->items())->pluck('id')->all();
        $fees = DB::table('card_issue_orders')->where('status', 'SUCCEEDED')
            ->whereIn('id', DB::table('user_cards')->whereIn('id', $cardIds)->select('card_issue_order_id'))
            ->get(['id', 'tenant_id', 'user_id', 'opening_fee', 'wallet_asset'])->keyBy(fn ($i) => $i->tenant_id.':'.$i->user_id.':'.$i->id);
        $issueIds = DB::table('user_cards')->whereIn('id', $cardIds)->pluck('card_issue_order_id', 'id');
        $cards->through(function ($row) use ($identities, &$summaries, $fees, $issueIds, $cardIds, $canSummarize) {
            $key = $row['tenantId'].':'.$row['userId'];
            if ($canSummarize && ! isset($summaries[$key])) {
                $reader = app(\App\Application\User\PlatformUserCardsSummary::class);
                $summaries[$key] = $reader->query($row['tenantId'], $row['userId'], false)->whereIn('id', $cardIds)->get()->merge($reader->query($row['tenantId'], $row['userId'], true)->whereIn('id', $cardIds)->get())->keyBy('id');
            }
            $summary = $canSummarize ? $summaries[$key]->get($row['id']) : null;
            $fee = $fees->get($key.':'.$issueIds->get($row['id']));
            // Consumption already deducts returns; only the list funding projection becomes net.
            $funded = $summary ? (string) BigDecimal::of($summary->funded)->minus($summary->returned)->toScale(8) : null;

            return [...$row, ...($identities[$key] ?? []), 'funded' => $funded, 'consumed' => $summary?->consumed,
                'openingFee' => $fee?->opening_fee, 'openingFeeAsset' => $fee?->wallet_asset];
        });

        return $cards;
    }
}
