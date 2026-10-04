<?php

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed();
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false]);
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($this->owner, 'platform_admin');
});

it('lists every company configuration with pagination and rejects invalid company filters', function () {
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->get('http://admin.localhost/platform/company-configurations')->assertOk()->assertInertia(fn (Assert $page) => $page->component('platform/CompanyConfigurations')->where('records.total', Tenant::count()));
    $this->get('http://admin.localhost/platform/company-configurations?company='.$tenant->id)->assertOk()->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.data.0.id', $tenant->id));
    $this->getJson('http://admin.localhost/platform/company-configurations?company='.(string) Str::uuid())->assertUnprocessable();
    Http::assertNothingSent();
});

it('defaults partner list to all companies with row-owned identity and no writes', function () {
    foreach (Tenant::limit(2)->get() as $tenant) {
        $user = User::where('tenant_id', $tenant->id)->firstOrFail();
        DB::table('partner_configurations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $user->id, 'enabled' => true, 'share_percent' => '40', 'updated_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $before = DB::table('ledger_entries')->count();
    $this->get('http://admin.localhost/platform/partners')->assertOk()->assertInertia(fn (Assert $page) => $page->has('partners.data', 2)->where('companyId', null)->has('partners.data.0.tenant_id')->has('partners.data.0.company_name'));
    expect(DB::table('ledger_entries')->count())->toBe($before);
    $this->getJson('http://admin.localhost/platform/partners?company='.(string) Str::uuid())->assertUnprocessable();
});

it('offers all-company sent notifications without requiring company selection', function () {
    $this->get('http://admin.localhost/platform/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page->where('company', null)->where('batches.total', 0));
    $this->getJson('http://admin.localhost/platform/notifications?company='.(string) Str::uuid())->assertUnprocessable();
});

it('redirects old editor links while allowing lazy authorized DTO reads', function () {
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $user = User::where('tenant_id', $tenant->id)->firstOrFail();
    $url = 'http://admin.localhost/platform/tenants/'.$tenant->id.'/users/'.$user->id.'/manual-commissions';
    $this->get($url)->assertRedirectContains('/platform/users?editor=');
    $this->withHeader('X-Admin-Dialog', '1')->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('platform/ManualCommission')->where('account.companyId', $tenant->id));
    $other = User::where('tenant_id', '<>', $tenant->id)->firstOrFail();
    $this->get(str_replace($user->id, $other->id, $url))->assertNotFound();
    $this->actingAs(AdminUser::where('email', 'owner@a.localhost')->firstOrFail(), 'platform_admin')->get($url)->assertForbidden();
});

it('returns JSON for a successful editor mutation and preserves validation errors', function () {
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $url = 'http://admin.localhost/platform/tenants/'.$tenant->id.'/name';
    $this->withHeader('X-Admin-Dialog', '1')->putJson($url, ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->putJson($url, ['name' => 'Offline renamed company'])->assertOk()->assertJson(['saved' => true]);
    expect($tenant->fresh()->name)->toBe('Offline renamed company');
});

it('paginates all-company notification history without creating delivery work', function () {
    $companies = Tenant::orderBy('id')->limit(2)->get();
    foreach (range(1, 23) as $index) {
        DB::table('inbox_broadcasts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $companies[$index % 2]->id, 'actor_id' => $this->owner->id, 'intent_hash' => hash('sha256', 'offline:'.$index), 'audience' => 'selected', 'title' => 'Synthetic '.$index, 'body' => 'Offline fixture only', 'recipient_count' => 0, 'created_at' => now()->subMinutes($index)]);
    }
    $this->get('http://admin.localhost/platform/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page->where('batches.total', 23)->has('batches.data', 20)->has('batches.data.0.company_name'));
    $this->get('http://admin.localhost/platform/notifications?page=2')->assertOk()->assertInertia(fn (Assert $page) => $page->has('batches.data', 3));
    $this->get('http://admin.localhost/platform/notifications?company='.$companies[0]->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('batches.total', 11)->where('batches.data.0.tenant_id', $companies[0]->id));
    expect(DB::table('inbox_events')->count())->toBe(0);
});
