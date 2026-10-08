<?php

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false]);
    $this->actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->actingAs($this->actor, 'platform_admin');
});

it('redirects every company configuration entry to the one list and keeps embedded reads scoped', function () {
    $before = DB::table('ledger_entries')->count();
    foreach (['onboarding', 'domains', 'card-products', 'team', 'settings/branding', 'settings/locales', 'settings/business', 'settings/articles', 'settings/sms', 'settings/email', 'promotion', 'wealth'] as $section) {
        $path = '/platform/tenants/'.$this->tenant->id.'/configuration/'.$section;
        $this->get('http://admin.localhost'.$path)->assertRedirectContains('/platform/tenants?editor=');
    }
    foreach (['hours', 'replies', 'bot'] as $section) {
        $path = '/platform/support/'.$section.'?company='.$this->tenant->id;
        $this->get('http://admin.localhost'.$path)->assertRedirectContains('/platform/tenants?editor=');
        $this->withHeaders(['X-Admin-Dialog' => '1', 'X-Admin-Company' => $this->tenant->id])->get('http://admin.localhost'.$path)
            ->assertOk()->assertInertia(fn (Assert $p) => $p->where('configurationCompany.id', $this->tenant->id)->where('filters.company', $this->tenant->id));
        $this->flushHeaders();
    }
    expect(DB::table('ledger_entries')->count())->toBe($before);
    Http::assertNothingSent();
});

it('rejects mismatched drawer company before configuration mutations', function () {
    $other = Tenant::where('id', '!=', $this->tenant->id)->firstOrFail();
    $this->withHeaders(['X-Admin-Dialog' => '1', 'X-Admin-Company' => $this->tenant->id])
        ->putJson('http://admin.localhost/platform/tenants/'.$other->id.'/name', ['name' => 'Wrong company'])->assertForbidden();
    $this->postJson('http://admin.localhost/platform/support/bot/settings', ['company' => $other->id, 'enabled' => true, 'revision' => 0])->assertForbidden();
    $this->getJson('http://admin.localhost/platform/support/hours?company='.$other->id)->assertForbidden();
});

it('allows support configuration users into the directory without financial summaries or tenant management', function () {
    $keep = DB::table('permissions')->whereIn('name', ['support.read', 'support.hours.manage', 'wallet_topups.read'])->pluck('id');
    DB::table('role_permissions')->whereNotIn('permission_id', $keep)->delete();
    $this->actingAs($this->actor->fresh(), 'platform_admin');
    $this->get('http://admin.localhost/platform/tenants')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('financialAccess.inflow', false)->where('financialAccess.outflow', false)->missing('tenants.data.0.inflow')->missing('totals.inflow'));
    $this->withHeaders(['X-Admin-Dialog' => '1', 'X-Admin-Company' => $this->tenant->id]);
    $this->get('http://admin.localhost/platform/support/hours?company='.$this->tenant->id)->assertOk();
    $this->get('http://admin.localhost/platform/tenants/'.$this->tenant->id.'/configuration/settings/branding')->assertForbidden();
    $this->get('http://admin.localhost/platform/support/bot?company='.$this->tenant->id)->assertForbidden();
});

it('preserves list filters and selected categories in legacy configuration URLs', function () {
    $response = $this->get('http://admin.localhost/platform/company-configurations?company='.$this->tenant->id.'&section=team&search=tenant&status=ACTIVE&page=2');
    $response->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['company' => $this->tenant->id, 'search' => 'tenant', 'status' => 'ACTIVE', 'page' => '2', 'editor' => '/platform/tenants/'.$this->tenant->id.'/configuration/team']);
    $this->getJson('http://admin.localhost/platform/tenants?company=invalid')->assertUnprocessable();
});
