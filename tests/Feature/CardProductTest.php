<?php

use App\Application\CardProduct\ArchiveCardProductsAction;
use App\Application\CardProduct\CardProductCatalogQuery;
use App\Application\CardProduct\ConfigureTenantCardProductAction;
use App\Application\CardProduct\UpdateCardProductAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\CardProduct\Enums\CardProductStatus;
use App\Domain\CardProduct\Enums\TenantCardProductStatus;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->seed();
    $this->platformOwner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->tenantAOwner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->tenantBOwner = AdminUser::query()->where('email', 'owner@b.localhost')->firstOrFail();
    $this->tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $this->product = CardProduct::query()->where('provider', 'PHOTONPAY')->firstOrFail();
});

it('archives the explicit catalog without removing historical data or moving money', function (): void {
    Http::fake();
    $ids = CardProduct::query()->pluck('id')->all();
    $tables = ['user_cards', 'provider_cardholders', 'card_issue_orders', 'tenant_card_product_configs', 'ledger_entries', 'ledger_postings', 'ledger_accounts'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $action = app(ArchiveCardProductsAction::class);
    expect($action->execute($ids, $this->platformOwner))->toBe(count($ids))
        ->and($action->execute($ids, $this->platformOwner))->toBe(0)
        ->and(CardProduct::query()->count())->toBe(count($ids))
        ->and(CardProduct::query()->whereNull('archived_at')->count())->toBe(0)
        ->and(CardProduct::query()->where('status', 'ACTIVE')->count())->toBe(0);
    $catalog = app(CardProductCatalogQuery::class);
    expect($catalog->platform()['products'])->toBe([])
        ->and($catalog->tenant($this->tenantA->id)['products'])->toBe([])
        ->and($catalog->user($this->tenantA->id, null)['products'])->toBe([]);
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before[$table]);
    }
    expect(fn () => app(UpdateCardProductAction::class)->execute($this->product->id, [
        'name' => 'Cannot restore', 'opening_fee' => '5', 'minimum_initial_load' => '20',
        'minimum_reload' => '20', 'status' => 'ACTIVE', 'provider_product_ref' => $this->product->provider_product_ref,
    ], $this->platformOwner))->toThrow(DomainException::class, 'This card product has been archived.');
    Http::assertNothingSent();
});

it('rejects company administrators archiving platform products', function (): void {
    expect(fn () => app(ArchiveCardProductsAction::class)->execute([$this->product->id], $this->tenantAOwner))
        ->toThrow(HttpException::class);
    expect($this->product->fresh()->archived_at)->toBeNull();
});

it('lets platform create and edit account-bound products with fixed USD regular shape', function (): void {
    [$account] = photonAccountFixture($this->platformOwner, 'DEMO-MILLE-PLUS-0001');
    $account->forceFill(['bin_catalog' => [['bin' => 'DEMO-MILLE-PLUS-0001'], ['bin' => 'DEMO-MILLE-PLUS-0002']]])->save();
    $beforeEntries = DB::table('ledger_entries')->count();
    $this->actingAs($this->platformOwner, 'platform_admin')->post('http://admin.localhost/platform/card-products', [
        'name' => 'Mille Card Plus', 'card_provider_reference_id' => $account->id,
        'provider_product_ref' => 'DEMO-MILLE-PLUS-0001',
        'minimum_initial_load' => '20.00000000',
        'opening_fee' => '5.00000000', 'minimum_reload' => '25.50000000',
        'status' => 'ACTIVE',
        'provider' => 'OTHER',
        'card_currency' => 'EUR',
        'card_type' => 'SHARED',
    ])->assertRedirect();

    $created = CardProduct::query()->where('provider_product_ref', 'DEMO-MILLE-PLUS-0001')->firstOrFail();
    expect($created->provider)->toBe('UNCONFIGURED')
        ->and($created->card_currency)->toBe('USD')
        ->and($created->card_type)->toBe('REGULAR')
        ->and($created->minimum_initial_load)->toBe('20.00000000')
        ->and($created->minimum_reload)->toBe('25.50000000')
        ->and(DB::table('ledger_entries')->count())->toBe($beforeEntries);

    $this->actingAs($this->platformOwner, 'platform_admin')->put("http://admin.localhost/platform/card-products/{$created->id}", [
        'name' => 'Mille Card Plus Updated',
        'provider_product_ref' => 'DEMO-MILLE-PLUS-0002',
        'minimum_initial_load' => '30',
        'opening_fee' => '5.00000000', 'minimum_reload' => '20',
        'status' => 'INACTIVE',
    ])->assertRedirect();
    expect($created->fresh()->name)->toBe('Mille Card Plus Updated')
        ->and($created->fresh()->provider_product_ref)->toBe('DEMO-MILLE-PLUS-0002')
        ->and($created->fresh()->status)->toBe(CardProductStatus::Inactive);
});

