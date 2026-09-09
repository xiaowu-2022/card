<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\Repositories\TenantDomainRepository;
use App\Domain\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResolveTenantFromHost
{
    public function __construct(
        private TenantDomainRepository $domains,
        private TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $hostname = strtolower(rtrim($request->getHost(), '.'));

        abort_if($hostname === strtolower((string) config('tenancy.platform_admin_host')), 404);

        $domain = $this->domains->resolveActiveHostname($hostname);
        abort_if($domain === null, 404, 'Tenant not found for this host.');

        $this->context->set($domain->tenant);
        $request->attributes->set('tenant_id', $domain->tenant_id);
        $request->attributes->set('tenant', $domain->tenant);
        Log::withContext(['tenant_id' => $domain->tenant_id]);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
