<?php

namespace App\Domain\Tenant\Services;

use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Enums\TenantSurface;
use App\Domain\Tenant\Enums\TenantSurfaceAccess;

final class TenantSurfaceAvailability
{
    public function accessFor(TenantStatus $status, TenantSurface $surface): TenantSurfaceAccess
    {
        return match ($surface) {
            TenantSurface::TenantAdmin => match ($status) {
                TenantStatus::Draft, TenantStatus::Active, TenantStatus::Suspended => TenantSurfaceAccess::Allowed,
                TenantStatus::Closed => TenantSurfaceAccess::Unavailable,
            },
            TenantSurface::EndUser => match ($status) {
                TenantStatus::Active => TenantSurfaceAccess::Allowed,
                TenantStatus::Suspended => TenantSurfaceAccess::Restricted,
                TenantStatus::Draft, TenantStatus::Closed => TenantSurfaceAccess::Unavailable,
            },
        };
    }
}