it('rejects missing accounts and optional or arbitrary BIN inputs', function (): void {
    Http::preventStrayRequests();
    $base = ['name' => 'Unconfigured', 'minimum_initial_load' => '20', 'opening_fee' => '5', 'minimum_reload' => '20', 'status' => 'ACTIVE'];
    $this->actingAs($this->platformOwner, 'platform_admin');
    $this->post('http://admin.localhost/platform/card-products', $base)->assertSessionHasErrors(['card_provider_reference_id', 'provider_product_ref']);
    [$account] = photonAccountFixture($this->platformOwner);
    $this->post('http://admin.localhost/platform/card-products', $base + ['card_provider_reference_id' => $account->id, 'provider_product_ref' => '123456'])->assertSessionHasErrors('provider_product_ref');
    expect(CardProduct::where('name', 'Unconfigured')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects imprecise non-string or below-provider-minimum amounts', function (array $payload, string $field): void {
    $base = [
        'name' => 'Rejected Product', 'provider_product_ref' => 'DEMO-REJECTED-0001',
        'minimum_initial_load' => '20', 'opening_fee' => '5.00000000', 'minimum_reload' => '20', 'status' => 'ACTIVE',
    ];
    $this->actingAs($this->platformOwner, 'platform_admin')
        ->post('http://admin.localhost/platform/card-products', [...$base, ...$payload])
        ->assertSessionHasErrors($field);
})->with([
    'float' => [['minimum_initial_load' => 20.0], 'minimum_initial_load'],
    'overprecision' => [['opening_fee' => '5.00000000', 'minimum_reload' => '20.000000001'], 'minimum_reload'],
    'below minimum' => [['minimum_initial_load' => '19.99999999'], 'minimum_initial_load'],
]);

it('lets SaaS configure company presentation without changing platform pricing or money', function (): void {
    $config = TenantCardProductConfig::query()->where('tenant_id', $this->tenantA->id)
        ->where('card_product_id', $this->product->id)->firstOrFail();
    $ledgerCounts = [DB::table('ledger_entries')->count(), DB::table('ledger_postings')->count()];
    $identity = $this->product->only(['provider', 'provider_product_ref', 'card_currency', 'card_type', 'minimum_initial_load', 'minimum_reload']);

    $this->actingAs($this->platformOwner, 'platform_admin')->put("http://admin.localhost/platform/tenants/{$this->tenantA->id}/configuration/card-products/{$this->product->id}", [
        'display_name' => 'Tenant A Mille',
        'max_cards_per_user' => 3,
        'status' => 'ACTIVE',
        'sort_order' => 4,
        'tenant_id' => $this->tenantB->id,
        'provider_product_ref' => 'ATTACKER-BIN',
        'minimum_initial_load' => '1',
        'minimum_reload' => '1',
    ])->assertRedirect();

    expect($config->fresh()->display_name)->toBe('Tenant A Mille')
        ->and($config->fresh()->opening_fee)->toBe('5.00000000')
        ->and($config->fresh()->max_cards_per_user)->toBe(3)
        ->and($config->fresh()->sort_order)->toBe(4)
        ->and($this->product->fresh()->only(array_keys($identity)))->toBe($identity)
        ->and([DB::table('ledger_entries')->count(), DB::table('ledger_postings')->count()])->toBe($ledgerCounts);
});

it('keeps tenant product configuration isolated across hosts and queries', function (): void {
    $configB = TenantCardProductConfig::query()->where('tenant_id', $this->tenantB->id)
        ->where('card_product_id', $this->product->id)->firstOrFail();
    $beforeB = $configB->only(['display_name', 'opening_fee', 'status', 'sort_order']);

    $this->actingAs($this->platformOwner, 'platform_admin')->put("http://admin.localhost/platform/tenants/{$this->tenantA->id}/configuration/card-products/{$this->product->id}", [
        'display_name' => 'Only Tenant A', 'max_cards_per_user' => 2,
        'status' => 'ACTIVE', 'sort_order' => 1, 'tenant_id' => $this->tenantB->id,
    ])->assertRedirect();
    expect($configB->fresh()->only(array_keys($beforeB)))->toBe($beforeB);

    $this->actingAs($this->tenantAOwner, 'tenant_admin')->get('http://b.localhost/admin/card-products')->assertForbidden();
    $tenantAData = app(CardProductCatalogQuery::class)->tenant($this->tenantA->id);
    $tenantBData = app(CardProductCatalogQuery::class)->tenant($this->tenantB->id);
    expect($tenantAData['products'][0]['config']['openingFee'])->toBe('5.00000000')
        ->and($tenantBData['products'][0]['config']['openingFee'])->toBe('5.00000000');
});

it('shows only active platform and tenant offerings without exposing provider identity to users', function (): void {
    $catalog = app(CardProductCatalogQuery::class);
    $visible = $catalog->user($this->tenantA->id, null);
    expect($visible['products'])->toHaveCount(1)
        ->and($visible['products'][0]['name'])->toBe('Mille Card')
        ->and($visible['products'][0]['openingFee'])->toBe('5.00000000')
        ->and($visible['products'][0]['minimumInitialLoad'])->toBe('20.00000000')
        ->and($visible['products'][0]['minimumReload'])->toBe('20.00000000')
        ->and($visible['products'][0]['minimumRequiredBalance'])->toBe('25.00000000')
        ->and($visible['products'][0])->not->toHaveKeys(['provider', 'providerProductRef']);

    $this->product->forceFill(['status' => CardProductStatus::Inactive])->save();
    expect($catalog->user($this->tenantA->id, null)['products'])->toBe([]);
    $this->product->forceFill(['status' => CardProductStatus::Active])->save();
    TenantCardProductConfig::query()->where('tenant_id', $this->tenantA->id)
        ->where('card_product_id', $this->product->id)->update(['status' => TenantCardProductStatus::Inactive->value]);
    expect($catalog->user($this->tenantA->id, null)['products'])->toBe([]);
});

it('renders the three product surfaces with admin-only provider identity', function (): void {
    $this->get('http://a.localhost/demo/cards')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('user/Cards')->has('products', 1)->missing('products.0.providerProductRef'));
    $this->actingAs($this->platformOwner, 'platform_admin')->get('http://admin.localhost/platform/card-products')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('platform/CardProducts')->where('products.0.minimumReload', '20.00000000'));
    $this->actingAs($this->tenantAOwner, 'tenant_admin')->get('http://a.localhost/admin/card-products')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('tenant-admin/CardProducts')->where('products.0.providerProductRef', 'DEMO-MILLE-REGULAR-54493747'));
});

