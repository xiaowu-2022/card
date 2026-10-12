<?php

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Services\WalletProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($this->owner, 'platform_admin');
    $this->url = 'http://admin.localhost/platform/funds';
});

function globalFundsFixture(Tenant $tenant, User $user, string $asset = 'USDT', string $amount = '300'): void
{
    DB::transaction(fn () => app(WalletProvisioner::class)->provision($tenant, $user, $asset));
    $available = LedgerAccount::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('asset_code', $asset)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $clearing = LedgerAccount::where('tenant_id', $tenant->id)->where('asset_code', $asset)->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
    app(LedgerWriter::class)->post(new LedgerPostingPlan($tenant->id, $asset, 'global-funds:'.Str::uuid(), 'TEST_FUNDING', 'FIXTURE', (string) Str::uuid(), null, [
        new LedgerPostingInstruction($available->id, Money::of($amount, $asset)),
        new LedgerPostingInstruction($clearing->id, Money::of('-'.$amount, $asset)),
    ]));
}

it('reads an empty global history without provisioning wallets or contacting providers', function () {
    $before = collect(['wallets', 'ledger_accounts', 'ledger_entries', 'ledger_postings'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);
    $this->get($this->url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn ($p) => $p->component('platform/Funds')->where('flows.total', 0));
    foreach ($before as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
    Http::assertNothingSent();
});

it('shows complete sealed entries once with customer and company postings and safe native precision', function () {
    globalFundsFixture($this->tenant, $this->user, 'ETH', '0.000000000000000001');
    $before = collect(['wallets', 'ledger_accounts', 'ledger_entries', 'ledger_postings'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()]);
    $this->get($this->url)->assertOk()->assertInertia(fn ($p) => $p->where('flows.total', 1)->has('flows.data', 1)
        ->has('flows.data.0.movements', 2)->where('flows.data.0.movements.0.account', 'TENANT_TOPUP_CLEARING')
        ->where('flows.data.0.movements.0.amount', '-0.000000000000000001')->where('flows.data.0.movements.0.user', null)
        ->where('flows.data.0.movements.1.amount', '0.000000000000000001')->where('flows.data.0.movements.1.user.id', $this->user->id)
        ->missing('flows.data.0.metadata')->missing('flows.data.0.event_key')->missing('flows.data.0.movements.1.user.password_hash'));
    foreach ($before as $table => $json) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($json);
    }
    Http::assertNothingSent();
});

it('filters company customer currency and inclusive UTC8 dates with exact stable numbered pages', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 15:59:59', 'UTC'));
    globalFundsFixture($this->tenant, $this->user, 'ETH', '0.000000000000000001');
    $this->travelTo(CarbonImmutable::parse('2026-10-03 16:00:00', 'UTC'));
    for ($i = 0; $i < 26; $i++) {
        globalFundsFixture($this->tenant, $this->user, 'USDT', '0.01');
    }
    $other = User::where('tenant_id', '<>', $this->tenant->id)->firstOrFail();
    globalFundsFixture($other->tenant, $other, 'USDT', '100');
    $this->get($this->url)->assertOk()->assertInertia(fn ($p) => $p->where('flows.total', 28));
    $params = '?company='.$this->tenant->id.'&asset=USDT&event=TEST_FUNDING&from=2026-10-04&to=2026-10-04';
    $first = $this->get($this->url.$params)->assertOk()->assertInertia(fn ($p) => $p->where('flows.total', 26)->where('flows.last_page', 2)->has('flows.data', 25));
    $second = $this->get($this->url.$params.'&page=2')->assertOk()->assertInertia(fn ($p) => $p->has('flows.data', 1)->where('flows.current_page', 2));
    expect(array_intersect(array_column($first->viewData('page')['props']['flows']['data'], 'id'), array_column($second->viewData('page')['props']['flows']['data'], 'id')))->toBe([]);
    expect($first->viewData('page')['props']['flows']['next_page_url'])->toContain('company=', 'asset=USDT', 'event=TEST_FUNDING', 'from=2026-10-04', 'page=2');
    $this->get($this->url.'?search='.urlencode($other->email))->assertOk()->assertInertia(fn ($p) => $p->where('flows.total', 1)->where('flows.data.0.companyId', $other->tenant_id));
    $this->get($this->url.'?company='.$this->tenant->id.'&search='.urlencode($other->email))->assertOk()->assertInertia(fn ($p) => $p->where('flows.total', 0));
    $this->get($this->url.'?asset=ETH&from=2026-10-03&to=2026-10-03')->assertOk()->assertInertia(fn ($p) => $p->where('flows.total', 1));
    $this->getJson($this->url.'?from=2026-10-05&to=2026-10-04')->assertUnprocessable();
    Http::assertNothingSent();
});

it('requires active platform scope and each financial read permission', function (string $permission) {
    $company = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($company, 'platform_admin')->get($this->url)->assertForbidden();
    $this->owner->update(['status' => 'SUSPENDED']);
    $this->actingAs($this->owner, 'platform_admin')->get($this->url)->assertForbidden();
    $this->owner->update(['status' => 'ACTIVE']);
    $id = DB::table('permissions')->where('name', $permission)->value('id');
    DB::table('role_permissions')->where('permission_id', $id)->delete();
    $this->actingAs($this->owner->fresh(), 'platform_admin')->get($this->url)->assertForbidden();
})->with(['users.read', 'wallet.read', 'ledger.read']);
