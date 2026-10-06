<?php

namespace App\Application\User;

use App\Application\Promotion\PromotionMembershipAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserPreference;
use App\Domain\User\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final readonly class CreatePlatformUserAction
{
    public function __construct(private AuthorizationService $authorization, private ActivateUserWalletAction $wallets, private PromotionMembershipAction $members, private AuditLogger $audit) {}

    public function execute(string $tenantId, AdminUser $actor, array $data): User
    {
        abort_unless($this->authorization->allows($actor, ScopeType::Platform, null, 'users.read')
            && $this->authorization->allows($actor, ScopeType::Platform, null, 'users.create'), 403);

        return DB::transaction(function () use ($tenantId, $actor, $data): User {
            $tenant = Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $email = strtolower(trim($data['email']));
            $name = trim($data['display_name'] ?? '') ?: null;
            $previous = DB::table('platform_user_creations')->where('tenant_id', $tenantId)->where('actor_id', $actor->id)->where('request_id', $data['request_id'])->first();
            if ($previous) {
                $user = User::where('tenant_id', $tenantId)->findOrFail($previous->user_id);
                abort_unless($user->email === $email && $user->profile?->display_name === $name && Hash::check($data['password'], $user->password_hash), 409);

                return $user;
            }
            if ($tenant->status !== TenantStatus::Active) {
                throw ValidationException::withMessages(['company' => 'Select an active company.']);
            }
            if (User::where('tenant_id', $tenantId)->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'An account already exists for this contact.']);
            }
            $locale = $tenant->locales()->where('enabled', true)->where('is_default', true)->value('locale');
            if (! is_string($locale)) {
                throw ValidationException::withMessages(['company' => 'Registration is temporarily unavailable.']);
            }
            $user = User::create([
                'tenant_id' => $tenantId, 'email' => $email, 'password_hash' => Hash::make($data['password']),
                'status' => UserStatus::Active, 'email_verified_at' => null, 'phone_verified_at' => null,
            ]);
            UserProfile::create(['tenant_id' => $tenantId, 'user_id' => $user->id, 'display_name' => $name]);
            UserPreference::create(['tenant_id' => $tenantId, 'user_id' => $user->id, 'locale' => $locale]);
            DB::table('platform_user_creations')->insert([
                'tenant_id' => $tenantId, 'user_id' => $user->id, 'actor_id' => $actor->id,
                'request_id' => $data['request_id'], 'verified_at' => now(),
            ]);
            $this->wallets->execute($tenantId, $user->id, $data['request_id']);
            $this->members->ensure($tenantId, $user->id);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'PLATFORM_USER_CREATED', 'user', $user->id, null,
                ['kyc_status' => 'APPROVED', 'verification_source' => 'PLATFORM_CREATION'], $data['request_id']);

            return $user->refresh();
        }, 3);
    }
}