it('enforces the small schema and rejects unsupported provider product shapes', function (): void {
    expect(Schema::hasTable('card_products'))->toBeTrue()
        ->and(Schema::hasTable('tenant_card_product_configs'))->toBeTrue()
        ->and(Schema::hasColumns('tenant_card_product_configs', ['load_fee_fixed', 'load_fee_rate', 'card_load_percentage_fee', 'card_load_fixed_fee']))->toBeFalse()
        ->and(Schema::hasTable('user_cards'))->toBeTrue();

    expect(fn () => DB::table('card_products')->insert([
        'id' => (string) Str::uuid(), 'provider' => 'OTHER', 'provider_product_ref' => 'INVALID-0001',
        'name' => 'Invalid', 'card_currency' => 'EUR', 'card_type' => 'SHARED',
        'minimum_initial_load' => '1', 'opening_fee' => '5.00000000', 'minimum_reload' => '1', 'status' => 'ACTIVE',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects company fee overrides and exposes only the platform price', function (): void {
    $data = ['display_name' => 'Name', 'opening_fee' => '1', 'max_cards_per_user' => 3, 'status' => 'ACTIVE', 'sort_order' => 1];
    $this->actingAs($this->tenantAOwner, 'tenant_admin')->putJson("http://a.localhost/admin/card-products/{$this->product->id}", $data)->assertForbidden();
    expect(fn () => app(ConfigureTenantCardProductAction::class)->execute($this->tenantA->id, $this->product->id, $data, $this->platformOwner))->toThrow(ValidationException::class);
    $this->product->forceFill(['opening_fee' => '7.12345678'])->save();
    expect(app(CardProductCatalogQuery::class)->user($this->tenantA->id, null)['products'][0]['openingFee'])->toBe('7.12345678');
    $this->product->forceFill(['opening_fee' => null])->save();
    expect(app(CardProductCatalogQuery::class)->user($this->tenantA->id, null)['products'])->toBe([]);
});

it('saves and clears the product default balance limit without moving funds', function (): void {
    $entries = DB::table('ledger_entries')->count();
    $data = ['name' => $this->product->name, 'provider_product_ref' => $this->product->provider_product_ref,
        'opening_fee' => '5', 'minimum_initial_load' => '20', 'minimum_reload' => '20', 'status' => 'ACTIVE', 'balance_limit' => '500'];
    $url = 'http://admin.localhost/platform/card-products/'.$this->product->id;
    $this->actingAs($this->platformOwner, 'platform_admin')->put($url, $data)->assertRedirect()->assertSessionHasNoErrors();
    expect($this->product->fresh()->balance_limit)->toBe('500.00000000');
    foreach (['-1', '1.001', 'abc'] as $invalid) {
        $this->put($url, [...$data, 'balance_limit' => $invalid])->assertSessionHasErrors('balance_limit');
    }
    expect($this->product->fresh()->balance_limit)->toBe('500.00000000');
    $this->put($url, [...$data, 'balance_limit' => null])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->product->fresh()->balance_limit)->toBeNull()->and(DB::table('ledger_entries')->count())->toBe($entries);
});
