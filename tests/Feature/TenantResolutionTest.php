<?php

use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;

beforeEach(fn () => $this->seed());

it('resolves tenant A and tenant B from their hosts', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();

    $this->getJson('http://a.localhost/__tenant/context')->assertOk()->assertJson(['tenant_id' => $tenantA->id]);
    $this->getJson('http://b.localhost/__tenant/context')->assertOk()->assertJson(['tenant_id' => $tenantB->id]);
});

it('rejects unknown hosts and never treats platform host as a tenant', function (): void {
    $this->getJson('http://unknown.localhost/__tenant/context')->assertNotFound();
    $this->getJson('http://admin.localhost/__tenant/context')->assertNotFound();
});

it('requires an active tenant domain even when the tenant is active', function (): void {
    TenantDomain::query()->where('hostname', 'a.localhost')->firstOrFail()
        ->update(['status' => TenantDomainStatus::Disabled]);

    $this->getJson('http://a.localhost/__tenant/context')->assertNotFound();
});

it('does not allow a client tenant id to switch host context', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();

    $this->postJson('http://a.localhost/__tenant/context?tenant_id='.$tenantB->id, ['tenant_id' => $tenantB->id])
        ->assertStatus(405);

    $this->withHeader('X-Tenant-ID', $tenantB->id)
        ->getJson('http://a.localhost/__tenant/context?tenant_id='.$tenantB->id)
        ->assertOk()
        ->assertJson(['tenant_id' => $tenantA->id]);
});

it('returns a request id and accepts a safe externally supplied one', function (): void {
    $requestId = '018f3f64-89cd-7a2e-a91d-1f6c8d2b92a0';

    $this->withHeader('X-Request-ID', $requestId)
        ->getJson('http://a.localhost/__tenant/context')
        ->assertHeader('X-Request-ID', $requestId);
});
