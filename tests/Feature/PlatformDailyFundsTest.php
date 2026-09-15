<?php

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Tenant\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($this->owner, 'platform_admin');
    $this->url = 'http://admin.localhost/platform/demo';
});

it('defaults to 30 UTC+8 calendar days and all persisted companies without creating money', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-13T16:01:00Z'));
    $before = LedgerAccount::query()->count();
    $this->get($this->url)->assertOk()->assertInertia(fn ($p) => $p
        ->component('platform/Dashboard')->where('filters.start', '2026-08-16')->where('filters.end', '2026-09-14')
        ->where('filters.scope', 'all')->where('filters.companies', [])->has('companies', 2)
        ->has('days', 30)->where('days.0.date', '2026-08-16')->where('days.29.date', '2026-09-14')
        ->where('totals', ['inflow' => '0.00000000', 'outflow' => '0.00000000', 'net' => '0.00000000'])
        ->missing('cardProviderCount'));
    expect(LedgerAccount::query()->count())->toBe($before);
    Http::assertNothingSent();
});

it('validates real company selections inclusive dates and a bounded date range', function (): void {
    $id = Tenant::query()->firstOrFail()->id;
    foreach ([
        ['start' => 'invalid'], ['start' => '2026-02-30'], ['end' => '2026-99-20'],
        ['start' => '2026-09-14', 'end' => '2026-09-13'],
        ['start' => '2025-01-01', 'end' => '2026-01-02'],
        ['scope' => 'selected'], ['scope' => 'selected', 'companies' => []],
        ['scope' => 'selected', 'companies' => ['bad']],
        ['scope' => 'selected', 'companies' => [(string) Str::uuid()]],
        ['scope' => 'selected', 'companies' => [$id, $id]], ['scope' => 'forged'],
    ] as $filters) {
        $this->getJson($this->url.'?'.http_build_query($filters))->assertUnprocessable();
    }
    $this->get($this->url.'?'.http_build_query(['start' => '2026-09-13', 'end' => '2026-09-13', 'scope' => 'selected', 'companies' => [$id]]))
        ->assertOk()->assertInertia(fn ($p) => $p->has('days', 1)->where('filters.companies', [$id]));
    $this->get($this->url.'?start=2025-01-01&end=2026-01-01')->assertOk()->assertInertia(fn ($p) => $p->has('days', 366));
});

it('omits unauthorized money series and net values and rejects company identities on the platform', function (): void {
    $url = $this->url.'?start=2026-09-13&end=2026-09-13&inflow=1&outflow=1';
    $permission = DB::table('permissions')->where('name', 'withdrawals.read')->value('id');
    DB::table('role_permissions')->where('permission_id', $permission)->delete();
    $this->get($url)->assertOk()->assertInertia(fn ($p) => $p
        ->where('financialAccess', ['inflow' => true, 'outflow' => false])
        ->has('totals.inflow')->missing('totals.outflow')->missing('totals.net')
        ->has('days.0.inflow')->missing('days.0.outflow')->missing('days.0.net'));
    $permission = DB::table('permissions')->where('name', 'wallet_topups.read')->value('id');
    DB::table('role_permissions')->where('permission_id', $permission)->delete();
    $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->where('totals', [])->where('days', [['date' => '2026-09-13']]));
    $company = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($company, 'platform_admin')->get($url)->assertForbidden();
    Http::assertNothingSent();
});
