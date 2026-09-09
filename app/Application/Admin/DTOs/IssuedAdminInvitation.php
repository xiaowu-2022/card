<?php

namespace App\Application\Admin\DTOs;

use App\Domain\Admin\Models\AdminInvitation;

final readonly class IssuedAdminInvitation
{
    public function __construct(
        public AdminInvitation $invitation,
        public string $rawToken,
        public string $url,
    ) {}
}
