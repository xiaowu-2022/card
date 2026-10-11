<?php

use App\Application\Assets\AssetOverviewQuery;
use App\Application\Assets\AssetValuation;
use App\Application\User\PlatformUserFinancialSummary;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\MarketSnapshot;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
});

it('shares exact local four asset valuation with holds wealth guarantee and card overflow', function () {
    $accounts = collect();
    foreach (['USDT' => '2', 'USDC' => '3', 'ETH' => '0.123456789012345678', 'BTC' => '0.00000001'] as $asset => $amount) {
        foreach (['USER_AVAILABLE', 'USER_WITHDRAWAL_HOLD', 'USER_CARD_ISSUE_HOLD', 'USER_CARD_FUNDING_HOLD', 'USER_WEALTH_PRINCIPAL'] as $type) {
            $accounts->push((object) ['asset_code' => $asset, 'account_type' => $type, 'balance' => $amount, 'card_id' => null]);
        }
    }
    $accounts->push((object) ['asset_code' => 'USDT', 'account_type' => 'USER_SECURITY_DEPOSIT', 'balance' => '50', 'card_id' => null]);
    $accounts->push((object) ['asset_code' => 'USDT', 'account_type' => 'USER_CARD_OVERFLOW', 'balance' => '7', 'card_id' => 'card']);
    $cards = collect([(object) ['id' => 'card', 'card_currency' => 'USD', 'provider_balance' => '13']]);
    $snapshot = new MarketSnapshot(['provider' => 'OKX', 'usdt_rates' => ['USDC' => '1.01', 'ETH' => '2000', 'BTC' => '60000'], 'observed_at' => now()]);
    $v = app(AssetValuation::class)->calculate($accounts, $cards, $snapshot);
    expect($v['estimate'])->toBe('1329.72089012')->and($v['cardBalance'])->toBe('20');
    expect(collect($v['items'])->where('kind', 'wallet')->where('asset', 'ETH')->first()['held'])->toBe('0.370370367037037034');
    expect(app(AssetValuation::class)->calculate($accounts, $cards, null)['estimate'])->toBeNull();
    $snapshot->usdt_rates = ['USDC' => '1.01'];
    expect(app(AssetValuation::class)->calculate($accounts, $cards, $snapshot)['estimate'])->toBeNull();
    $cards[0]->provider_balance = null;
    expect(app(AssetValuation::class)->calculate($accounts, $cards, null)['cardBalance'])->toBeNull();
    Http::assertNothingSent();
});

it('keeps summary reads scoped and identical to the consumer without financial writes', function () {
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $user = User::where('tenant_id', $tenant->id)->firstOrFail();
    $other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $before = collect(['ledger_accounts', 'ledger_entries', 'ledger_postings', 'wallets', 'user_cards', 'audit_logs'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()]);
    $this->actingAs(AdminUser::where('email', 'owner@platform.local')->firstOrFail(), 'platform_admin');
    $base = 'http://admin.localhost/platform/tenants/';
    $consumer = app(AssetOverviewQuery::class)->get($tenant->id, $user->id, []);
    $this->getJson($base.$tenant->id.'/users/'.$user->id.'/funds-summary')->assertOk()->assertJsonPath('estimate', $consumer['estimate']);
    $this->getJson($base.$tenant->id.'/users/'.$user->id.'/cards-summary')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('totals.consumed', '0');
    $this->getJson($base.$other->id.'/users/'.$user->id.'/funds-summary')->assertNotFound();
    $this->getJson($base.$other->id.'/users/'.$user->id.'/cards-summary')->assertNotFound();
    $batch = app(PlatformUserFinancialSummary::class)->forUsers([(object) ['id' => $user->id, 'tenant_id' => $tenant->id]], []);
    expect($batch[$tenant->id.':'.$user->id])->toBe([]);
    foreach ($before as $t => $json) {
        expect(DB::table($t)->orderBy('id')->get()->toJson())->toBe($json);
    }
    Http::assertNothingSent();
});

it('withholds funds estimates and endpoints when a required read permission is unavailable', function () {
    $this->actingAs(AdminUser::where('email', 'owner@platform.local')->firstOrFail(), 'platform_admin');
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $user = User::where('tenant_id', $tenant->id)->firstOrFail();
    $permission = DB::table('permissions')->where('name', 'cards.read')->value('id');
    DB::table('role_permissions')->where('permission_id', $permission)->delete();
    DB::table('permissions')->where('id', $permission)->delete();
    $base = 'http://admin.localhost/platform/tenants/'.$tenant->id.'/users/'.$user->id;
    $this->getJson($base.'/funds-summary')->assertForbidden();
    $this->getJson($base.'/cards-summary')->assertForbidden();
    $this->get('http://admin.localhost/platform/users?company='.$tenant->id)->assertOk()->assertInertia(fn ($p) => $p->where('financialAccess.funds', false)->missing('users.data.0.financialSummary.estimate')->missing('users.data.0.financialSummary.cardBalance'));
});
