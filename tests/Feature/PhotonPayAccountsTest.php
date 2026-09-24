<?php

use App\Application\Card\CardProductProviderRouter;
use App\Application\Card\ReceiveCardNotificationAction;
use App\Application\CardProduct\CreateCardProductAction;
use App\Application\CardProduct\UpdateCardProductAction;
use App\Application\CardProviderDirectory\PhotonPayAccounts;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Card\Models\CardProviderEvent;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Services\CardholderMaterials;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    [$this->account,$this->input,$this->keys] = photonAccountFixture($this->owner);
    $this->input['version'] = $this->account->version;
    $this->productInput = ['name' => 'Account product', 'card_provider_reference_id' => $this->account->id, 'provider_product_ref' => '522105', 'minimum_initial_load' => '20', 'opening_fee' => '5', 'minimum_reload' => '20', 'status' => 'DRAFT'];
});

it('retains blank credentials and invalidates checks and catalogs on rotation without leaking secrets', function (): void {
    $old = $this->account->photonpay_issuing_encrypted;
    $blank = [...$this->input, 'private_key' => '', 'webhook_public_key' => '', 'app_secret' => ''];
    app(PhotonPayAccounts::class)->save($this->account->id, $blank, $this->owner);
    expect($this->account->fresh()->photonpay_issuing_encrypted)->toBe($old);
    app(PhotonPayAccounts::class)->save($this->account->id, [...$blank, 'version' => $this->account->fresh()->version, 'app_secret' => 'rotated-secret'], $this->owner);
    $row = $this->account->fresh();
    expect($row->photonpay_check_status)->toBe('UNCHECKED')->and($row->bin_catalog)->toBeNull();
    expect(json_decode(Crypt::decryptString($row->photonpay_issuing_encrypted), true)['private_key'])->toBe($this->keys[0]);
    $public = json_encode(app(PhotonPayAccounts::class)->publicConfiguration($row));
    expect($public)->not->toContain('rotated-secret')->not->toContain('PRIVATE KEY');
    expect(json_encode($row))->not->toContain('rotated-secret')->not->toContain('photonpay_issuing_encrypted');
    expect(fn () => app(PhotonPayAccounts::class)->save($row->id, $this->input, $this->owner))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('checks token account ownership and saved BINs without any issuing calls', function (): void {
    Http::fake([
        '*/oauth2/token/accessToken' => Http::response(['code' => '0000', 'data' => ['token' => 'offline-token', 'expiresIn' => (string) ((time() + 1200) * 1000)]]),
        '*/wallet/openApi/v4/account/single*' => Http::response(['code' => '0000', 'data' => ['accountNo' => $this->input['account_id'], 'memberId' => $this->input['member_id'], 'currency' => 'USD', 'accountType' => 'FT10001']]),
        '*/getCardBin*' => Http::response(['code' => '0000', 'data' => [['cardBin' => '534934', 'cardScheme' => 'MasterCard', 'cardCurrency' => 'USD', 'cardType' => 'recharge', 'cardFormFactor' => 'physical_card']]]),
    ]);
    app(PhotonPayAccounts::class)->check($this->account->id, $this->owner);
    expect($this->account->fresh()->photonpay_check_status)->toBe('VERIFIED')->and($this->account->fresh()->bin_catalog[0]['bin'])->toBe('534934');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openCard'));
});

it('fails account ownership checks closed', function (): void {
    Http::fake(['*/oauth2/token/accessToken' => Http::response(['code' => '0000', 'data' => ['token' => 'offline']]), '*' => Http::response(['code' => '0000', 'data' => ['accountNo' => 'foreign', 'memberId' => 'foreign', 'currency' => 'USD', 'accountType' => 'FT10001']])]);
    expect(fn () => app(PhotonPayAccounts::class)->check($this->account->id, $this->owner))->toThrow(ValidationException::class);
    expect($this->account->fresh()->photonpay_check_status)->toBe('FAILED')->and($this->account->fresh()->bin_catalog)->toBeNull();
});

it('requires configured available accounts and saved exact BIN membership', function (): void {
    foreach ([[...$this->productInput, 'card_provider_reference_id' => null], [...$this->productInput, 'provider_product_ref' => '534934']] as $input) {
        expect(fn () => app(CreateCardProductAction::class)->execute($input, $this->owner))->toThrow(ValidationException::class);
    }
    $this->account->forceFill(['photonpay_enabled' => false])->save();
    expect(fn () => app(CreateCardProductAction::class)->execute($this->productInput, $this->owner))->toThrow(ValidationException::class);
});

it('prevents cross account and cross environment BIN reuse and releases changed BIN claims', function (): void {
    $p = app(CreateCardProductAction::class)->execute($this->productInput, $this->owner);
    [$other] = photonAccountFixture($this->owner, '522105', 'production');
    expect(fn () => app(CreateCardProductAction::class)->execute([...$this->productInput, 'card_provider_reference_id' => $other->id], $this->owner))->toThrow(ValidationException::class);
    $this->account->forceFill(['bin_catalog' => [['bin' => '534934', 'scheme' => 'MasterCard', 'formFactors' => ['physical_card']], ['bin' => '53493435', 'scheme' => 'MasterCard', 'formFactors' => ['virtual_card']]]])->save();
    app(UpdateCardProductAction::class)->execute($p->id, [...$this->productInput, 'provider_product_ref' => '534934'], $this->owner);
    app(CreateCardProductAction::class)->execute([...$this->productInput, 'provider_product_ref' => '53493435'], $this->owner);
    expect(DB::table('card_bin_claims')->whereIn('bin', ['522105', '534934', '53493435'])->count())->toBe(2);
    Http::assertNothingSent();
});

it('protects credentials from validation-session flashing and enforces platform permission', function (): void {
    $this->actingAs($this->owner, 'platform_admin')->put('http://admin.localhost/platform/card-providers/'.$this->account->id, [...$this->input, 'name' => '', 'private_key' => 'secret-private', 'app_secret' => 'secret-app'])
        ->assertSessionHasErrors('name')->assertSessionMissing('_old_input.private_key')->assertSessionMissing('_old_input.app_secret');
    $this->app['auth']->forgetGuards();
    $this->post('http://admin.localhost/platform/card-providers/'.$this->account->id.'/check')->assertRedirect();
});

function accountHolderFixture($test, $product): ProviderCardholder
{
    $user = User::firstOrFail();
    $holder = new ProviderCardholder;
    $holder->forceFill(['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'provider' => 'PHOTONPAY',
        'provider_cardholder_id' => 'CH-'.Str::uuid(), 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
        'card_product_id' => $product->id, 'submission_version' => 1, 'materials_encrypted' => app(CardholderMaterials::class)->encrypt('{}'),
        'status' => 'READY', 'provider_status' => 'normal', 'provider_review_status' => 'pass', 'submitted_at' => now(), 'synced_at' => now()])->save();

    return $holder;
}

it('locks used account identity but permits credential rotation and preserves pending reads when paused', function (): void {
    $product = app(CreateCardProductAction::class)->execute($this->productInput, $this->owner);
    accountHolderFixture($this, $product);
    expect(fn () => app(PhotonPayAccounts::class)->save($this->account->id, [...$this->input, 'account_id' => 'different'], $this->owner))->toThrow(ValidationException::class);
    app(PhotonPayAccounts::class)->save($this->account->id, [...$this->input, 'app_secret' => 'rotated', 'enabled' => false], $this->owner);
    $this->account->refresh()->forceFill(['photonpay_check_status' => 'VERIFIED'])->save();
    $router = app(CardProductProviderRouter::class);
    expect($router->forProduct($product->fresh())->available())->toBeTrue();
    expect(fn () => $router->assertNewBusiness($product->fresh()))->toThrow(DomainException::class);
    expect(fn () => DB::transaction(fn () => $this->account->fresh()->forceFill(['photonpay_identity' => ['base_url' => 'elsewhere']])->save()))->toThrow(QueryException::class);
});

it('uses only the selected account public key and deduplicates verified callbacks within that account', function (): void {
    $product = app(CreateCardProductAction::class)->execute($this->productInput, $this->owner);
    $holder = accountHolderFixture($this, $product);
    $holder->forceFill(['synced_at' => now()->subMinute()])->save();
    $body = json_encode(['cardholderId' => $holder->provider_cardholder_id]);
    openssl_sign($body, $signature, $this->keys[0], OPENSSL_ALGO_MD5);
    Http::fake([
        '*/oauth2/token/accessToken' => Http::response(['code' => '0000', 'data' => ['token' => 'offline', 'expiresIn' => (string) ((time() + 1200) * 1000)]]),
        '*/pagingVccCardholder*' => Http::response(['code' => '0000', 'data' => [['cardholderId' => $holder->provider_cardholder_id, 'memberId' => $this->input['member_id'], 'status' => 'normal', 'cardholderReviewStatus' => 'pass']]]),
    ]);
    $receive = app(ReceiveCardNotificationAction::class);
    $receive->execute($body, base64_encode($signature), 'issuing_card', 'cardholder', $this->account->id);
    $receive->execute($body, base64_encode($signature), 'issuing_card', 'cardholder');
    expect(CardProviderEvent::count())->toBe(1)
        ->and(CardProviderEvent::first()->card_provider_reference_id)->toBe($this->account->id);
    expect(CardProviderEvent::first()->status)->toBe('PROCESSED');
    Http::assertSentCount(2);
    [$other] = photonAccountFixture($this->owner, '555555', 'production');
    // Even equal verification keys cannot authorize cross-account resource references.
    expect(fn () => $receive->execute($body, base64_encode($signature), 'issuing_card', 'cardholder', $other->id))->toThrow(DomainException::class);
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $other->forceFill(['photonpay_webhook_key_encrypted' => Crypt::encryptString(openssl_pkey_get_details($key)['key'])])->save();
    expect(fn () => $receive->execute($body, base64_encode($signature), 'issuing_card', 'cardholder', $other->id))->toThrow(DomainException::class);
    expect(fn () => $receive->execute('{}', base64_encode($signature), 'issuing_card', 'cardholder'))->toThrow(DomainException::class);
    expect(CardProviderEvent::count())->toBe(1);
});

it('routes two accounts with independent token caches and request signing keys', function (): void {
    $first = app(CreateCardProductAction::class)->execute($this->productInput, $this->owner);
    [$secondAccount,$input] = photonAccountFixture($this->owner, '534934', 'production');
    $rsa = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($rsa, $private);
    app(PhotonPayAccounts::class)->save($secondAccount->id, [...$input, 'version' => $secondAccount->version, 'private_key' => $private], $this->owner);
    $secondAccount->refresh()->forceFill(['photonpay_check_status' => 'VERIFIED', 'bin_catalog' => [['bin' => '534934', 'formFactors' => ['physical_card']]]])->save();
    $second = app(CreateCardProductAction::class)->execute([...$this->productInput, 'card_provider_reference_id' => $secondAccount->id, 'provider_product_ref' => '534934'], $this->owner);
    $tokens = [];
    $signed = [];
    Http::fake(function ($request) use (&$tokens, &$signed, $rsa) {
        $production = str_contains($request->url(), 'x-api.photonpay.com');
        if (str_contains($request->url(), 'accessToken')) {
            $tokens[] = $request->header('Authorization')[0];

            return Http::response(['code' => '0000', 'data' => ['token' => $production ? 'prod-token' : 'sandbox-token', 'expiresIn' => (string) ((time() + 1200) * 1000)]]);
        }
        $key = $production ? openssl_pkey_get_details($rsa)['key'] : $this->keys[1];
        $signed[] = openssl_verify($request->body(), base64_decode($request->header('X-PD-SIGN')[0]), $key, OPENSSL_ALGO_MD5) === 1;
        expect($request->header('X-PD-TOKEN')[0])->toBe($production ? 'prod-token' : 'sandbox-token');

        return Http::response(['code' => '0000', 'data' => ['recipientId' => $production ? 'RECIPIENT-PROD' : 'RECIPIENT-SANDBOX']]);
    });
    $router = app(CardProductProviderRouter::class);
    expect($router->forProduct($first, 'physical_card')->addRecipient(['recipientFirstName' => 'Synthetic']))->toBe('RECIPIENT-SANDBOX');
    expect($router->forProduct($second, 'physical_card')->addRecipient(['recipientFirstName' => 'Synthetic']))->toBe('RECIPIENT-PROD');
    expect(count(array_unique($tokens)))->toBe(2)->and($signed)->toBe([true, true]);
});

it('creates account configuration atomically through the backend without flashing credentials', function (): void {
    $request = (string) Str::uuid();
    $base = [...$this->input, 'request_id' => $request, 'version' => 1, 'name' => 'New backend account', 'account_id' => 'new-account', 'member_id' => 'new-member'];
    $this->actingAs($this->owner, 'platform_admin')->post('http://admin.localhost/platform/card-providers', [...$base, 'private_key' => 'invalid-private'])
        ->assertSessionHasErrors('private_key')->assertSessionMissing('_old_input.private_key');
    expect(CardProviderReference::find($request))->toBeNull();
    $this->post('http://admin.localhost/platform/card-providers', $base)->assertSessionHasNoErrors();
    $row = CardProviderReference::findOrFail($request);
    expect($row->photonpay_check_status)->toBe('UNCHECKED')->and($row->photonpay_enabled)->toBeTrue();
    Http::assertNothingSent();
});

it('accepts Base64 keys and stores normalized PEM with specific validation errors', function (): void {
    $strip = fn ($pem) => preg_replace('/-----[^-]+-----|\s+/', '', $pem);
    app(PhotonPayAccounts::class)->save($this->account->id, [...$this->input, 'private_key' => $strip($this->keys[0]), 'webhook_public_key' => $strip($this->keys[1])], $this->owner);
    $row = $this->account->fresh();
    $saved = json_decode(Crypt::decryptString($row->photonpay_issuing_encrypted), true);
    expect(openssl_pkey_get_private($saved['private_key']))->not->toBeFalse();
    expect(openssl_pkey_get_public(Crypt::decryptString($row->photonpay_webhook_key_encrypted)))->not->toBeFalse();
    try {
        app(PhotonPayAccounts::class)->save($row->id, [...$this->input, 'version' => $row->version, 'webhook_public_key' => 'invalid-key'], $this->owner);
        test()->fail('Invalid public key accepted');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['webhook_public_key']);
    }
    Http::assertNothingSent();
});
