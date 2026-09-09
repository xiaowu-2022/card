<?php

namespace App\Application\Admin;

use App\Domain\Admin\Models\AdminUser;
use Illuminate\Contracts\Session\Session;

final class TenantAdminRecentAuthentication
{
    private const SESSION_KEY = 'tenant_admin.recent_auth';

    public function mark(Session $session, AdminUser $admin, string $tenantId): void
    {
        $session->put(self::SESSION_KEY, [
            'admin_user_id' => $admin->id,
            'guard' => 'tenant_admin',
            'tenant_id' => $tenantId,
            'password_fingerprint' => hash('sha256', $admin->password),
            'authenticated_at' => now()->getTimestamp(),
        ]);
    }

    public function valid(Session $session, AdminUser $admin, string $tenantId): bool
    {
        $state = $session->get(self::SESSION_KEY);

        return is_array($state)
            && ($state['admin_user_id'] ?? null) === $admin->id
            && ($state['guard'] ?? null) === 'tenant_admin'
            && ($state['tenant_id'] ?? null) === $tenantId
            && hash_equals((string) ($state['password_fingerprint'] ?? ''), hash('sha256', $admin->password))
            && is_int($state['authenticated_at'] ?? null)
            && $state['authenticated_at'] >= now()->subSeconds((int) config('kyc.admin_recent_auth_ttl_seconds'))->getTimestamp();
    }

    public function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}
