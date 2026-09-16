<?php

namespace App\Application\Assets;

use App\Domain\Assets\ExchangeOrder;
use App\Domain\Assets\ExchangePolicy;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final readonly class ExchangeAssetsAction
{
    public function __construct(private AssetAccess $access, private MarketPrices $prices, private LedgerWriter $ledger, private AuditLogger $audit) {}

    public function quote(string $tenantId, string $userId, string $asset, string $amount, string $requestId): ExchangeOrder
    {
        AssetAccess::requestId($requestId);
        if (! in_array($asset, ['USDC', 'ETH', 'BTC'], true)) {
            throw new DomainException('ASSET_INVALID', 'Select a supported asset.');
        }
        try {
            $money = Money::of($amount, $asset);
        } catch (\InvalidArgumentException) {
            throw new DomainException('AMOUNT_INVALID', 'Enter an amount within the currency precision.');
        }
        if (! $money->isPositive()) {
            throw new DomainException('AMOUNT_INVALID', 'Enter a positive amount.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $asset, $money, $requestId): ExchangeOrder {
            AssetAccess::lock('asset-exchange:'.$tenantId.':'.$userId.':'.$requestId);
            $existing = ExchangeOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('request_id', $requestId)->first();
            if ($existing) {
                if ($existing->asset_code !== $asset || $existing->amount !== $money->amount()) {
                    throw new DomainException('IDEMPOTENCY_CONFLICT', 'This request identifier was already used with different details.', 409);
                }

                return $existing;
            }
            [$tenant,$user] = $this->access->operational($tenantId, $userId);
            $policy = $this->policy($tenantId, $asset);
            $snapshot = $this->prices->latest();
            if (! $snapshot) {
                throw new DomainException('ASSET_PRICES_UNAVAILABLE', 'Market prices are unavailable.', 503);
            }
            $rate = $this->prices->rate($snapshot, $asset);
            $gross = BigDecimal::of($money->amount())->multipliedBy($rate)->toScale(8, RoundingMode::Down);
            $fee = $gross->multipliedBy($policy->fee_percent)->dividedBy('100', 8, RoundingMode::Ceiling);
            $net = $gross->minus($fee);
            Money::of((string) $gross, 'USDT');
            if (! $net->isPositive() || $gross->isGreaterThan($policy->single_limit)) {
                throw new DomainException('EXCHANGE_LIMIT', 'The amount is outside the exchange limits.');
            }
            // A quote reads existing source funds; it neither creates wallets nor holds money.
            $available = LedgerAccount::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', $asset)->where('account_type', 'USER_AVAILABLE')->first();
            if (! $available || Money::of($available->balance, $asset)->compare($money) < 0) {
                throw new DomainException('INSUFFICIENT_AVAILABLE_BALANCE', 'Your available balance is not enough.');
            }

            return ExchangeOrder::query()->create(['tenant_id' => $tenantId, 'user_id' => $userId, 'request_id' => $requestId, 'asset_code' => $asset, 'amount' => $money->amount(), 'rate' => (string) $rate, 'gross_amount' => (string) $gross, 'fee_amount' => (string) $fee, 'receive_amount' => (string) $net, 'fee_percent' => $policy->fee_percent, 'snapshot_id' => $snapshot->id, 'expires_at' => now()->addSeconds(30)]);
        }, 3);
    }

    public function confirm(string $tenantId, string $userId, string $id): ExchangeOrder
    {
        return DB::transaction(function () use ($tenantId, $userId, $id): ExchangeOrder {
            $order = ExchangeOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($order->status === 'COMPLETED') {
                return $order;
            }
            [$tenant,$user] = $this->access->operational($tenantId, $userId);
            if ($order->expires_at->lessThanOrEqualTo(now())) {
                throw new DomainException('EXCHANGE_EXPIRED', 'The quote has expired. Request a new quote.', 409);
            }
            $policy = $this->policy($tenantId, $order->asset_code);
            $start = now($tenant->timezone)->startOfDay()->utc();
            $used = (string) ExchangeOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', $order->asset_code)->where('status', 'COMPLETED')->where('completed_at', '>=', $start)->sum('gross_amount');
            if (BigDecimal::of($order->gross_amount)->isGreaterThan($policy->single_limit) || BigDecimal::of($used)->plus($order->gross_amount)->isGreaterThan($policy->daily_limit)) {
                throw new DomainException('EXCHANGE_LIMIT', 'The amount is outside the exchange limits.');
            }
            $source = $this->access->wallet($tenant, $user, $order->asset_code);
            $target = $this->access->wallet($tenant, $user, 'USDT');
            $sourceAccount = $this->access->account($source, 'USER_AVAILABLE');
            $targetAccount = $this->access->account($target, 'USER_AVAILABLE');
            $sourceClearing = $this->access->companyAccount($tenantId, $order->asset_code, 'TENANT_EXCHANGE_CLEARING');
            $targetClearing = $this->access->companyAccount($tenantId, 'USDT', 'TENANT_EXCHANGE_CLEARING');
            $sourceEntry = $this->ledger->post(new LedgerPostingPlan($tenantId, $order->asset_code, 'exchange:'.$id.':source', 'ASSET_EXCHANGE_OUT', 'ASSET_EXCHANGE', $id, null, [
                new LedgerPostingInstruction($sourceAccount->id, Money::of('-'.$order->amount, $order->asset_code)),
                new LedgerPostingInstruction($sourceClearing->id, Money::of($order->amount, $order->asset_code)),
            ]));
            $postings = [new LedgerPostingInstruction($targetClearing->id, Money::of('-'.$order->gross_amount, 'USDT')), new LedgerPostingInstruction($targetAccount->id, Money::of($order->receive_amount, 'USDT'))];
            if (Money::of($order->fee_amount, 'USDT')->isPositive()) {
                $postings[] = new LedgerPostingInstruction($this->access->companyAccount($tenantId, 'USDT', 'TENANT_FEE_REVENUE')->id, Money::of($order->fee_amount, 'USDT'));
            }
            $targetEntry = $this->ledger->post(new LedgerPostingPlan($tenantId, 'USDT', 'exchange:'.$id.':target', 'ASSET_EXCHANGE_IN', 'ASSET_EXCHANGE', $id, null, $postings));
            $order->update(['status' => 'COMPLETED', 'source_entry_id' => $sourceEntry->id, 'target_entry_id' => $targetEntry->id, 'completed_at' => now()]);
            $this->audit->record($tenantId, 'USER', $userId, 'ASSET_EXCHANGED', 'asset_exchange_order', $id, null, ['asset' => $order->asset_code, 'amount' => $order->amount, 'receive_amount' => $order->receive_amount, 'fee_amount' => $order->fee_amount, 'snapshot_id' => $order->snapshot_id]);

            return $order->refresh();
        }, 3);
    }

    private function policy(string $tenantId, string $asset): ExchangePolicy
    {
        $policy = ExchangePolicy::query()->where('tenant_id', $tenantId)->where('asset_code', $asset)->first();
        if (! $policy?->enabled || $policy->fee_percent === null || $policy->single_limit === null || $policy->daily_limit === null) {
            throw new DomainException('EXCHANGE_UNAVAILABLE', 'Exchange is not available for this asset.', 403);
        }

        return $policy;
    }
}
