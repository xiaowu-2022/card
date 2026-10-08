<?php

use App\Application\Card\AccountCardholderMaterials;
use App\Application\Card\ReadUnissuedCardholderMaterialsQuery;
use App\Application\Card\SubmitProviderCardholderAction;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Services\CardholderMaterials;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Http\Requests\ManageCardRequest;
use App\Http\Requests\SubmitCardSetupRequest;
use App\Infrastructure\Providers\Card\MockCardProvider;
use App\Support\Errors\DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    Http::preventStrayRequests();
    Storage::fake('private');
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->product = CardProduct::where('provider', 'PHOTONPAY')->firstOrFail();
    $bytes = kycTestImage()->getContent();
    $base = 'kyc/'.$this->tenant->id.'/'.$this->user->id.'/'.Str::uuid();
    Storage::disk('private')->put($base.'/front', $bytes);
    Storage::disk('private')->put($base.'/back', $bytes);
    $identity = ['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
        'document_type' => 'NATIONAL_ID', 'document_country' => 'CN',
        'identity_number_encrypted' => app(KycDataCipher::class)->encrypt('110101199003071234'),
        'identity_hash' => hash('sha256', Str::uuid())];
    $this->application = new KycApplication;
    $this->application->forceFill($identity + ['front_object_key' => $base.'/front', 'back_object_key' => $base.'/back',
        'ocr_status' => 'SUCCEEDED', 'review_status' => 'APPROVED', 'submitted_at' => now(), 'reviewed_at' => now(),
        'automatically_approved' => true])->save();
    (new IdentityRecord)->forceFill($identity + ['source_kyc_application_id' => $this->application->id, 'verified_at' => now()])->save();
    app()->instance(CardProviderInterface::class, new MockCardProvider);
    $this->input = ['request_id' => (string) Str::uuid(), 'card_product_id' => $this->product->id,
        'legal_first_name' => 'Alice', 'legal_last_name' => 'Chen', 'email' => 'alice@example.test',
        'mobile' => '13800138000', 'mobile_country_code' => 'CN'];
});

it('uses scoped approved originals and the fixed address without changing identity or moving money', function () {
    $source = $this->application->fresh()->getRawOriginal();
    $before = Storage::disk('private')->allFiles();
    $holder = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $this->input);
    expect($holder->status->value)->toBe('READY');
    $saved = json_decode(app(CardholderMaterials::class)->decrypt($holder->materials_encrypted), true);
    expect(array_keys($saved['fields']))->toBe(['legal_first_name', 'legal_last_name', 'date_of_birth', 'email',
        'nationality_country_code', 'residential_address', 'residential_city', 'residential_state',
        'residential_country_code', 'residential_postal_code', 'document_type', 'document_country',
        'cardholder_name_abbreviation', 'identity_number', 'mobile', 'mobile_prefix']);
    expect($saved['fields']['date_of_birth'])->toBe('1990-03-07')
        ->and($saved['fields']['nationality_country_code'])->toBe('CN')
        ->and($saved['fields']['residential_state'])->toBe('Fujian')
        ->and($saved['fields']['residential_city'])->toBe('Fuzhou')
        ->and($saved['fields']['residential_address'])->toBe('西湖花园3栋304室')
        ->and($saved['fields']['residential_postal_code'])->toBe('351000')
        ->and($saved['documents']['front'])->toBe($this->application->front_object_key)
        ->and(Storage::disk('private')->allFiles())->toBe($before)
        ->and($this->application->fresh()->getRawOriginal())->toBe($source);
    $same = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $this->input);
    expect($same->id)->toBe($holder->id)->and($same->submission_version)->toBe(1);
    $edit = app(ReadUnissuedCardholderMaterialsQuery::class)->execute($this->tenant->id, $this->user->id, $holder->id);
    expect(array_keys($edit))->toEqualCanonicalizing(['legal_first_name', 'legal_last_name', 'email', 'mobile', 'mobile_country_code']);
    $this->input['legal_first_name'] = 'Amy';
    $edited = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $this->input);
    expect($edited->id)->toBe($holder->id)->and($edited->submission_version)->toBe(2)
        ->and($edited->provider_cardholder_id)->toBe($holder->provider_cardholder_id);
    expect(CardIssueOrder::count())->toBe(0);
    Http::assertNothingSent();
});

