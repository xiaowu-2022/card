<?php

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
});

it('publishes all and only active company domains without authentication or writes', function () {
    foreach (['ACTIVE', 'DISABLED', 'PENDING_VERIFICATION'] as $status) {
        TenantDomain::create(['tenant_id' => $this->tenant->id, 'hostname' => strtolower(str_replace('_', '-', $status)).'.example.org', 'domain_type' => 'CUSTOM_DOMAIN', 'status' => $status, 'is_primary' => false]);
    }
    TenantDomain::create(['tenant_id' => null, 'hostname' => 'unassigned.example.org', 'domain_type' => 'CUSTOM_DOMAIN', 'status' => 'ACTIVE', 'is_primary' => false]);
    $before = [DB::table('ledger_entries')->count(), DB::table('audit_logs')->count(), DB::table('consumer_device_tokens')->count()];
    $response = $this->getJson('https://a.localhost/api/mobile/v1/domains?tenant_id=other')
        ->assertOk()->assertJsonPath('tenant.id', $this->tenant->id)->assertJsonPath('tenant.slug', 'tenant-a');
    expect($response->json('origins'))->toContain('https://active.example.org', 'https://a.localhost')
        ->not->toContain('https://disabled.example.org', 'https://pending-verification.example.org', 'https://unassigned.example.org', 'https://b.localhost');
    expect(array_keys($response->json()))->toBe(['tenant', 'origins']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect([DB::table('ledger_entries')->count(), DB::table('audit_logs')->count(), DB::table('consumer_device_tokens')->count()])->toBe($before);
    $this->getJson('https://active.example.org/api/mobile/v1/domains')->assertOk()->assertJsonPath('tenant.id', $this->tenant->id);
    $this->getJson('https://disabled.example.org/api/mobile/v1/domains')->assertNotFound();
    $this->getJson('https://unassigned.example.org/api/mobile/v1/domains')->assertNotFound();
    $this->getJson('https://admin.localhost/api/mobile/v1/domains')->assertNotFound();
});

it('rejects unavailable companies and follows active domain reassignment', function () {
    $domain = TenantDomain::create(['tenant_id' => $this->tenant->id, 'hostname' => 'alternate.example.org', 'domain_type' => 'CUSTOM_DOMAIN', 'status' => 'ACTIVE', 'is_primary' => false]);
    $other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $domain->update(['tenant_id' => $other->id]);
    $this->getJson('https://alternate.example.org/api/mobile/v1/domains')->assertOk()->assertJsonPath('tenant.id', $other->id);
    $this->tenant->update(['status' => 'CLOSED', 'closed_at' => now()]);
    $this->getJson('https://a.localhost/api/mobile/v1/domains')->assertStatus(503);
});

it('limits repeated probes per host without consuming other domain or login allowances', function () {
    TenantDomain::create(['tenant_id' => $this->tenant->id, 'hostname' => 'alternate.example.org', 'domain_type' => 'CUSTOM_DOMAIN', 'status' => 'ACTIVE', 'is_primary' => false]);
    for ($i = 0; $i < 30; $i++) {
        $this->getJson('https://a.localhost/api/mobile/v1/domains')->assertOk();
    }
    $this->getJson('https://a.localhost/api/mobile/v1/domains')->assertStatus(429);
    $this->getJson('https://alternate.example.org/api/mobile/v1/domains')->assertOk();
    $this->getJson('https://a.localhost/api/mobile/v1/bootstrap')->assertOk();
});
