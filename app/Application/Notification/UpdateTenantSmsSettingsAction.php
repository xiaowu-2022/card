<?php

namespace App\Application\Notification;

use App\Application\Notification\DTOs\UpdateTenantSmsSettings;
use App\Domain\Admin\Models\AdminUser;

final readonly class UpdateTenantSmsSettingsAction
{
    public function execute(string $tenantId, #[\SensitiveParameter] UpdateTenantSmsSettings $data, AdminUser $actor, ?string $requestId = null): void
    {
        abort(403);
    }
}
