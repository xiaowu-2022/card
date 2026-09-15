<?php

namespace App\Application\Tenant;

use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ListTenantsQuery
{
    public function execute(?string $search, ?string $status, array $financialAccess = []): LengthAwarePaginator
    {
        return $this->filtered($search, $status)->select('tenants.*')
            ->with(['domains' => fn ($query) => $query->where('is_primary', true)])
            ->when($financialAccess['inflow'] ?? false, fn ($query) => $query->selectSub(
                $this->orders('wallet_topup_orders', 'CREDITED')->whereColumn('tenant_id', 'tenants.id')->selectRaw('COALESCE(SUM(amount), 0)::text'), 'inflow'))
            ->when($financialAccess['outflow'] ?? false, fn ($query) => $query->selectSub(
                $this->orders('withdrawal_orders', 'SUCCEEDED')->whereColumn('tenant_id', 'tenants.id')->selectRaw('COALESCE(SUM(amount), 0)::text'), 'outflow'))
            ->latest()->orderBy('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domains->first()?->hostname,
                'status' => $tenant->status->value,
                'createdAt' => $tenant->created_at->toIso8601String(),
            ] + (($financialAccess['inflow'] ?? false) ? ['inflow' => $this->decimal($tenant->inflow)] : [])
                + (($financialAccess['outflow'] ?? false) ? ['outflow' => $this->decimal($tenant->outflow)] : []));
    }

    public function totals(?string $search, ?string $status, array $financialAccess = []): array
    {
        $totals = [];
        foreach (['inflow' => ['wallet_topup_orders', 'CREDITED'], 'outflow' => ['withdrawal_orders', 'SUCCEEDED']] as $key => [$table, $state]) {
            if ($financialAccess[$key] ?? false) {
                // Identical company filters, deliberately before pagination; orders are counted once.
                $sum = $this->orders($table, $state)->whereIn('tenant_id', $this->filtered($search, $status)->select('id'))
                    ->selectRaw('COALESCE(SUM(amount), 0)::text AS total')->first()->total;
                $totals[$key] = $this->decimal($sum);
            }
        }

        return $totals;
    }

    private function filtered(?string $search, ?string $status): Builder
    {
        return Tenant::query()->when($search, fn ($query, $value) => $query->where(function ($nested) use ($value): void {
            $nested->where('name', 'ILIKE', '%'.$value.'%')
                ->orWhere('slug', 'ILIKE', '%'.$value.'%')
                ->orWhereHas('domains', fn ($domains) => $domains->where('hostname', 'ILIKE', '%'.$value.'%'));
        }))->when($status && TenantStatus::tryFrom($status), fn ($query) => $query->where('status', $status));
    }

    private function orders(string $table, string $status): \Illuminate\Database\Query\Builder
    {
        return DB::table($table)->where('asset_code', 'USDT')->where('status', $status);
    }

    private function decimal(string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(8);
    }
}
