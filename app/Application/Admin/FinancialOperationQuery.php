<?php

namespace App\Application\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FinancialOperationQuery
{
    private function query()
    {
        return DB::table('audit_logs as a')->leftJoin('admin_users as actor', 'actor.id', '=', 'a.actor_id')
            ->leftJoin('tenants as company', 'company.id', '=', 'a.tenant_id')
            ->where('a.actor_type', 'ADMIN')->whereIn('a.resource_type', ['wallet_topup_order', 'withdrawal_order', 'asset_deposit_order', 'asset_withdrawal_order', 'promotion_rebate'])
            ->select('a.id', 'a.tenant_id', 'a.resource_id', 'a.resource_type', 'a.action', 'a.actor_id', 'a.created_at', 'actor.name as actor_name', 'company.name as company_name')
            ->orderByDesc('a.created_at')->orderByDesc('a.id');
    }

    public function paginate(?string $company, ?string $search)
    {
        return $this->query()->when($company, fn ($q) => $q->where('a.tenant_id', $company))
            ->when($search, fn ($q) => $q->where(function ($q) use ($search): void {
                $pattern = '%'.addcslashes($search, '%_').'%';
                $q->where('actor.name', 'ilike', $pattern)->orWhereRaw('a.resource_id::text ilike ?', [$pattern])->orWhereRaw('a.actor_id::text ilike ?', [$pattern]);
            }))->paginate(25)->withQueryString()->through(fn ($row) => $this->present($row));
    }

    public function forOrder(string $tenantId, string $type, string $id): array
    {
        return $this->query()->where('a.tenant_id', $tenantId)->where('a.resource_type', $type)->where('a.resource_id', $id)
            ->get()->map(fn ($row) => $this->present($row))->all();
    }

    private function present(object $row): array
    {
        return ['id' => $row->id, 'companyId' => $row->tenant_id, 'companyName' => $row->company_name,
            'orderId' => $row->resource_id, 'orderType' => $row->resource_type, 'action' => $row->action,
            'operatorId' => $row->actor_id, 'operatorName' => $row->actor_name,
            'operatedAt' => CarbonImmutable::parse($row->created_at)->toIso8601String()];
    }
}