it('validates core fields and rejects invalid birth dates and fixed-field overrides', function () {
    $rules = (new SubmitCardSetupRequest)->rules();
    expect(Validator::make($this->input, $rules)->passes())->toBeTrue();
    foreach (['date_of_birth', 'nationality_country_code', 'residential_address', 'front_url', 'front_upload_id', 'identity_number'] as $field) {
        expect(Validator::make($this->input + [$field => 'tampered'], $rules)->errors()->has($field))->toBeTrue();
    }
    $request = ManageCardRequest::create('/', 'POST', ['action' => 'holder_details']);
    $rules = $request->rules();
    expect(Validator::make(['action' => 'holder_details', 'legal_first_name' => 'Amy'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['action' => 'holder_details', 'residential_address' => 'other'], $rules)->errors()->has('residential_address'))->toBeTrue();
});

it('never resolves another tenant account identity', function () {
    $other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    expect(fn () => app(AccountCardholderMaterials::class)->resolve($other->id, $this->user->id))
        ->toThrow(ModelNotFoundException::class);
});

it('rejects missing originals before creating a provider holder', function () {
    Storage::disk('private')->delete($this->application->front_object_key);
    expect(fn () => app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $this->input))->toThrow(DomainException::class);
    expect(ProviderCardholder::count())->toBe(0);
});

it('completes every hidden field from account records despite empty stale client values', function () {
    $input = $this->input + array_fill_keys(['date_of_birth', 'nationality_country_code', 'residential_country_code',
        'residential_state', 'residential_city', 'residential_address', 'residential_postal_code',
        'document_country', 'document_type', 'identity_number'], '');
    $holder = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $input);
    $saved = json_decode(app(CardholderMaterials::class)->decrypt($holder->materials_encrypted), true)['fields'];
    expect($holder->status->value)->toBe('READY')
        ->and($saved['document_country'])->toBe('CN')->and($saved['document_type'])->toBe('id_card')
        ->and($saved['date_of_birth'])->toBe('1990-03-07')->and($saved['identity_number'])->toBeNull();
    Http::assertNothingSent();
});

it('accepts the actual four-field HTTP form and fills all remaining provider materials', function () {
    $this->actingAs($this->user, 'tenant_user')->postJson('https://a.localhost/api/v1/client/cards/cardholder', $this->input)
        ->assertSuccessful();
    $holder = ProviderCardholder::where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->firstOrFail();
    expect($holder->status->value)->toBe('READY')->and(CardIssueOrder::count())->toBe(0);
    Http::assertNothingSent();
});

it('identifies a missing visible name instead of reporting generic invalid materials', function () {
    $input = $this->input;
    $input['legal_first_name'] = '  ';
    expect(fn () => app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id, $input))
        ->toThrow(DomainException::class, 'Enter the cardholder first name.');
    expect(ProviderCardholder::count())->toBe(0);
});

function manualCardAccount($test, ?string $birth = null): KycApplication
{
    $test->user = User::create(['tenant_id' => $test->tenant->id, 'email' => 'manual-card@example.test',
        'password_hash' => \Illuminate\Support\Facades\Hash::make('synthetic-password'), 'status' => 'ACTIVE']);
    \App\Domain\User\Models\UserProfile::create(['tenant_id' => $test->tenant->id, 'user_id' => $test->user->id,
        'display_name' => 'Manual card test', 'date_of_birth' => $birth] + ($birth ? [
            'legal_first_name' => 'Synthetic', 'legal_last_name' => 'Holder', 'nationality_country_code' => 'CN',
            'residential_address' => 'Test address', 'residential_city' => 'Fuzhou', 'residential_state' => 'Fujian',
            'residential_country_code' => 'CN', 'residential_postal_code' => '351000',
        ] : []));
    $base = 'kyc/'.$test->tenant->id.'/'.$test->user->id.'/'.Str::uuid();
    foreach (['front', 'back'] as $side) Storage::disk('private')->put($base.'/'.$side, kycTestImage()->getContent());
    $application = (new KycApplication)->forceFill([
        'tenant_id' => $test->tenant->id, 'user_id' => $test->user->id, 'document_type' => 'NATIONAL_ID', 'document_country' => 'CN',
        'identity_number_encrypted' => null, 'identity_hash' => null, 'front_object_key' => $base.'/front', 'back_object_key' => $base.'/back',
        'review_status' => 'PENDING', 'ocr_status' => 'FAILED', 'processing_status' => 'FAILED', 'processing_error' => 'KYC_OCR_UNAVAILABLE', 'submitted_at' => now(),
    ]);
    $application->save();
    app(\App\Application\Kyc\ApproveKycAction::class)->execute($test->tenant->id, $application->id,
        \App\Domain\Admin\Models\AdminUser::where('email', 'owner@platform.local')->firstOrFail());
    return $application->fresh();
}

