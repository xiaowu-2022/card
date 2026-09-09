<?php

namespace App\Application\Tenant;

use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListTenantsQuery
{
    public function execute(?string $search, ?string $status): LengthAwarePaginator
    {
        return Tenant::query()
            ->with(['domains' => fn ($query) => $query->where('is_primary', true)])
            ->when($search, fn ($query, $value) => $query->where(function ($nested) use ($value): void {
                $nested->where('name', 'ILIKE', '%'.$value.'%')
                    ->orWhere('slug', 'ILIKE', '%'.$value.'%')
                    ->orWhereHas('domains', fn ($domains) => $domains->where('hostname', 'ILIKE', '%'.$value.'%'));
            }))
            ->when($status && TenantStatus::tryFrom($status), fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domains->first()?->hostname,
                'status' => $tenant->status->value,
                'createdAt' => $tenant->created_at->toIso8601String(),
            ]);
    }
}
