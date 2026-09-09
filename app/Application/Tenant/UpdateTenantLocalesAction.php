<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantLocale;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTenantLocalesAction
{
    public function __construct(private AuditLogger $audit) {}

    /** @param list<string> $enabledLocales */
    public function execute(Tenant $tenant, array $enabledLocales, string $defaultLocale, AdminUser $actor, ?string $requestId = null): void
    {
        $enabledLocales = array_values(array_unique($enabledLocales));
        if ($enabledLocales === []) {
            throw new DomainException('LOCALE_REQUIRED', 'At least one locale must remain enabled.');
        }
        if (! in_array($defaultLocale, $enabledLocales, true)) {
            throw new DomainException('DEFAULT_LOCALE_DISABLED', 'The default locale must be enabled.');
        }

        DB::transaction(function () use ($tenant, $enabledLocales, $defaultLocale, $actor, $requestId): void {
            $before = $tenant->locales()->orderBy('locale')->get(['locale', 'enabled', 'is_default'])->toArray();
            foreach (config('tenancy.supported_locales') as $locale) {
                TenantLocale::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'locale' => $locale],
                    ['enabled' => in_array($locale, $enabledLocales, true), 'is_default' => false],
                );
            }
            TenantLocale::query()->where('tenant_id', $tenant->id)->where('locale', $defaultLocale)->update(['enabled' => true, 'is_default' => true]);
            $tenant->update(['default_locale' => $defaultLocale]);
            $after = $tenant->locales()->orderBy('locale')->get(['locale', 'enabled', 'is_default'])->toArray();
            $this->audit->record($tenant->id, 'ADMIN', $actor->id, 'TENANT_LOCALE_UPDATED', 'tenant_locales', $tenant->id, ['locales' => $before], ['locales' => $after], $requestId);
        });
    }
}
