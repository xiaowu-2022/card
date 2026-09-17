<?php

namespace App\Application\Wallet;

use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** A read-only projection of immutable events into customer business activities. */
final readonly class WalletActivityQuery
{
    public function get(string $tenantId, string $userId): array
    {
        $types = ['CARD_MANAGEMENT_ORDER', 'CARD_ISSUE_ORDER', 'WITHDRAWAL_ORDER'];
        $keySql = "CASE WHEN reference_type IN ('CARD_MANAGEMENT_ORDER','CARD_ISSUE_ORDER','WITHDRAWAL_ORDER') AND reference_id IS NOT NULL THEN reference_type || ':' || reference_id::text ELSE ledger_entries.id::text END";
        $base = LedgerEntry::query()->where('ledger_entries.asset_code', Tenant::query()->whereKey($tenantId)->value('default_asset'))->where('ledger_entries.tenant_id', $tenantId)->whereNotNull('sealed_at')
            ->whereHas('postings.account', fn ($q) => $q->where('tenant_id', $tenantId)->where('user_id', $userId)->whereNotNull('wallet_id'));
        $keys = (clone $base)->selectRaw("$keySql AS activity_key, MAX(posted_at) AS activity_time")
            ->groupByRaw($keySql)->orderByDesc('activity_time')->orderBy('activity_key')->limit(20)->pluck('activity_key');

        return (clone $base)->whereIn(DB::raw($keySql), $keys)->with('postings.account')->orderByDesc('posted_at')->orderByDesc('id')->get()
            ->groupBy(fn ($entry) => in_array($entry->reference_type, $types, true) && $entry->reference_id ? $entry->reference_type.':'.$entry->reference_id : $entry->id)
            ->map(function ($events) use ($tenantId, $userId): array {
                $latest = $events->first();
                $net = Money::of('0', $latest->asset_code);
                $steps = [];
                foreach ($events as $entry) {
                    $delta = Money::of('0', $entry->asset_code);
                    foreach ($entry->postings as $posting) {
                        if ($posting->account?->tenant_id === $tenantId && $posting->account?->user_id === $userId && $posting->account?->account_type === LedgerAccountType::UserAvailable) {
                            $delta = $delta->add(Money::of($posting->delta, $entry->asset_code));
                        }
                    }
                    $net = $net->add($delta);
                    $steps[] = ['id' => $entry->id, 'eventType' => $entry->event_type, 'amount' => $delta->amount(), 'postedAt' => $entry->posted_at->toIso8601String()];
                }
                $pending = $events->contains(fn ($e) => str_ends_with($e->event_type, '_HOLD'));
                $settled = $events->contains(fn ($e) => str_ends_with($e->event_type, '_SETTLE'));
                $released = $events->contains(fn ($e) => str_ends_with($e->event_type, '_RELEASE'));

                $displayEvent = $events->first(fn ($e) => str_ends_with($e->event_type, '_RELEASE'))
                    ?? $events->first(fn ($e) => str_ends_with($e->event_type, '_SETTLE'))
                    ?? $latest;

                return ['id' => $latest->id, 'eventType' => $displayEvent->event_type, 'asset' => $latest->asset_code,
                    'amount' => $net->amount(), 'postedAt' => $latest->posted_at->toIso8601String(),
                    'transferId' => $latest->event_type === 'WALLET_TRANSFER' ? $latest->reference_id : null,
                    'reference' => $latest->reference_id, 'steps' => $steps,
                    'state' => $released ? 'Returned' : ($pending && ! $settled ? 'Processing' : 'Completed')];
            })->values()->all();
    }
}
