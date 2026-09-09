<?php

namespace App\Application\Admin;

use App\Application\Admin\DTOs\AcceptedAdminInvitation;
use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\InvitationStatus;
use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminInvitation;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

final readonly class AcceptAdminInvitationAction
{
    public function __construct(private AuditLogger $audit) {}

    public function inspect(string $rawToken, string $tenantId): AdminInvitation
    {
        $invitation = AdminInvitation::query()
            ->with(['role', 'tenant.branding'])
            ->where('token_hash', hash('sha256', $rawToken))
            ->where('tenant_id', $tenantId)
            ->first();

        $this->assertUsable($invitation);

        return $invitation;
    }

    public function execute(
        string $rawToken,
        string $tenantId,
        string $name,
        string $password,
        ?string $requestId = null,
    ): AcceptedAdminInvitation {
        return DB::transaction(function () use ($rawToken, $tenantId, $name, $password, $requestId): AcceptedAdminInvitation {
            $invitation = AdminInvitation::query()
                ->with('role')
                ->where('token_hash', hash('sha256', $rawToken))
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();
            $this->assertUsable($invitation);

            if ($invitation->role->scope_type !== ScopeType::Tenant) {
                throw new DomainException('INVALID_INVITATION_SCOPE', 'The invitation role is not valid for this Tenant.');
            }

            $admin = AdminUser::query()->whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])->lockForUpdate()->first();
            if ($admin) {
                if ($admin->status !== AdminUserStatus::Active || ! Hash::check($password, $admin->password)) {
                    throw new DomainException('ADMIN_CONFIRMATION_FAILED', 'The existing administrator password could not be confirmed.');
                }
            } else {
                Validator::make(
                    ['password' => $password],
                    ['password' => [Password::min(12)->letters()->mixedCase()->numbers()]],
                )->validate();
                $admin = AdminUser::query()->create([
                    'name' => trim($name),
                    'email' => $invitation->email,
                    'password' => $password,
                    'status' => AdminUserStatus::Active,
                    'email_verified_at' => now(),
                ]);
            }

            if (AdminMembership::query()
                ->where('admin_user_id', $admin->id)
                ->where('scope_type', ScopeType::Tenant)
                ->where('scope_id', $tenantId)
                ->exists()) {
                throw new DomainException('ADMIN_ALREADY_MEMBER', 'This administrator already belongs to the Tenant.');
            }

            $membership = AdminMembership::query()->create([
                'admin_user_id' => $admin->id,
                'scope_type' => ScopeType::Tenant,
                'scope_id' => $tenantId,
                'role_id' => $invitation->role_id,
                'status' => MembershipStatus::Active,
            ]);
            $invitation->update([
                'status' => InvitationStatus::Accepted,
                'accepted_at' => now(),
                'accepted_by' => $admin->id,
            ]);

            $this->audit->record($tenantId, 'ADMIN', $admin->id, 'ADMIN_INVITATION_ACCEPTED', 'admin_invitation', $invitation->id, ['status' => InvitationStatus::Pending->value], ['status' => InvitationStatus::Accepted->value], $requestId);
            $this->audit->record($tenantId, 'ADMIN', $admin->id, 'ADMIN_MEMBERSHIP_CREATED', 'admin_membership', $membership->id, null, [
                'role' => $invitation->role->name,
                'scope' => ScopeType::Tenant->value,
            ], $requestId);

            return new AcceptedAdminInvitation($invitation, $admin, $membership);
        });
    }

    private function assertUsable(?AdminInvitation $invitation): void
    {
        if (! $invitation) {
            throw new DomainException('INVITATION_INVALID', 'This invitation is invalid.', 404);
        }

        if ($invitation->status !== InvitationStatus::Pending) {
            throw new DomainException('INVITATION_NOT_PENDING', 'This invitation is no longer available.', 410);
        }

        if ($invitation->expires_at->isPast()) {
            throw new DomainException('INVITATION_EXPIRED', 'This invitation has expired.', 410);
        }
    }
}
