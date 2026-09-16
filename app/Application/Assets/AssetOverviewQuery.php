<?php

namespace App\Application\Assets;

use App\Domain\Assets\AssetCatalog;
use App\Domain\Assets\AssetDepositOrder;
use App\Domain\Assets\AssetRail;
use App\Domain\Assets\AssetWithdrawalOrder;
use App\Domain\Assets\ChainConnection;
use App\Domain\Assets\CompanyRail;
use App\Domain\Assets\ExchangeOrder;
use App\Domain\Assets\ExchangePolicy;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Wallet\Models\Wallet;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final readonly class AssetOverviewQuery
{
    public function __construct(private MarketPrices $prices) {}

    /** One read snapshot for all balances. No account creation on GET. */
    public function get(string $tenantId, string $userId, array $legacy): array
    {
        $accounts = LedgerAccount::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->get();
        $eligible = $legacy['transferAvailable'] ?? false;
        $wallets = Wallet::where('tenant_id', $tenantId)->where('user_id', $userId)->get()->keyBy('asset_code');
        $snapshot = $this->prices->latest();
        $total = BigDecimal::of('0');
        $assets = [];
        $rails = AssetRail::query()->where('enabled', true)->get();
        $connections = ChainConnection::query()->where('enabled', true)->whereNotNull('next_height')->get()->keyBy('network');
        $company = CompanyRail::query()->where('tenant_id', $tenantId)->get()->keyBy('rail_code');
        $policies = ExchangePolicy::query()->where('tenant_id', $tenantId)->get()->keyBy('asset_code');
        foreach (AssetCatalog::ASSETS as $asset) {
            $assetEligible = $eligible && (! $wallets->has($asset) || $wallets->get($asset)->status->value === 'ACTIVE');
            $own = $accounts->where('asset_code', $asset);
            $balance = fn (string $type): string => Money::of($own->first(fn ($a) => $a->account_type->value === $type)?->balance ?? '0', $asset)->amount();
            $held = BigDecimal::of('0');
            foreach (['USER_WITHDRAWAL_HOLD', 'USER_CARD_ISSUE_HOLD', 'USER_CARD_FUNDING_HOLD'] as $type) {
                $held = $held->plus($balance($type));
            }
            $available = $balance('USER_AVAILABLE');
            $deposit = $asset === 'USDT' ? $balance('USER_SECURITY_DEPOSIT') : '0';
            $commission = $asset === 'USDT' ? $balance('USER_COMMISSION') : '0';
            $native = BigDecimal::of($available)->plus($held)->plus($deposit)->plus($commission);
            if ($snapshot) {
                $total = $total->plus($native->multipliedBy($asset === 'USDT' ? '1' : $this->prices->rate($snapshot, $asset)));
            }
            $options = [];
            if ($asset === 'USDT' && (($legacy['topupAvailable'] ?? false) || ($legacy['withdrawalAvailable'] ?? false))) {
                $options[] = ['code' => 'USDT_TRON', 'network' => 'TRON', 'deposit' => (bool) $legacy['topupAvailable'], 'withdrawal' => (bool) $legacy['withdrawalAvailable'], 'fee' => null, 'minimum' => null];
            }
            foreach ($rails->where('asset_code', $asset) as $rail) {
                $settings = $company->get($rail->code);
                if (! $settings || ! $connections->has($rail->network) || ! $rail->deposit_address) {
                    continue;
                }
                $options[] = ['code' => $rail->code, 'network' => $rail->network, 'deposit' => $assetEligible && $settings->deposit_enabled && $settings->minimum_deposit !== null, 'withdrawal' => $assetEligible && $settings->withdrawal_enabled && $settings->withdrawal_fee !== null, 'fee' => $settings->withdrawal_fee === null ? null : Money::of($settings->withdrawal_fee, $asset)->amount(), 'minimum' => $settings->minimum_deposit === null ? null : Money::of($settings->minimum_deposit, $asset)->amount()];
            }
            $policy = $policies->get($asset);
            $activity = DB::table('ledger_postings as p')->join('ledger_accounts as a', 'a.id', '=', 'p.ledger_account_id')->join('ledger_entries as e', 'e.id', '=', 'p.ledger_entry_id')->where('a.tenant_id', $tenantId)->where('p.tenant_id', $tenantId)->where('e.tenant_id', $tenantId)->where('a.user_id', $userId)->where('a.asset_code', $asset)->where('a.account_type', 'USER_AVAILABLE')->orderByDesc('e.posted_at')->orderByDesc('p.id')->limit(5)->get(['p.id', 'p.delta', 'e.posted_at', 'e.event_type'])->map(fn ($p) => ['id' => $p->id, 'amount' => Money::of($p->delta, $asset)->amount(), 'time' => $p->posted_at, 'kind' => $p->event_type === 'PROMOTION_ANNUAL_FEE' ? 'Promotion annual fee paid' : ($p->event_type === 'PROMOTION_FEE_REBATE' ? 'Annual fee returned' : (str_starts_with($p->event_type, 'ASSET_EXCHANGE') ? 'Exchange' : (str_starts_with($p->event_type, 'ASSET_WITHDRAWAL') ? 'Withdrawal' : (str_starts_with($p->event_type, 'ASSET_DEPOSIT') ? 'Top up' : 'Account activity'))))])->all();
            $orders = collect();
            foreach (['deposit' => AssetDepositOrder::class, 'withdrawal' => AssetWithdrawalOrder::class, 'exchange' => ExchangeOrder::class] as $mode => $model) {
                $orders = $orders->merge($model::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', $asset)->latest()->limit(5)->get()->map(fn ($o) => [
                    'id' => $o->id, 'mode' => $mode, 'amount' => $o->amount, 'time' => $o->created_at->toIso8601String(),
                    'state' => match ($o->status) {
                        'CREDITED', 'COMPLETED' => 'Completed', 'CANCELLED' => 'Cancelled', 'REJECTED' => 'Rejected', 'QUOTED' => 'Review exchange', default => 'Processing'
                    },
                ]));
            }
            $assets[] = ['asset' => $asset, 'available' => $available, 'held' => Money::of((string) $held, $asset)->amount(), 'deposit' => $deposit, 'commission' => $commission, 'rails' => $options, 'transfer' => $asset === 'USDT' && $eligible, 'exchange' => $assetEligible && $asset !== 'USDT' && $policy?->enabled && $policy->fee_percent !== null && $policy->single_limit !== null && $policy->daily_limit !== null && $snapshot !== null, 'activity' => $activity, 'orders' => $orders->sortByDesc('time')->take(10)->values()->all()];
        }

        return ['assets' => $assets, 'estimate' => $snapshot ? (string) $total->toScale(8, RoundingMode::Down) : null, 'updatedAt' => $snapshot?->observed_at->toIso8601String()];
    }
}
