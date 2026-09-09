<?php

namespace App\Domain\Tenant\Enums;

enum TenantDomainType: string
{
    case SystemSubdomain = 'SYSTEM_SUBDOMAIN';
    case CustomDomain = 'CUSTOM_DOMAIN';
}
