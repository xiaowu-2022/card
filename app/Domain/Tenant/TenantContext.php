<?php

namespace App\Domain\Tenant;

use App\Domain\Tenant\Models\Tenant;
use LogicException;

final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new LogicException('No tenant is bound to the current execution context.');
    }

    public function id(): string
    {
        return $this->tenant()->getKey();
    }

    public function clear(): void
    {
        $this->tenant = null;
    }
}
