<?php

namespace App\Application\Admin;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final readonly class CreatePlatformAdminAction
{
    public function __construct(private AuthorizationService $authorization, private AuditLogger $audit) {}

    public function execute(AdminUser $actor, string $name, string $email, string $password, string $roleName, ?string $requestId = null): void
    {
        $current = $actor->fresh();
        if (! $current || $current->status !== AdminUserStatus::Active || ! $this->authorization->allows($current, ScopeType::Platform, null, 'admin_team.manage')) {
            throw new DomainException('ADMIN_CREATION_FORBIDDEN', 'Administrator access could not be confirmed.', 403);
        }
        $email = strtolower(trim($email));
        $name = trim($name);
        Validator::make(['name' => $name, 'email' => $email, 'password' => $password, 'role' => $roleName], [
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:72', Password::min(12)->letters()->mixedCase()->numbers()],
            'role' => ['required', Rule::in(['PLATFORM_ADMIN', 'PLATFORM_AUDITOR'])],
        ])->validate();
        // Hash outside locks; never return the password or include it in audit context.
        $passwordHash = Hash::make($password);
        try {
            DB::transaction(function () use ($actor, $name, $email, $passwordHash, $roleName, $requestId): void {
                $currentActor = AdminUser::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $currentActor->memberships()->where('scope_type', ScopeType::Platform)->whereNull('scope_id')->lockForUpdate()->get();
                if ($currentActor->status !== AdminUserStatus::Active
                    || ! $this->authorization->allows($currentActor, ScopeType::Platform, null, 'admin_team.manage')) {
                    throw new DomainException('ADMIN_CREATION_FORBIDDEN', 'Administrator access could not be confirmed.', 403);
                }
                // Admin identities are shared across scopes. Never attach or reset an existing identity here.
                if (AdminUser::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                    throw new DomainException('ADMIN_ACCOUNT_UNAVAILABLE', 'This login account is unavailable. Choose another account.', 409);
                }
                $role = Role::query()->where('scope_type', ScopeType::Platform)->where('name', $roleName)->firstOrFail();
                $admin = AdminUser::query()->create([
                    'name' => $name, 'email' => $email, 'password' => $passwordHash,
                    'status' => AdminUserStatus::Active, 'email_verified_at' => null,
                ]);
                $membership = AdminMembership::query()->create([
                    'admin_user_id' => $admin->id, 'scope_type' => ScopeType::Platform,
                    'scope_id' => null, 'role_id' => $role->id, 'status' => MembershipStatus::Active,
                ]);
                $this->audit->record(null, 'ADMIN', $actor->id, 'ADMIN_ACCOUNT_CREATED', 'admin_user', $admin->id, null, ['role' => $role->name, 'scope' => 'PLATFORM'], $requestId);
                $this->audit->record(null, 'ADMIN', $actor->id, 'ADMIN_MEMBERSHIP_CREATED', 'admin_membership', $membership->id, null, ['role' => $role->name, 'scope' => 'PLATFORM'], $requestId);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw new DomainException('ADMIN_ACCOUNT_UNAVAILABLE', 'This login account is unavailable. Choose another account.', 409);
            }
            throw $exception;
        }
    }
}
