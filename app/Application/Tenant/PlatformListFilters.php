<?php

namespace App\Application\Tenant;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Http\Request;

final class PlatformListFilters
{
    public function validated(Request $request, array $extra = []): array
    {
        return $request->validate([
            'company' => ['nullable', 'uuid', 'exists:tenants,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            ...$extra,
        ]);
    }

    public function companies(): array
    {
        return Tenant::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->map(fn (Tenant $tenant): array => ['id' => $tenant->id, 'name' => $tenant->name])->all();
    }
}
