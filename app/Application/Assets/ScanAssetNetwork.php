<?php

namespace App\Application\Assets;

use App\Domain\Assets\AssetDepositOrder;
use App\Domain\Assets\AssetRail;
use App\Domain\Assets\ChainConnection;
use App\Domain\Assets\ChainObservation;
use App\Infrastructure\Assets\ChainReader;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final readonly class ScanAssetNetwork
{
    public function __construct(private ChainReader $reader, private DepositAssetsAction $deposits) {}

    public function execute(string $network): int
    {
        $c = ChainConnection::query()->whereKey($network)->firstOrFail();
        if (! $c->enabled || $c->start_height === null || $c->next_height === null) {
            return 0;
        }
        if ($c->checkpoint_hash && $this->reader->hash($c, $c->next_height - 1) !== $c->checkpoint_hash) {
            // Finality violation: halt. Historical credits are never reversed by a scanner.
            $c->update(['enabled' => false]);
            throw new DomainException('CHAIN_REORG', 'Network confirmation changed. Manual review is required.', 409);
        }
        $final = $this->reader->finalHeight($c);
        $processed = 0;
        for ($height = $c->next_height; $height <= min($final, $c->next_height + config('assets.scan_batch_blocks', 10) - 1); $height++) {
            $block = $this->reader->block($c, $height);
            $committed = DB::transaction(function () use ($network, $height, $block): bool {
                $cursor = ChainConnection::query()->whereKey($network)->lockForUpdate()->firstOrFail();
                if (! $cursor->enabled || $cursor->next_height !== $height) {
                    return false;
                }
                $rails = AssetRail::query()->where('network', $network)->whereNotNull('deposit_address')->get();
                foreach ($block['transfers'] as $transfer) {
                    $atAddress = $rails->where('deposit_address', $transfer['address']);
                    $historical = AssetDepositOrder::query()->where('network', $network)->where('address', $transfer['address'])->where('contract', $transfer['contract'])->get();
                    if ($atAddress->isEmpty() && $historical->isEmpty()) {
                        continue;
                    }
                    $rail = $atAddress->first(fn ($rail) => $rail->contract === $transfer['contract'])
                        ?? $rails->firstWhere('code', $historical->first()?->rail_code);
                    $existing = ChainObservation::query()->where('network', $network)->where('event_id', $transfer['event_id'])->first();
                    if ($existing) {
                        continue;
                    }
                    $matches = $rail ? AssetDepositOrder::query()->where('rail_code', $rail->code)->where('address', $transfer['address'])->where('amount', $transfer['amount'])->get() : collect();
                    $order = $matches->count() === 1 ? $matches->first() : null;
                    $duplicates = count(array_filter($block['transfers'], fn ($other) => $other['address'] === $transfer['address'] && $other['contract'] === $transfer['contract'] && BigDecimal::of($other['amount'])->isEqualTo($transfer['amount'])));
                    $matched = $order && $duplicates === 1 && $transfer['occurred_at']->greaterThanOrEqualTo($order->created_at) && $transfer['occurred_at']->lessThanOrEqualTo($order->expires_at);
                    $observation = ChainObservation::query()->create(['network' => $network, 'event_id' => $transfer['event_id'], 'rail_code' => $rail?->code ?? 'UNSUPPORTED', 'address' => $transfer['address'], 'amount' => $transfer['amount'], 'block_height' => $height, 'block_hash' => $block['hash'], 'occurred_at' => $transfer['occurred_at'], 'status' => $matched ? 'MATCHED' : 'REQUIRES_REVIEW', 'order_id' => $order?->id]);
                    if ($matched) {
                        $this->deposits->verified($observation);
                    }
                }
                $cursor->update(['next_height' => $height + 1, 'checkpoint_hash' => $block['hash']]);

                return true;
            }, 3);
            if (! $committed) {
                break;
            }
            $processed++;
        }

        return $processed;
    }
}
