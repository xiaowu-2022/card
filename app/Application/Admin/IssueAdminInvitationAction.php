<?php

namespace App\Application\Admin;

use App\Application\Admin\DTOs\IssuedAdminInvitation;
use App\Application\Tenant\CompanyConfigurationAuthority;
use App\Domain\Admin\Enums\InvitationStatus;
use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminInvitation;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;

final readonly class IssueAdminInvitationAction
{
    public function __construct(private TenantAdminUrlGenerator $urls) {}

    public function execute(Tenant $tenant, string $email, Role $role, AdminUser $inviter): IssuedAdminInvitation
    {
        app(CompanyConfigurationAuthority::class)->assert($inviter);
        if ($role->scope_type !== ScopeType::Tenant) {
            throw new DomainException('INVALID_INVITATION_ROLE', 'Only Tenant roles may be used for Tenant invitations.');
        }

        $email = strtolower(trim($email));
        $existingAdmin = AdminUser::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existingAdmin && AdminMembership::query()
            ->where('admin_user_id', $existingAdmin->id)
            ->where('scope_type', ScopeType::Tenant)
            ->where('scope_id', $tenant->id)
            ->whereIn('status', [MembershipStatus::Active, MembershipStatus::Suspended])
            ->exists()) {
            throw new DomainException('ADMIN_ALREADY_MEMBER', 'This administrator already belongs to the Tenant.');
        }

        if (AdminInvitation::query()
            ->where('tenant_id', $tenant->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('status', InvitationStatus::Pending)
            ->exists()) {
            throw new DomainException('INVITATION_ALREADY_PENDING', 'A pending invitation already exists for this email.');
        }

        $rawToken = bin2hex(random_bytes(32));
        $invitation = AdminInvitation::query()->create([
            'tenant_id' => $tenant->id,
            'email' => $email,
            'role_id' => $role->id,
            'token_hash' => hash('sha256', $rawToken),
            'status' => InvitationStatus::Pending,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addHours((int) config('tenancy.invitation_expiration_hours')),
        ]);

        return new IssuedAdminInvitation($invitation, $rawToken, $this->urls->invitation($tenant, $rawToken));
    }
}
