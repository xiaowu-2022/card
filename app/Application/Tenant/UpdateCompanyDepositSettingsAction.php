<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class UpdateCompanyDepositSettingsAction
{
    public function __construct(private AuthorizationService $authorization, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $amount, int $waitDays, AdminUser $actor, ?string $requestId = null): void
    {
        if (! preg_match('/\A\d{1,12}(?:\.\d{1,8})?\z/', $amount) || $waitDays < 0 || $waitDays > 3650) {
            throw new DomainException('DEPOSIT_SETTINGS_INVALID', 'Enter a valid deposit amount and a waiting period from 0 to 3650 days.');
        }
        DB::transaction(function () use ($tenantId, $amount, $waitDays, $actor, $requestId): void {
            $currentActor = AdminUser::query()->findOrFail($actor->id);
            if ($currentActor->status !== AdminUserStatus::Active || ! $this->authorization->allows($currentActor, ScopeType::Platform, null, 'tenant.manage')) {
                throw new DomainException('DEPOSIT_SETTINGS_FORBIDDEN', 'Only SaaS administrators can configure company security deposits.', 403);
            }
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $settings = $tenant->businessSettings()->lockForUpdate()->firstOrFail();
            $fields = ['required_security_deposit_amount', 'required_security_deposit_asset', 'security_deposit_refund_wait_days'];
            $before = $settings->only($fields);
            $settings->update([
                'required_security_deposit_amount' => Money::of($amount, $tenant->default_asset)->amount(),
                'required_security_deposit_asset' => $tenant->default_asset,
                'security_deposit_refund_wait_days' => $waitDays,
            ]);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'COMPANY_DEPOSIT_SETTINGS_UPDATED', 'tenant_business_settings', $tenantId, $before, $settings->fresh()->only($fields), $requestId);
        });
    }
}
