<?php

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Domain\Tenant\Repositories\TenantDomainRepository;

beforeEach(fn () => $this->seed());

it('does not return tenant B resources in tenant A queries', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $domainB = TenantDomain::query()->where('hostname', 'b.localhost')->firstOrFail();

    expect(app(TenantDomainRepository::class)->findForTenant($tenantA->id, $domainB->id))->toBeNull();
});

it('prevents tenant A admin membership from authorizing tenant B', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $admin = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $authorization = app(AuthorizationService::class);

    expect($authorization->allows($admin, ScopeType::Tenant, $tenantA->id, 'users.read'))->toBeTrue()
        ->and($authorization->allows($admin, ScopeType::Tenant, $tenantB->id, 'users.read'))->toBeFalse();
});
