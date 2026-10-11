<?php

namespace App\Application\Assets;

use App\Domain\Assets\AssetCatalog;
use App\Domain\Assets\MarketSnapshot;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/** Shared consumer/Platform valuation. Only supplied local evidence is read. */
final readonly class AssetValuation
{
    public function __construct(private MarketPrices $prices) {}

    public function calculate(Collection $accounts, Collection $cards, ?MarketSnapshot $snapshot): array
    {
        $balance = fn ($asset, $type) => (string) BigDecimal::sum('0', ...$accounts->filter(fn ($a) => $a->asset_code === $asset && ($a->account_type instanceof \BackedEnum ? $a->account_type->value : $a->account_type) === $type)->pluck('balance')->all());
        $convert = function (string $amount, string $asset) use ($snapshot): ?string {
            $native = BigDecimal::of($amount);
            if ($asset === 'USDT' || $native->isZero()) {
                return (string) $native->toScale(8, RoundingMode::Down);
            }
            if (! $snapshot) {
                return null;
            }
            try {
                $rate = $this->prices->rate($snapshot, $asset);

                return $rate->isPositive() ? (string) $native->multipliedBy($rate)->toScale(8, RoundingMode::Down) : null;
            } catch (\Throwable) {
                return null;
            }
        };
        $items = [];
        $items[] = ['kind' => 'deposit', 'asset' => 'USDT', 'amount' => $balance('USDT', 'USER_SECURITY_DEPOSIT'), 'held' => '0'];
        foreach (AssetCatalog::ASSETS as $asset) {
            $items[] = ['kind' => 'wallet', 'asset' => $asset, 'amount' => $balance($asset, 'USER_AVAILABLE'), 'held' => (string) BigDecimal::sum($balance($asset, 'USER_WITHDRAWAL_HOLD'), $balance($asset, 'USER_CARD_ISSUE_HOLD'), $balance($asset, 'USER_CARD_FUNDING_HOLD'))];
            $items[] = ['kind' => 'wealth', 'asset' => $asset, 'amount' => $balance($asset, 'USER_WEALTH_PRINCIPAL'), 'held' => '0'];
        }
        // Display wallets together, followed by the four native wealth principals.
        $items = [...array_filter($items, fn ($i) => $i['kind'] !== 'wealth'), ...array_filter($items, fn ($i) => $i['kind'] === 'wealth')];
        $cardAmount = BigDecimal::of('0');
        $cardKnown = true;
        foreach ($cards as $card) {
            if ($card->provider_balance === null || $card->card_currency !== 'USD') {
                $cardKnown = false;

                continue;
            }
            $overflow = $accounts->filter(fn ($a) => $a->card_id === $card->id && $a->asset_code === 'USDT' && ($a->account_type instanceof \BackedEnum ? $a->account_type->value : $a->account_type) === 'USER_CARD_OVERFLOW');
            $cardAmount = $cardAmount->plus($card->provider_balance)->plus(BigDecimal::sum('0', ...$overflow->pluck('balance')->all()));
        }
        $items[] = ['kind' => 'cards', 'asset' => 'USDT', 'amount' => $cardKnown ? (string) $cardAmount : null, 'held' => '0'];
        $total = BigDecimal::of('0');
        $known = true;
        $usesRates = false;
        foreach ($items as &$item) {
            $native = $item['amount'] === null ? null : (string) BigDecimal::of($item['amount'])->plus($item['held']);
            $item['usdt'] = $native === null ? null : $convert($native, $item['asset']);
            $usesRates = $usesRates || ($native !== null && $item['asset'] !== 'USDT' && ! BigDecimal::of($native)->isZero());
            if ($item['usdt'] === null) {
                $known = false;
            } else {
                // Accumulate at quote precision, then round once, matching the consumer total.
                $total = $total->plus($native === null || $item['asset'] === 'USDT' || BigDecimal::of($native)->isZero() ? ($native ?? '0') : BigDecimal::of($native)->multipliedBy($this->prices->rate($snapshot, $item['asset'])));
            }
        }
        unset($item);

        return ['items' => $items, 'estimate' => $known ? (string) $total->toScale(8, RoundingMode::Down) : null, 'cardBalance' => $cardKnown ? (string) $cardAmount : null, 'updatedAt' => $known && $usesRates ? $snapshot?->observed_at->toIso8601String() : null, 'readAt' => now()->toIso8601String()];
    }
}
