<?php

namespace App\Application\Notification;

use App\Application\Notification\DTOs\UpdateTenantEmailSettings;
use App\Domain\Admin\Models\AdminUser;

final readonly class UpdateTenantEmailSettingsAction
{
    public function execute(string $tenantId, #[\SensitiveParameter] UpdateTenantEmailSettings $data, AdminUser $actor, ?string $requestId = null): void
    {
        abort(403);
    }
}
