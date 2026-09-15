<?php

use App\Application\Tenant\AddCustomDomainAction;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Contracts\DomainVerificationService;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Domain\Tenant\Repositories\TenantDomainRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    config(['inertia.ssr.enabled' => false]);
    $this->seed();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->other = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $this->owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->mock(DomainVerificationService::class)->shouldReceive('verify')->andReturn(true);
    $this->companyAdmin = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->url = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/domains';
});

it('removes every company domain endpoint including direct API writes', function (): void {
    $before = TenantDomain::query()->orderBy('id')->get()->toJson();
    $domain = $this->tenant->domains()->firstOrFail();
    $this->actingAs($this->companyAdmin, 'tenant_admin');
    $this->get('http://a.localhost/admin/domains')->assertNotFound();
    $this->postJson('http://a.localhost/admin/domains', ['hostname' => 'blocked.example.test'])->assertNotFound();
    foreach (['verify', 'activate', 'primary'] as $action) {
        $this->postJson('http://a.localhost/admin/domains/'.$domain->id.'/'.$action)->assertNotFound();
    }
    $this->deleteJson('http://a.localhost/admin/domains/'.$domain->id)->assertNotFound();
    expect(TenantDomain::query()->orderBy('id')->get()->toJson())->toBe($before);
});

it('allows the platform owner to manage only domains belonging to the selected company', function (): void {
    $this->actingAs($this->owner, 'platform_admin');
    $configurationUrl = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/configuration/domains';
    $this->get($this->url)->assertRedirect($configurationUrl);
    $this->get($configurationUrl)->assertOk()->assertInertia(fn ($page) => $page->component('platform/Domains')
        ->where('company.id', $this->tenant->id)->where('configurationCompany.id', $this->tenant->id));
    $this->postJson($this->url, ['hostname' => 'blocked.example.test'])->assertForbidden();
    $global = 'http://admin.localhost/platform/settings/domains';
    $this->postJson($global, ['hostname' => 'platform-managed.example.test'])->assertRedirect();
    $domain = TenantDomain::query()->whereNull('tenant_id')->where('hostname', 'platform-managed.example.test')->firstOrFail();
    expect($domain->status->value)->toBe('PENDING_VERIFICATION');
    $this->postJson($this->url.'/'.$domain->id.'/activate')->assertNotFound();
    $wrong = 'http://admin.localhost/platform/tenants/'.$this->other->id.'/domains/'.$domain->id;
    $this->postJson($wrong.'/verify')->assertUnprocessable();
    $this->postJson($wrong.'/activate')->assertNotFound();
    $this->postJson($wrong.'/primary')->assertNotFound();
    $this->deleteJson($wrong)->assertNotFound();
    $this->postJson($global.'/'.$domain->id.'/activate')->assertUnprocessable();
    $this->postJson($global.'/'.$domain->id.'/verify')->assertRedirect();
    $this->postJson($global.'/'.$domain->id.'/activate')->assertRedirect();
    $this->postJson($configurationUrl, ['domain_ids' => [$domain->id], 'original_ids' => [], 'confirmed' => true])->assertRedirect();
    $this->postJson($this->url.'/'.$domain->id.'/primary')->assertRedirect();
    expect($domain->fresh()->is_primary)->toBeTrue();
    $this->deleteJson($this->url.'/'.$domain->id)->assertUnprocessable();
    $system = $this->tenant->domains()->where('domain_type', 'SYSTEM_SUBDOMAIN')->firstOrFail();
    $this->postJson($this->url.'/'.$system->id.'/primary')->assertRedirect();
    $this->deleteJson($this->url.'/'.$system->id)->assertUnprocessable();
    $this->deleteJson($this->url.'/'.$domain->id)->assertRedirect();
    expect(TenantDomain::query()->whereKey($domain->id)->exists())->toBeFalse();
    expect(DB::table('audit_logs')->where('action', 'DOMAIN_ADDED')->where('resource_id', $domain->id)->where('actor_id', $this->owner->id)->exists())->toBeTrue();
});

