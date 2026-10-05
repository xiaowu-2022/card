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
    Http::preventStrayRequests();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->tenant->update(['timezone' => 'Asia/Kuala_Lumpur']);
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->url = "http://admin.localhost/platform/tenants/{$this->tenant->id}/users/{$this->user->id}/funds";
    $this->actingAs($this->owner, 'platform_admin');
});

function fundsFixture($test, string $asset = 'USDT', string $amount = '300'): void
{
    DB::transaction(fn () => app(WalletProvisioner::class)->provision($test->tenant, $test->user, $asset));
    $available = LedgerAccount::where('tenant_id', $test->tenant->id)->where('user_id', $test->user->id)->where('asset_code', $asset)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $clearing = LedgerAccount::where('tenant_id', $test->tenant->id)->where('asset_code', $asset)->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
    app(LedgerWriter::class)->post(new LedgerPostingPlan($test->tenant->id, $asset, 'funds-test:'.Str::uuid(), 'TEST_FUNDING', null, null, null, [
        new LedgerPostingInstruction($available->id, Money::of($amount, $asset)),
        new LedgerPostingInstruction($clearing->id, Money::of('-'.$amount, $asset)),
    ]));
}

it('returns an explicit empty history without creating a wallet or account', function () {
    $before = [DB::table('wallets')->count(), DB::table('ledger_accounts')->count(), DB::table('ledger_entries')->count()];
    $this->getJson($this->url)->assertOk()->assertJsonPath('rows.total', 0)->assertJsonPath('accounts', [])
        ->assertJsonPath('user.email', $this->user->email)->assertHeader('Cache-Control', 'no-store, private');
    expect([DB::table('wallets')->count(), DB::table('ledger_accounts')->count(), DB::table('ledger_entries')->count()])->toBe($before);
    Http::assertNothingSent();
});

it('shows both sides of owned internal movements once without exposing clearing or other users', function () {
    fundsFixture($this);
    $accounts = LedgerAccount::where('user_id', $this->user->id)->where('asset_code', 'USDT')->get()->keyBy(fn ($a) => $a->account_type->value);
    $entry = app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'USDT', 'funds-test:'.Str::uuid(), 'TEST_DEPOSIT', null, null, null, [
        new LedgerPostingInstruction($accounts['USER_AVAILABLE']->id, Money::of('-300', 'USDT')),
        new LedgerPostingInstruction($accounts['USER_SECURITY_DEPOSIT']->id, Money::of('300', 'USDT')),
    ]));
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $balances = DB::table('ledger_accounts')->orderBy('id')->get()->toJson();
    $counts = [DB::table('ledger_entries')->count(), DB::table('ledger_postings')->count()];
    $result = $this->getJson($this->url.'?event=TEST_DEPOSIT')->assertOk()->assertJsonPath('rows.total', 1)->assertJsonCount(2, 'rows.items.0.movements');
    expect(collect($result->json('rows.items.0.movements'))->pluck('amount', 'account')->all())->toBe([
        'USER_AVAILABLE' => '-300.00000000', 'USER_SECURITY_DEPOSIT' => '300.00000000',
    ]);
    $result->assertJsonMissingPath('rows.items.0.metadata')->assertJsonMissingPath('rows.items.0.event_key');
    expect(DB::table('ledger_accounts')->orderBy('id')->get()->toJson())->toBe($balances)
        ->and([DB::table('ledger_entries')->count(), DB::table('ledger_postings')->count()])->toBe($counts);
    Http::assertNothingSent();
});

it('filters local dates and assets with exact native precision and stable pagination', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 15:59:59', 'UTC'));
    fundsFixture($this, 'ETH', '0.000000000000000001');
    $this->travelTo(CarbonImmutable::parse('2026-10-03 16:00:00', 'UTC'));
    for ($i = 0; $i < 26; $i++) {
        fundsFixture($this, 'USDT', '0.00000001');
    }
    $this->getJson($this->url.'?asset=ETH&from=2026-10-03&to=2026-10-03')->assertOk()
        ->assertJsonPath('rows.total', 1)->assertJsonPath('rows.items.0.movements.0.amount', '0.000000000000000001');
    $this->getJson($this->url.'?asset=USDT&to=2026-10-03')->assertOk()->assertJsonPath('rows.total', 0);
    $first = $this->getJson($this->url.'?from=2026-10-04&to=2026-10-04')->assertOk()->assertJsonPath('rows.total', 26)->assertJsonCount(25, 'rows.items');
    $second = $this->getJson($this->url.'?from=2026-10-04&to=2026-10-04&page=2')->assertOk()->assertJsonCount(1, 'rows.items');
    expect(array_intersect(array_column($first->json('rows.items'), 'id'), array_column($second->json('rows.items'), 'id')))->toBe([]);
});

it('rejects cross company identities and isolates same company users', function () {
    fundsFixture($this);
    $other = User::where('tenant_id', '<>', $this->tenant->id)->firstOrFail();
    $this->getJson(str_replace($this->user->id, $other->id, $this->url))->assertNotFound();
    $child = $this->user->replicate(['account_id']);
    $child->forceFill(['email' => 'empty-funds@example.test'])->save();
    $this->getJson(str_replace($this->user->id, $child->id, $this->url))->assertOk()->assertJsonPath('rows.total', 0)->assertJsonPath('accounts', []);
});

it('requires active platform membership and every funds permission', function (string $permission) {
    $companyAdmin = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($companyAdmin, 'platform_admin')->getJson($this->url)->assertForbidden();
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', $permission)->value('id'))->delete();
    $this->actingAs($this->owner->fresh(), 'platform_admin')->getJson($this->url)->assertForbidden();
})->with(['users.read', 'wallet.read', 'ledger.read']);

it('validates date ranges page and filter syntax', function () {
    foreach (['page=0', 'from=2026-10-05&to=2026-10-04', 'from=not-a-date', 'asset=bad', 'event=invalid'] as $filter) {
        $this->getJson($this->url.'?'.$filter)->assertUnprocessable();
    }
});
