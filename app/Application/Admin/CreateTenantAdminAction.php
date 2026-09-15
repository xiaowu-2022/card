<?php

namespace App\Application\Admin;

use App\Application\Tenant\CompanyConfigurationAuthority;
use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final readonly class CreateTenantAdminAction
{
    public function __construct(private AuthorizationService $authorization, private AuditLogger $audit) {}

    public function execute(Tenant $tenant, AdminUser $actor, string $name, string $email, string $password, string $roleName, string $currentPassword, ?string $requestId = null): void
    {
        app(CompanyConfigurationAuthority::class)->assert($actor);
        $email = strtolower(trim($email));
        $name = trim($name);
        Validator::make(['name' => $name, 'email' => $email, 'password' => $password, 'role' => $roleName], [
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:72', Password::min(12)->letters()->mixedCase()->numbers()],
            'role' => ['required', Rule::in(['TENANT_ADMIN', 'KYC_REVIEWER', 'CARD_OPERATOR', 'FINANCE_VIEWER', 'SUPPORT'])],
        ])->validate();
        // Hash outside locks; never return the password or include it in audit context.
        $passwordHash = Hash::make($password);
        try {
            DB::transaction(function () use ($tenant, $actor, $name, $email, $passwordHash, $roleName, $currentPassword, $requestId): void {
                $currentTenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $currentActor = AdminUser::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $currentActor->memberships()->where('scope_type', ScopeType::Platform)->whereNull('scope_id')->lockForUpdate()->get();
                if ($currentTenant->status === TenantStatus::Closed || $currentActor->status !== AdminUserStatus::Active
                    || ! $this->authorization->allows($currentActor, ScopeType::Platform, null, 'tenant.manage')
                    || ! Hash::check($currentPassword, $currentActor->password)) {
                    throw new DomainException('ADMIN_CREATION_FORBIDDEN', 'Administrator access could not be confirmed.', 403);
                }
                // Admin identities are shared across scopes. Never attach or reset an existing identity here.
                if (AdminUser::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                    throw new DomainException('ADMIN_ACCOUNT_UNAVAILABLE', 'This login account is unavailable. Choose another account.', 409);
                }
                $role = Role::query()->where('scope_type', ScopeType::Tenant)->where('name', $roleName)->firstOrFail();
                $admin = AdminUser::query()->create([
                    'name' => $name, 'email' => $email, 'password' => $passwordHash,
                    'status' => AdminUserStatus::Active, 'email_verified_at' => null,
                ]);
                $membership = AdminMembership::query()->create([
                    'admin_user_id' => $admin->id, 'scope_type' => ScopeType::Tenant,
                    'scope_id' => $tenant->id, 'role_id' => $role->id, 'status' => MembershipStatus::Active,
                ]);
                $this->audit->record($tenant->id, 'ADMIN', $actor->id, 'ADMIN_ACCOUNT_CREATED', 'admin_user', $admin->id, null, ['role' => $role->name, 'scope' => 'TENANT'], $requestId);
                $this->audit->record($tenant->id, 'ADMIN', $actor->id, 'ADMIN_MEMBERSHIP_CREATED', 'admin_membership', $membership->id, null, ['role' => $role->name, 'scope' => 'TENANT'], $requestId);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw new DomainException('ADMIN_ACCOUNT_UNAVAILABLE', 'This login account is unavailable. Choose another account.', 409);
            }
            throw $exception;
        }
    }
}
