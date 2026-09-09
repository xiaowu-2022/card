<?php

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Repositories\TenantRepository;

beforeEach(fn () => $this->seed());

it('resolves a draft tenant for admin setup but blocks its end-user surface', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $activeTenant = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $admin = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $tenant->update(['status' => TenantStatus::Draft]);

    $this->getJson('http://a.localhost/__tenant/context')
        ->assertOk()
        ->assertJson(['tenant_id' => $tenant->id]);
    $this->actingAs($admin, 'tenant_admin')->get('http://a.localhost/admin/demo')->assertOk();
    $this->get('http://a.localhost/demo')->assertServiceUnavailable();
    $this->withHeader('X-Tenant-ID', $activeTenant->id)
        ->get('http://a.localhost/demo?tenant_id='.$activeTenant->id)
        ->assertServiceUnavailable();
});

it('allows an active tenant on end-user and tenant-admin surfaces', function (): void {
    $admin = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->get('http://a.localhost/demo')->assertOk();
    $this->actingAs($admin, 'tenant_admin')->get('http://a.localhost/admin/demo')->assertOk();
});

it('resolves a suspended tenant without treating it as nonexistent', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $admin = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $tenant->update(['status' => TenantStatus::Suspended]);

    $this->getJson('http://a.localhost/__tenant/context')
        ->assertOk()
        ->assertJson(['tenant_id' => $tenant->id]);
    $this->actingAs($admin, 'tenant_admin')->get('http://a.localhost/admin/demo')->assertOk();
    $this->get('http://a.localhost/demo')->assertStatus(423);
});

it('retains closed tenant records for authorized platform inspection', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenant->update(['status' => TenantStatus::Closed, 'closed_at' => now()]);
    $platformOwner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();

    expect(app(AuthorizationService::class)->allows($platformOwner, ScopeType::Platform, null, 'tenant.read'))->toBeTrue()
        ->and(app(TenantRepository::class)->findForPlatformInspection($tenant->id)?->status)->toBe(TenantStatus::Closed);

    $this->getJson('http://a.localhost/__tenant/context')->assertOk();
    $this->get('http://a.localhost/admin/demo')->assertServiceUnavailable();
    $this->get('http://a.localhost/demo')->assertServiceUnavailable();
});
