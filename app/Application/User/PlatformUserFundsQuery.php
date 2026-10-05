<?php

namespace App\Application\User;

use App\Application\Assets\AssetActivityDetails;
use App\Application\Assets\AssetActivityLabel;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PlatformUserFundsQuery
{
    public function read(Tenant $tenant, string $user, array $filters): array
    {
        $outer = DB::transactionLevel();

        return DB::transaction(function () use ($tenant, $user, $filters, $outer) {
            if ($outer === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $member = User::where('tenant_id', $tenant->id)->with('profile')->findOrFail($user);
            $accounts = DB::table('ledger_accounts')->where('tenant_id', $tenant->id)->where('user_id', $user)
                ->groupBy('asset_code', 'account_type')->orderBy('asset_code')->orderBy('account_type')
                ->get(['asset_code', 'account_type', DB::raw('SUM(balance)::text AS balance')]);
            $base = DB::table('ledger_entries as e')->where('e.tenant_id', $tenant->id)->whereNotNull('e.sealed_at')
                ->whereExists(function (Builder $query) use ($tenant, $user) {
                    $query->selectRaw('1')->from('ledger_postings as p')
                        ->join('ledger_accounts as a', 'a.id', '=', 'p.ledger_account_id')
                        ->whereColumn('p.ledger_entry_id', 'e.id')->whereColumn('a.asset_code', 'e.asset_code')
                        ->where('p.tenant_id', $tenant->id)->where('a.tenant_id', $tenant->id)->where('a.user_id', $user);
                });
            $events = (clone $base)->distinct()->orderBy('e.event_type')->pluck('e.event_type')->all();
            $query = clone $base;
            if (! empty($filters['asset'])) {
                $query->where('e.asset_code', $filters['asset']);
            }
            if (! empty($filters['event'])) {
                $query->where('e.event_type', $filters['event']);
            }
            if (! empty($filters['from'])) {
                $query->where('e.posted_at', '>=', CarbonImmutable::parse($filters['from'], $tenant->timezone)->startOfDay()->toIso8601String());
            }
            if (! empty($filters['to'])) {
                $query->where('e.posted_at', '<', CarbonImmutable::parse($filters['to'], $tenant->timezone)->addDay()->startOfDay()->toIso8601String());
            }
            $page = $query->orderByDesc('e.posted_at')->orderByDesc('e.id')
                ->paginate(25, ['e.id', 'e.asset_code', 'e.event_type', 'e.reference_type', 'e.reference_id', 'e.posted_at'], 'page', $filters['page'] ?? 1);
            $postings = DB::table('ledger_postings as p')->join('ledger_accounts as a', 'a.id', '=', 'p.ledger_account_id')
                ->where('p.tenant_id', $tenant->id)->where('a.tenant_id', $tenant->id)->where('a.user_id', $user)
                ->whereIn('p.ledger_entry_id', $page->pluck('id'))->orderBy('a.account_type')->orderBy('p.id')
                ->get(['p.id', 'p.ledger_entry_id', 'p.delta', 'a.asset_code', 'a.account_type'])->groupBy('ledger_entry_id');
            // Reuse the safe business reason / transfer-counterparty projection. Never serialize raw entry metadata.
            $contexts = $page->getCollection()->map(function ($entry) use ($postings) {
                $moves = $postings->get($entry->id);
                $representative = $moves->firstWhere('account_type', 'USER_AVAILABLE') ?? $moves->first();

                return (object) ['id' => $entry->id, 'entry_id' => $entry->id, 'event_type' => $entry->event_type,
                    'reference_type' => $entry->reference_type, 'reference_id' => $entry->reference_id, 'delta' => $representative->delta];
            });
            $details = app(AssetActivityDetails::class)->forRows($tenant->id, $user, $contexts);
            $contexts = $contexts->keyBy('id');

            return [
                'company' => ['id' => $tenant->id, 'name' => $tenant->name],
                'user' => ['id' => $member->id, 'displayName' => $member->profile?->display_name, 'email' => $member->email],
                'timezone' => $tenant->timezone,
                'accounts' => $accounts->map(fn ($a) => ['asset' => $a->asset_code, 'type' => $a->account_type, 'balance' => Money::of($a->balance, $a->asset_code)->amount()])->all(),
                'events' => array_map(fn ($event) => ['value' => $event, 'label' => AssetActivityLabel::for($event, '0')], $events),
                'rows' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total(),
                    'items' => $page->map(fn ($entry) => [
                        'id' => $entry->id, 'asset' => $entry->asset_code,
                        'kind' => AssetActivityLabel::for($contexts[$entry->id]->event_type, $contexts[$entry->id]->delta),
                        'event' => $entry->event_type, 'time' => CarbonImmutable::parse($entry->posted_at)->toIso8601String(),
                        'details' => $details->get($entry->id),
                        'movements' => $postings[$entry->id]->map(fn ($p) => ['id' => $p->id, 'account' => $p->account_type, 'amount' => Money::of($p->delta, $p->asset_code)->amount()])->all(),
                    ])->all()],
            ];
        });
    }
}
