<?php

namespace App\Application\Admin\DTOs;

use App\Domain\Admin\Models\AdminInvitation;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;

final readonly class AcceptedAdminInvitation
{
    public function __construct(
        public AdminInvitation $invitation,
        public AdminUser $admin,
        public AdminMembership $membership,
    ) {}
}
