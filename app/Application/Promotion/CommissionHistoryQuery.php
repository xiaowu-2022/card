<?php

namespace App\Application\Promotion;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CommissionHistoryQuery
{
    public function execute(string $tenantId, string $userId, ?string $date = null, int $page = 1): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $earned = DB::table('commission_awards as a')
            ->join('ledger_entries as e', function ($join): void {
                $join->on('e.id', '=', 'a.ledger_entry_id')->on('e.tenant_id', '=', 'a.tenant_id');
            })
            ->join('promotion_funding_events as f', function ($join): void {
                $join->on('f.id', '=', 'a.funding_event_id')->on('f.tenant_id', '=', 'a.tenant_id');
            })->join('users as u', function ($join): void {
                $join->on('u.id', '=', 'f.user_id')->on('u.tenant_id', '=', 'f.tenant_id');
            })->where('a.tenant_id', $tenantId)->where('a.user_id', $userId)
            ->selectRaw("a.id, 'earned' AS kind, a.amount::text AS amount, a.asset_code, u.account_id AS source_account_id, e.posted_at AS occurred_at");
        $transferred = DB::table('commission_transfers')->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->selectRaw("id, 'transferred' AS kind, (-amount)::text AS amount, asset_code, NULL::text AS source_account_id, created_at AS occurred_at");
        $query = DB::query()->fromSub($earned->unionAll($transferred), 'history');
        if ($date) {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $tenant->timezone);
            $query->where('occurred_at', '>=', $day->utc())->where('occurred_at', '<', $day->addDay()->utc());
        }
        $rows = $query->orderByDesc('occurred_at')->orderBy('id')->orderBy('kind')->offset(($page - 1) * 30)->limit(31)->get();

        return [
            'date' => $date, 'timezone' => $tenant->timezone, 'page' => $page, 'hasMore' => $rows->count() > 30,
            'items' => $rows->take(30)->map(fn ($row): array => [
                'id' => $row->kind.':'.$row->id, 'kind' => $row->kind, 'amount' => $row->amount,
                'asset' => $row->asset_code, 'sourceAccountId' => $row->source_account_id, 'occurredAt' => $row->occurred_at,
            ])->all(),
        ];
    }
}
