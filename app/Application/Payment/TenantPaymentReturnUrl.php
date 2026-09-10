<?php

namespace App\Application\Payment;

use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Models\TenantDomain;
use App\Support\Errors\DomainException;

final class TenantPaymentReturnUrl
{
    public function forResolvedHost(string $tenantId, string $requestHost, ?int $requestPort = null): string
    {
        $hostname = strtolower(rtrim($requestHost, '.'));
        $trusted = TenantDomain::query()->where('tenant_id', $tenantId)->where('hostname', $hostname)
            ->where('status', TenantDomainStatus::Active->value)->value('hostname');
        if (! $trusted) {
            throw new DomainException('PAYMENT_RETURN_HOST_INVALID', 'Payment return destination is unavailable.', 409);
        }
        $scheme = app()->environment(['local', 'testing']) ? 'http' : 'https';
        $port = app()->environment(['local', 'testing']) && $requestPort !== null && ! in_array($requestPort, [80, 443], true)
            ? ':'.$requestPort
            : '';

        return "{$scheme}://{$trusted}{$port}/wallet/top-ups/__ORDER__/return";
    }
}
