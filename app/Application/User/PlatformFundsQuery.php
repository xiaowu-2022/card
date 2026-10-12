<?php

namespace App\Application\User;

use App\Application\Assets\AssetActivityLabel;
use App\Application\Tenant\PlatformFundsFilters;
use App\Domain\Ledger\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Complete sealed ledger history, one business entry per row, with scoped postings. */
final class PlatformFundsQuery
{
    private function label(string $event): string
    {
        return $event === 'WALLET_TRANSFER' ? 'Wallet transfer' : AssetActivityLabel::for($event, '0');
    }

    public function read(array $filters): array
    {
        $outer = DB::transactionLevel();

        return DB::transaction(function () use ($filters, $outer) {
            if ($outer === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $base = DB::table('ledger_entries as e')->join('tenants as t', 't.id', '=', 'e.tenant_id')->whereNotNull('e.sealed_at');
            if (! empty($filters['company'])) {
                $base->where('e.tenant_id', $filters['company']);
            }
            if (! empty($filters['search'])) {
                $search = '%'.$filters['search'].'%';
                $base->where(function (Builder $q) use ($search) {
                    $q->whereRaw('e.id::text ILIKE ?', [$search])->orWhere('e.reference_id', 'ilike', $search)
                        ->orWhereExists(function (Builder $q) use ($search) {
                            $q->selectRaw('1')->from('ledger_postings as p')
                                ->join('ledger_accounts as a', fn ($j) => $j->on('a.id', '=', 'p.ledger_account_id')->on('a.tenant_id', '=', 'p.tenant_id'))
                                ->join('users as u', fn ($j) => $j->on('u.id', '=', 'a.user_id')->on('u.tenant_id', '=', 'a.tenant_id'))
                                ->leftJoin('user_profiles as up', fn ($j) => $j->on('up.user_id', '=', 'u.id')->on('up.tenant_id', '=', 'u.tenant_id'))
                                ->whereColumn('p.ledger_entry_id', 'e.id')->whereColumn('p.tenant_id', 'e.tenant_id')
                                ->where(fn ($q) => $q->where('u.account_id', 'ilike', $search)->orWhere('u.email', 'ilike', $search)
                                    ->orWhere('up.display_name', 'ilike', $search)->orWhere('u.support_remark', 'ilike', $search));
                        });
                });
            }
            $events = (clone $base)->distinct()->orderBy('e.event_type')->pluck('e.event_type')->all();
            $assets = (clone $base)->distinct()->orderBy('e.asset_code')->pluck('e.asset_code')->all();
            $query = clone $base;
            foreach (['asset' => 'asset_code', 'event' => 'event_type'] as $filter => $column) {
                if (! empty($filters[$filter])) {
                    $query->where('e.'.$column, $filters[$filter]);
                }
            }
            if (! empty($filters['from'])) {
                $query->where('e.posted_at', '>=', CarbonImmutable::parse($filters['from'], PlatformFundsFilters::TIMEZONE)->startOfDay()->utc());
            }
            if (! empty($filters['to'])) {
                $query->where('e.posted_at', '<', CarbonImmutable::parse($filters['to'], PlatformFundsFilters::TIMEZONE)->addDay()->startOfDay()->utc());
            }
            $page = $query->orderByDesc('e.posted_at')->orderByDesc('e.id')->paginate(25,
                ['e.id', 'e.tenant_id', 'e.asset_code', 'e.event_type', 'e.reference_type', 'e.reference_id', 'e.posted_at', 't.name as company_name'], 'page', $filters['page'] ?? 1)->withQueryString();
            $postings = DB::table('ledger_postings as p')
                ->join('ledger_entries as e', fn ($j) => $j->on('e.id', '=', 'p.ledger_entry_id')->on('e.tenant_id', '=', 'p.tenant_id'))
                ->join('ledger_accounts as a', fn ($j) => $j->on('a.id', '=', 'p.ledger_account_id')->on('a.tenant_id', '=', 'p.tenant_id')->on('a.asset_code', '=', 'e.asset_code'))
                ->leftJoin('users as u', fn ($j) => $j->on('u.id', '=', 'a.user_id')->on('u.tenant_id', '=', 'a.tenant_id'))
                ->leftJoin('user_profiles as up', fn ($j) => $j->on('up.user_id', '=', 'u.id')->on('up.tenant_id', '=', 'u.tenant_id'))
                ->whereIn('e.id', $page->pluck('id'))->orderBy('a.account_type')->orderBy('p.id')
                ->get(['p.id', 'p.ledger_entry_id', 'p.delta', 'a.asset_code', 'a.account_type', 'u.id as user_id', 'u.account_id', 'u.email', 'u.support_remark', 'up.display_name'])->groupBy('ledger_entry_id');
            $page->through(fn ($entry) => [
                'id' => $entry->id, 'companyId' => $entry->tenant_id, 'companyName' => $entry->company_name,
                'asset' => $entry->asset_code, 'event' => $entry->event_type, 'kind' => $this->label($entry->event_type),
                'reference' => $entry->reference_id, 'time' => CarbonImmutable::parse($entry->posted_at)->toIso8601String(),
                'movements' => $postings->get($entry->id, collect())->map(fn ($p) => [
                    'id' => $p->id, 'account' => $p->account_type, 'amount' => Money::of($p->delta, $p->asset_code)->amount(),
                    'user' => $p->user_id ? ['id' => $p->user_id, 'companyId' => $entry->tenant_id, 'companyName' => $entry->company_name,
                        'accountId' => $p->account_id, 'email' => $p->email, 'displayName' => $p->display_name, 'remark' => $p->support_remark] : null,
                ])->all(),
            ]);

            return ['flows' => $page, 'assets' => $assets,
                'events' => array_map(fn ($event) => ['value' => $event, 'label' => $this->label($event)], $events)];
        });
    }
}
