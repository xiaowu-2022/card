<?php

namespace App\Http\Controllers\Platform;

use App\Application\Tenant\PlatformListFilters;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class CompanyConfigurationListController
{
    public function __invoke(Request $request, PlatformListFilters $lists)
    {
        $filters = $lists->validated($request, ['status' => 'nullable|in:DRAFT,ACTIVE,SUSPENDED,CLOSED']);
        $rows = Tenant::query()->when($filters['company'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->whereRaw('strpos(lower(name), lower(?)) > 0', [$search])->orWhereRaw('strpos(lower(slug), lower(?)) > 0', [$search])))
            ->orderBy('name')->orderBy('id')->paginate(20, ['id', 'name', 'slug', 'status', 'default_locale', 'timezone'])->withQueryString();

        return Inertia::render('platform/CompanyConfigurations', ['companies' => $lists->companies(), 'records' => $rows, 'filters' => $filters])
            ->toResponse($request)->header('Cache-Control', 'private, no-store');
    }
}
