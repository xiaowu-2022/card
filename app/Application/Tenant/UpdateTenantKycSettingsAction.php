<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Enums\KycReviewMode;
use App\Domain\Tenant\Models\Tenant;

final readonly class UpdateTenantKycSettingsAction
{
    public function execute(Tenant $tenant, bool $enabled, int $maxAccountsPerIdentity, AdminUser $actor, ?string $requestId = null, KycReviewMode $reviewMode = KycReviewMode::Manual): void
    {
        abort(403);
    }
}