it('opens provider setup for manual approval with a real profile birthday and no OCR number', function () {
    $application = manualCardAccount($this, '1992-05-06');
    $before = $application->getAttributes();
    $holder = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id,
        $this->input + ['date_of_birth' => '1980-01-01']);
    $saved = json_decode(app(CardholderMaterials::class)->decrypt($holder->materials_encrypted), true);
    expect($holder->status->value)->toBe('READY')->and($saved['fields']['date_of_birth'])->toBe('1992-05-06')
        ->and($saved['fields']['identity_number'])->toBeNull()->and($saved['documents']['front'])->toBe($application->front_object_key)
        ->and($application->fresh()->getAttributes())->toBe($before)
        ->and(app(AccountCardholderMaterials::class)->requiresBirthDate($this->tenant->id, $this->user->id))->toBeFalse();
    expect(CardIssueOrder::count())->toBe(0);
    Http::assertNothingSent();
});

it('requests a missing birthday and accepts it for manual card setup without changing account identity', function () {
    $application = manualCardAccount($this);
    $before = $application->getAttributes();
    expect(app(AccountCardholderMaterials::class)->requiresBirthDate($this->tenant->id, $this->user->id))->toBeTrue();
    $url = 'https://a.localhost/api/v1/client/cards/cardholder';
    $this->actingAs($this->user, 'tenant_user')->postJson($url, $this->input)->assertUnprocessable()->assertJsonValidationErrors('date_of_birth');
    expect(ProviderCardholder::count())->toBe(0);
    foreach (['2030-01-01', '1992-02-30', 'not-a-date'] as $invalid) {
        $this->postJson($url, $this->input + ['date_of_birth' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('date_of_birth');
    }
    $input = $this->input + ['date_of_birth' => '1992-05-06'];
    $this->postJson($url, $input)->assertSuccessful();
    $holder = ProviderCardholder::where('user_id', $this->user->id)->sole();
    $saved = json_decode(app(CardholderMaterials::class)->decrypt($holder->materials_encrypted), true);
    expect($holder->status->value)->toBe('READY')->and($saved['fields']['date_of_birth'])->toBe('1992-05-06')
        ->and($saved['fields']['identity_number'])->toBeNull()->and($application->fresh()->getAttributes())->toBe($before)
        ->and($this->user->fresh()->profile->date_of_birth)->toBeNull();
    $this->postJson($url, $input)->assertSuccessful();
    expect(ProviderCardholder::where('user_id', $this->user->id)->count())->toBe(1);
    $edit = app(ReadUnissuedCardholderMaterialsQuery::class)->execute($this->tenant->id, $this->user->id, $holder->id);
    expect($edit['date_of_birth'])->toBe('1992-05-06');
    $input['legal_first_name'] = 'Amy';
    $this->postJson($url, $input)->assertSuccessful();
    expect($holder->fresh()->submission_version)->toBe(2)->and(CardIssueOrder::count())->toBe(0);
    Http::assertNothingSent();
});

it('never replaces an identity-derived birthday with a client date', function () {
    $holder = app(SubmitProviderCardholderAction::class)->execute($this->tenant->id, $this->user->id,
        $this->input + ['date_of_birth' => '1980-01-01']);
    $saved = json_decode(app(CardholderMaterials::class)->decrypt($holder->materials_encrypted), true);
    expect($saved['fields']['date_of_birth'])->toBe('1990-03-07');
});
