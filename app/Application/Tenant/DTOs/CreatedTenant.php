<?php

namespace App\Application\Tenant\DTOs;

use App\Application\Admin\DTOs\IssuedAdminInvitation;
use App\Domain\Tenant\Models\Tenant;

final readonly class CreatedTenant
{
    public function __construct(
        public Tenant $tenant,
        public IssuedAdminInvitation $ownerInvitation,
    ) {}
}
