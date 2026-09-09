<?php

namespace App\Infrastructure\Auth;

use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use LogicException;

final class TenantUserProvider extends EloquentUserProvider
{
    public function __construct($hasher, $model, private readonly TenantContext $tenantContext)
    {
        parent::__construct($hasher, $model);
    }

    protected function newModelQuery($model = null)
    {
        $query = parent::newModelQuery($model);

        return $this->tenantContext->hasTenant()
            ? $query->where('tenant_id', $this->tenantContext->id())
            : $query->whereRaw('1 = 0');
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token): void
    {
        $this->assertCurrentTenant($user);
        parent::updateRememberToken($user, $token);
    }

    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false): void
    {
        $this->assertCurrentTenant($user);
        parent::rehashPasswordIfRequired($user, $credentials, $force);
    }

    private function assertCurrentTenant(Authenticatable $user): void
    {
        if (! $user instanceof User || ! $this->tenantContext->hasTenant() || $user->tenant_id !== $this->tenantContext->id()) {
            throw new LogicException('Tenant user authentication context mismatch.');
        }
    }
}
