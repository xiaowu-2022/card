<?php

namespace App\Application\User;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateUserLocaleAction
{
    public function execute(Tenant $tenant, ?User $user, string $locale): void
    {
        DB::transaction(function () use ($tenant, $user, $locale): void {
            $current = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locale, config('tenancy.supported_locales'), true)
                || ! $current->locales()->where('enabled', true)->where('locale', $locale)->exists()) {
                throw ValidationException::withMessages(['locale' => 'This language is not enabled for this tenant.']);
            }
            if ($user !== null) {
                User::query()->where('tenant_id', $current->id)->whereKey($user->id)->lockForUpdate()->firstOrFail();
                UserPreference::query()->updateOrCreate(
                    ['tenant_id' => $current->id, 'user_id' => $user->id],
                    ['locale' => $locale],
                );
            }
        });
    }
}
