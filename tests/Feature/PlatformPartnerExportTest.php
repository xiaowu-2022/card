<?php

use App\Application\Partners\PartnerManagement;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('exports all filtered enabled partners using list summaries without changing evidence', function () {
    $this->seed();
    $this->withoutVite();
    Http::preventStrayRequests();
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $base = User::where('tenant_id', $tenant->id)->firstOrFail();
    $accounts = [];
    for ($i = 0; $i < 21; $i++) {
        $user = $base->replicate(['account_id']);
        $user->forceFill(['email' => 'export-partner-'.$i.'@example.test'])->save();
        $user->refresh();
        app(PartnerManagement::class)->configure($owner, $tenant->id, ['account_id' => $user->account_id, 'enabled' => true, 'share_percent' => '40']);
        $accounts[] = $user->account_id;
    }
    $other = User::where('tenant_id', '<>', $tenant->id)->firstOrFail();
    app(PartnerManagement::class)->configure($owner, $other->tenant_id, ['account_id' => $other->account_id, 'enabled' => true, 'share_percent' => '40']);
    $before = DB::table('ledger_accounts')->orderBy('id')->get()->toJson();
    $partners = DB::table('partner_configurations')->orderBy('id')->get()->toJson();
    $this->actingAs($owner, 'platform_admin');
    $response = $this->get('http://admin.localhost/platform/partners/export?company='.$tenant->id.'&page=2&sort=registered_at&direction=desc')
        ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $csv = $response->getContent();
    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue();
    $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\r\n", trim(substr($csv, 3))));
    expect($lines)->toHaveCount(22);
    $header = array_shift($lines);
    expect($header)->toContain('库存 USDT', '理论余额 USDT', '普通会员保证金收入 前30日均 USDT', '导出时间 UTC+8');
    $exported = array_column($lines, array_search('账号', $header));
    expect($exported)->toEqualCanonicalizing($accounts)->not->toContain($other->account_id);
    $this->get('http://admin.localhost/platform/partners?company='.$tenant->id.'&sort=registered_at&direction=desc')
        ->assertOk()->assertInertia(function ($page) use ($lines, $header) {
            $page->where('partners.total', 21);
            foreach (['stock' => '库存 USDT', 'theoretical' => '理论余额 USDT', 'actual' => '实际余额 USDT', 'difference' => '合伙人余额差额 USDT'] as $key => $label) {
                $page->where('partners.data.0.summary.'.$key, $lines[0][array_search($label, $header)]);
            }
        });
    $this->get('http://admin.localhost/platform/partners/export?company='.$tenant->id.'&referrer=missing')->assertOk()
        ->assertSee('导出时间 UTC+8')->assertDontSee($accounts[0]);
    expect(DB::table('ledger_accounts')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(DB::table('partner_configurations')->orderBy('id')->get()->toJson())->toBe($partners);
    Http::assertNothingSent();
});

it('requires platform authorization and validates partner export filters', function () {
    $this->get('http://admin.localhost/platform/partners/export')->assertRedirect();
    $this->seed();
    $owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($owner, 'platform_admin')->getJson('http://admin.localhost/platform/partners/export?direction=invalid')->assertUnprocessable();
});
