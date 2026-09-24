<?php

use App\Application\CardProviderDirectory\PhotonPayAccounts;
use App\Application\CardProviderDirectory\SaveCardProviderReferenceAction;
use App\Application\Promotion\PromotionMembershipAction;
use App\Domain\Card\Models\CardProviderEvent;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshIsolatedDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshIsolatedDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Architecture');

function kycTestImage(string $name = 'identity.png'): UploadedFile
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

    return UploadedFile::fake()->createWithContent($name, $png ?: 'invalid');
}

function registrationTestInvitation(string $slug = 'tenant-a'): string
{
    $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();

    return app(PromotionMembershipAction::class)->companyInvitation($tenant->id)->invitation_code;
}

/** Explicit pre-upgrade fixtures for immutable historical USD accounting tests. */
function legacyUsdAccountingFixtures(): void
{
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    DB::table('tenants')->update(['default_asset' => 'USD']);
    DB::table('tenant_business_settings')->update(['required_security_deposit_asset' => 'USD']);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
}

function notificationProfileUrl(string $channel, Tenant $tenant): string
{
    $profile = DB::table('tenant_notification_profiles')->where('tenant_id', $tenant->id)->value($channel.'_profile_id');

    return 'http://admin.localhost/platform/settings/'.$channel.($profile ? '/'.$profile : '');
}
function bindNotificationTestProfile(Tenant $tenant, string $channel, string $profileId): void
{
    DB::table('tenant_notification_profiles')->upsert([['tenant_id' => $tenant->id, $channel.'_profile_id' => $profileId, 'created_at' => now(), 'updated_at' => now()]], ['tenant_id'], [$channel.'_profile_id', 'updated_at']);
}

/** Synthetic, offline-only configured merchant for account/catalog tests. */
function photonAccountFixture($owner, string $bin = '522105', string $environment = 'sandbox'): array
{
    static $keys;
    if (! $keys) {
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rsa, $private);
        $keys = [$private, openssl_pkey_get_details($rsa)['key']];
    }
    $merchant = app(SaveCardProviderReferenceAction::class)->execute(null, ['name' => 'PhotonPay '.Str::uuid(), 'reference_balance' => '0', 'request_id' => (string) Str::uuid()], $owner);
    $payload = ['version' => $merchant->version, 'name' => $merchant->name, 'environment' => $environment, 'enabled' => true, 'app_id' => 'app-'.$merchant->id, 'app_secret' => 'synthetic-secret', 'private_key' => $keys[0], 'webhook_public_key' => $keys[1], 'account_id' => 'account-'.$merchant->id, 'member_id' => 'member-'.$merchant->id, 'matrix_account' => null];
    app(PhotonPayAccounts::class)->save($merchant->id, $payload, $owner);
    $merchant->refresh()->forceFill(['photonpay_check_status' => 'VERIFIED', 'bin_catalog' => [['bin' => $bin, 'scheme' => 'MasterCard', 'formFactors' => ['virtual_card', 'physical_card']]]])->save();

    return [$merchant, $payload, $keys];
}

/** Seed persisted legacy products without invoking the now PhotonPay-only creation endpoint. */
function legacyCardProductFixture(array $values): CardProduct
{
    $p = new CardProduct;
    $p->forceFill($values + ['provider' => 'UNCONFIGURED', 'provider_product_ref' => 'LEGACY-'.Str::uuid(),
        'card_currency' => 'USD', 'card_type' => 'REGULAR', 'minimum_initial_load' => '20', 'opening_fee' => '5', 'minimum_reload' => '20', 'status' => 'ACTIVE'])->save();

    return $p;
}

function storedCardNotificationFixture($resource, string $column, ?string $transaction = null): CardProviderEvent
{
    $event = new CardProviderEvent;
    $event->forceFill(['tenant_id' => $resource->tenant_id, 'user_id' => $resource->user_id, $column => $resource->id,
        'event_digest' => hash('sha256', $resource->id.($transaction ?? '')), 'category' => 'issuing', 'event_type' => 'auth', 'transaction_id' => $transaction, 'status' => 'PENDING'])->save();

    return $event;
}
