<?php

use App\Application\CardProduct\CardProductCatalogQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\CardProduct\Enums\CardProductStatus;
use App\Domain\CardProduct\Enums\TenantCardProductStatus;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed();
    $this->platformOwner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->tenantAOwner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->tenantBOwner = AdminUser::query()->where('email', 'owner@b.localhost')->firstOrFail();
    $this->tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $this->product = CardProduct::query()->where('provider', 'PHOTONPAY')->firstOrFail();
});

it('lets platform create and edit only the locked regular PhotonPay product shape', function (): void {
    $beforeEntries = DB::table('ledger_entries')->count();
    $this->actingAs($this->platformOwner, 'platform_admin')->post('http://admin.localhost/platform/card-products', [
        'name' => 'Mille Card Plus',
        'provider_product_ref' => 'DEMO-MILLE-PLUS-0001',
        'minimum_initial_load' => '20.00000000',
        'minimum_reload' => '25.50000000',
        'status' => 'ACTIVE',
        'provider' => 'OTHER',
        'card_currency' => 'EUR',
        'card_type' => 'SHARED',
    ])->assertRedirect();

    $created = CardProduct::query()->where('provider_product_ref', 'DEMO-MILLE-PLUS-0001')->firstOrFail();
    expect($created->provider)->toBe('PHOTONPAY')
        ->and($created->card_currency)->toBe('USD')
        ->and($created->card_type)->toBe('REGULAR')
        ->and($created->minimum_initial_load)->toBe('20.00000000')
        ->and($created->minimum_reload)->toBe('25.50000000')
        ->and(DB::table('ledger_entries')->count())->toBe($beforeEntries);

    $this->actingAs($this->platformOwner, 'platform_admin')->put("http://admin.localhost/platform/card-products/{$created->id}", [
        'name' => 'Mille Card Plus Updated',
        'provider_product_ref' => 'DEMO-MILLE-PLUS-0002',
        'minimum_initial_load' => '30',
        'minimum_reload' => '20',
        'status' => 'INACTIVE',
    ])->assertRedirect();
    expect($created->fresh()->name)->toBe('Mille Card Plus Updated')
        ->and($created->fresh()->provider_product_ref)->toBe('DEMO-MILLE-PLUS-0002')
        ->and($created->fresh()->status)->toBe(CardProductStatus::Inactive);
});

it('rejects imprecise non-string or below-provider-minimum amounts', function (array $payload, string $field): void {
    $base = [
        'name' => 'Rejected Product', 'provider_product_ref' => 'DEMO-REJECTED-0001',
        'minimum_initial_load' => '20', 'minimum_reload' => '20', 'status' => 'ACTIVE',
    ];
    $this->actingAs($this->platformOwner, 'platform_admin')
        ->post('http://admin.localhost/platform/card-products', [...$base, ...$payload])
        ->assertSessionHasErrors($field);
})->with([
    'float' => [['minimum_initial_load' => 20.0], 'minimum_initial_load'],
    'overprecision' => [['minimum_reload' => '20.000000001'], 'minimum_reload'],
    'below minimum' => [['minimum_initial_load' => '19.99999999'], 'minimum_initial_load'],
]);

it('lets a tenant configure exact future pricing without changing platform product facts or money', function (): void {
    $config = TenantCardProductConfig::query()->where('tenant_id', $this->tenantA->id)
        ->where('card_product_id', $this->product->id)->firstOrFail();
    $ledgerCounts = [DB::table('ledger_entries')->count(), DB::table('ledger_postings')->count()];
    $identity = $this->product->only(['provider', 'provider_product_ref', 'card_currency', 'card_type', 'minimum_initial_load', 'minimum_reload']);

    $this->actingAs($this->tenantAOwner, 'tenant_admin')->put("http://a.localhost/admin/card-products/{$this->product->id}", [
        'display_name' => 'Tenant A Mille',
        'opening_fee' => '5.12345678',
        'max_cards_per_user' => 3,
        'status' => 'ACTIVE',
        'sort_order' => 4,
        'tenant_id' => $this->tenantB->id,
        'provider_product_ref' => 'ATTACKER-BIN',
        'minimum_initial_load' => '1',
        'minimum_reload' => '1',
    ])->assertRedirect();

    expect($config->fresh()->display_name)->toBe('Tenant A Mille')
        ->and($config->fresh()->opening_fee)->toBe('5.12345678')
        ->and($config->fresh()->max_cards_per_user)->toBe(3)
        ->and($config->fresh()->sort_order)->toBe(4)
        ->and($this->product->fresh()->only(array_keys($identity)))->toBe($identity)
        ->and([DB::table('ledger_entries')->count(), DB::table('ledger_postings')->count()])->toBe($ledgerCounts);
});

it('keeps tenant product configuration isolated across hosts and queries', function (): void {
    $configB = TenantCardProductConfig::query()->where('tenant_id', $this->tenantB->id)
        ->where('card_product_id', $this->product->id)->firstOrFail();
    $beforeB = $configB->only(['display_name', 'opening_fee', 'status', 'sort_order']);

    $this->actingAs($this->tenantAOwner, 'tenant_admin')->put("http://a.localhost/admin/card-products/{$this->product->id}", [
        'display_name' => 'Only Tenant A', 'opening_fee' => '6', 'max_cards_per_user' => 2,
        'status' => 'ACTIVE', 'sort_order' => 1, 'tenant_id' => $this->tenantB->id,
    ])->assertRedirect();
    expect($configB->fresh()->only(array_keys($beforeB)))->toBe($beforeB);

    $this->actingAs($this->tenantAOwner, 'tenant_admin')->get('http://b.localhost/admin/card-products')->assertForbidden();
    $tenantAData = app(CardProductCatalogQuery::class)->tenant($this->tenantA->id);
    $tenantBData = app(CardProductCatalogQuery::class)->tenant($this->tenantB->id);
    expect($tenantAData['products'][0]['config']['openingFee'])->toBe('6.00000000')
        ->and($tenantBData['products'][0]['config']['openingFee'])->toBe('8.00000000');
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
        'minimum_initial_load' => '1', 'minimum_reload' => '1', 'status' => 'ACTIVE',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