it('requires an active platform identity and membership and rejects company identities on platform routes', function (): void {
    $this->actingAs($this->companyAdmin, 'platform_admin')->getJson($this->url)->assertForbidden();
    $this->postJson($this->url, ['hostname' => 'blocked.example.test'])->assertForbidden();
    $this->actingAs($this->owner, 'platform_admin');
    AdminMembership::query()->where('admin_user_id', $this->owner->id)->update(['status' => 'SUSPENDED']);
    $this->getJson($this->url)->assertForbidden();
    $this->postJson($this->url, ['hostname' => 'blocked.example.test'])->assertForbidden();
});

it('assigns multiple ready domains atomically and excludes unassigned hosts from tenant resolution', function (): void {
    $this->actingAs($this->owner, 'platform_admin');
    $global = 'http://admin.localhost/platform/settings/domains';
    $ids = [];
    foreach (['one.example.test', 'two.example.test'] as $hostname) {
        $this->postJson($global, ['hostname' => $hostname])->assertRedirect();
        $domain = TenantDomain::query()->where('hostname', $hostname)->firstOrFail();
        $this->postJson($global.'/'.$domain->id.'/verify')->assertRedirect();
        $this->postJson($global.'/'.$domain->id.'/activate')->assertRedirect();
        expect(app(TenantDomainRepository::class)->resolveActiveHostname($hostname))->toBeNull();
        $this->get('http://'.$hostname)->assertNotFound();
        $ids[] = $domain->id;
    }
    $url = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/configuration/domains';
    $payload = ['domain_ids' => $ids, 'original_ids' => [], 'confirmed' => true];
    $this->postJson($url, [...$payload, 'confirmed' => false])->assertUnprocessable();
    $this->postJson($url, [...$payload, 'tenant_id' => $this->other->id])->assertUnprocessable();
    $this->postJson($url, $payload)->assertRedirect();
    foreach ($ids as $id) {
        $domain = TenantDomain::query()->findOrFail($id);
        expect($domain->tenant_id)->toBe($this->tenant->id)
            ->and(app(TenantDomainRepository::class)->resolveActiveHostname($domain->hostname)?->tenant_id)->toBe($this->tenant->id);
        $this->deleteJson($global.'/'.$id)->assertUnprocessable();
    }
    $otherUrl = 'http://admin.localhost/platform/tenants/'.$this->other->id.'/configuration/domains';
    $this->get($otherUrl)->assertInertia(fn ($page) => $page->has('domains', 1));
    $this->postJson($otherUrl, $payload)->assertUnprocessable();
    $this->postJson($url, $payload)->assertUnprocessable(); // stale selection
    $this->postJson($url, ['domain_ids' => [$ids[0]], 'original_ids' => $ids, 'confirmed' => true])->assertRedirect();
    $removed = TenantDomain::query()->findOrFail($ids[1]);
    expect($removed->tenant_id)->toBeNull()
        ->and(app(TenantDomainRepository::class)->resolveActiveHostname($removed->hostname))->toBeNull();
    $this->postJson($otherUrl, ['domain_ids' => [$ids[1]], 'original_ids' => [], 'confirmed' => true])->assertRedirect();
    expect($removed->fresh()->tenant_id)->toBe($this->other->id);
    expect(DB::table('audit_logs')->where('action', 'COMPANY_DOMAINS_ASSIGNED')->where('actor_id', $this->owner->id)->whereNotNull('created_at')->count())->toBe(3);
});

