<?php

namespace App\Application\Tenant;

use App\Application\Admin\IssueAdminInvitationAction;
use App\Application\Tenant\DTOs\CreatedTenant;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\KycReviewMode;
use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Enums\TenantDomainType;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantBranding;
use App\Domain\Tenant\Models\TenantBusinessSetting;
use App\Domain\Tenant\Models\TenantDomain;
use App\Domain\Tenant\Models\TenantKycSetting;
use App\Domain\Tenant\Models\TenantLocale;
use App\Mail\AdminInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final readonly class CreateTenantAction
{
    public function __construct(
        private IssueAdminInvitationAction $invitations,
        private AuditLogger $audit,
    ) {}

    /** @param array{name:string,slug:string,default_locale:string,timezone:string,default_asset:string,owner_email:string} $data */
    public function execute(array $data, AdminUser $actor, ?string $requestId = null): CreatedTenant
    {
        $created = DB::transaction(function () use ($data, $actor, $requestId): CreatedTenant {
            $tenant = Tenant::query()->create([
                'name' => trim($data['name']),
                'slug' => $data['slug'],
                'status' => TenantStatus::Draft,
                'default_locale' => $data['default_locale'],
                'timezone' => $data['timezone'],
                'default_asset' => $data['default_asset'],
            ]);
            $hostname = $data['slug'].'.'.config('tenancy.root_domain');
            TenantDomain::query()->create([
                'tenant_id' => $tenant->id,
                'hostname' => $hostname,
                'domain_type' => TenantDomainType::SystemSubdomain,
                'status' => TenantDomainStatus::Active,
                'is_primary' => true,
                'verified_at' => now(),
                'ssl_status' => app()->environment('local') ? 'LOCAL' : 'PENDING',
            ]);
            TenantBranding::query()->create([
                'tenant_id' => $tenant->id,
                'brand_name' => $tenant->name,
                'primary_color' => '#155EEF',
                'support_email' => $data['owner_email'],
            ]);
            TenantLocale::query()->create([
                'tenant_id' => $tenant->id,
                'locale' => $data['default_locale'],
                'enabled' => true,
                'is_default' => true,
            ]);
            TenantBusinessSetting::query()->create([
                'tenant_id' => $tenant->id,
                'required_security_deposit_amount' => '0.00000000',
                'required_security_deposit_asset' => $data['default_asset'],
                'allow_wallet_topup' => false,
                'allow_withdrawal' => false,
            ]);
            TenantKycSetting::query()->create([
                'tenant_id' => $tenant->id,
                'enabled' => false,
                'max_accounts_per_identity' => 1,
                'review_mode' => KycReviewMode::Manual,
            ]);

            $ownerRole = Role::query()->where('name', 'TENANT_OWNER')->where('scope_type', ScopeType::Tenant)->firstOrFail();
            $issued = $this->invitations->execute($tenant, $data['owner_email'], $ownerRole, $actor);
            $this->audit->record(null, 'ADMIN', $actor->id, 'TENANT_CREATED', 'tenant', $tenant->id, null, [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status->value,
                'system_domain' => $hostname,
                'default_locale' => $tenant->default_locale,
                'timezone' => $tenant->timezone,
                'default_asset' => $tenant->default_asset,
            ], $requestId);
            $this->audit->record($tenant->id, 'ADMIN', $actor->id, 'ADMIN_INVITED', 'admin_invitation', $issued->invitation->id, null, [
                'email' => $issued->invitation->email,
                'role' => $ownerRole->name,
                'expires_at' => $issued->invitation->expires_at->toIso8601String(),
            ], $requestId);

            return new CreatedTenant($tenant, $issued);
        });

        Mail::to($created->ownerInvitation->invitation->email)->send(new AdminInvitationMail(
            $created->tenant,
            $created->ownerInvitation->invitation,
            $created->ownerInvitation->url,
        ));

        return $created;
    }
}
