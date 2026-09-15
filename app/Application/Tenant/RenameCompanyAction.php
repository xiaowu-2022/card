<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class RenameCompanyAction
{
    public function __construct(private CompanyConfigurationAuthority $authority, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $name, AdminUser $actor, ?string $requestId = null): void
    {
        $this->authority->assert($actor);
        $name = trim($name);
        Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:120']])->validate();

        DB::transaction(function () use ($tenantId, $name, $actor, $requestId): void {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            if ($tenant->name === $name) {
                return;
            }
            $before = ['name' => $tenant->name];
            $tenant->update(['name' => $name]);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'COMPANY_RENAMED', 'tenant', $tenantId, $before, ['name' => $name], $requestId);
        });
    }
}