it('protects primary and system assignments and rejects unverified selections without partial writes', function (): void {
    $this->actingAs($this->owner, 'platform_admin');
    $global = 'http://admin.localhost/platform/settings/domains';
    $url = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/configuration/domains';
    $this->postJson($global, ['hostname' => 'pending.example.test'])->assertRedirect();
    $pending = TenantDomain::query()->where('hostname', 'pending.example.test')->firstOrFail();
    $system = $this->tenant->domains()->firstOrFail();
    foreach ([$pending->id, $system->id] as $id) {
        $this->postJson($url, ['domain_ids' => [$id], 'original_ids' => [], 'confirmed' => true])->assertUnprocessable();
    }
    expect($pending->fresh()->tenant_id)->toBeNull()->and($system->fresh()->tenant_id)->toBe($this->tenant->id);
    $this->postJson($global.'/'.$pending->id.'/verify')->assertRedirect();
    $this->postJson($global.'/'.$pending->id.'/activate')->assertRedirect();
    $this->postJson($url, ['domain_ids' => [$pending->id], 'original_ids' => [], 'confirmed' => true])->assertRedirect();
    $this->postJson($this->url.'/'.$pending->id.'/primary')->assertRedirect();
    $this->postJson($url, ['domain_ids' => [], 'original_ids' => [$pending->id], 'confirmed' => true])->assertUnprocessable();
    expect($pending->fresh()->tenant_id)->toBe($this->tenant->id)->and($pending->fresh()->is_primary)->toBeTrue();
    $this->postJson($this->url.'/'.$system->id.'/primary')->assertRedirect();
    $this->postJson($url, ['domain_ids' => [], 'original_ids' => [$pending->id], 'confirmed' => true])->assertRedirect();
    $this->deleteJson($global.'/'.$pending->id)->assertRedirect();
    expect(TenantDomain::query()->find($pending->id))->toBeNull();
});

it('requires platform authority for the global catalog and direct domain actions', function (): void {
    $global = 'http://admin.localhost/platform/settings/domains';
    $this->actingAs($this->companyAdmin, 'platform_admin')->getJson($global)->assertForbidden();
    $this->postJson($global, ['hostname' => 'blocked.example.test'])->assertForbidden();
    expect(fn () => app(AddCustomDomainAction::class)->execute(null, 'blocked.example.test', $this->companyAdmin))->toThrow(HttpException::class);
    $this->actingAs($this->owner, 'platform_admin');
    AdminMembership::query()->where('admin_user_id', $this->owner->id)->update(['status' => 'SUSPENDED']);
    $this->getJson($global)->assertForbidden();
    $this->postJson($global, ['hostname' => 'blocked.example.test'])->assertForbidden();
});

it('allocates domains from the global catalog using a persisted company route and keeps company views scoped', function (): void {
    $this->actingAs($this->owner, 'platform_admin');
    $global = 'http://admin.localhost/platform/settings/domains';
    $this->get($global)->assertInertia(fn ($page) => $page->where('company', null)->has('companies', 2)->has('domains', 2));
    $ids = [];
    foreach (['global-one.example.test', 'global-two.example.test'] as $hostname) {
        $this->postJson($global, ['hostname' => $hostname])->assertRedirect();
        $domain = TenantDomain::query()->where('hostname', $hostname)->firstOrFail();
        $this->postJson($global.'/'.$domain->id.'/verify')->assertRedirect();
        $this->postJson($global.'/'.$domain->id.'/activate')->assertRedirect();
        $ids[] = $domain->id;
    }
    $companyView = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/configuration/domains';
    $this->get($companyView)->assertInertia(fn ($page) => $page->has('domains', 1)->missing('companies'));
    $assignmentUrl = $global.'/assign/'.$this->tenant->id;
    $payload = ['domain_ids' => $ids, 'original_ids' => [], 'confirmed' => true];
    $this->postJson($assignmentUrl, [...$payload, 'tenant_id' => $this->other->id])->assertUnprocessable();
    $this->postJson($assignmentUrl, $payload)->assertRedirect();
    $this->get($companyView)->assertInertia(fn ($page) => $page->has('domains', 3));
    expect(TenantDomain::query()->whereIn('id', $ids)->where('tenant_id', $this->tenant->id)->count())->toBe(2);
    $this->postJson($global.'/assign/'.$this->other->id, $payload)->assertUnprocessable();
    $this->postJson($assignmentUrl, ['domain_ids' => [$ids[0]], 'original_ids' => $ids, 'confirmed' => true])->assertRedirect();
    expect(TenantDomain::query()->findOrFail($ids[0])->tenant_id)->toBe($this->tenant->id)
        ->and(TenantDomain::query()->findOrFail($ids[1])->tenant_id)->toBeNull();
    $this->actingAs($this->companyAdmin, 'platform_admin')->postJson($assignmentUrl, $payload)->assertForbidden();
});
