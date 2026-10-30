<?php

use App\Application\Kyc\ProcessPendingKyc;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Media\ImageStorage;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Http\Requests\SubmitKycApplicationRequest;
use App\Infrastructure\Providers\Kyc\UnavailableKycOcrProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->seed();
    Storage::fake('private');
    $this->freezeTime();
    config(['kyc.ocr_driver' => 'image_url', 'media.storage' => 'server']);
    Http::preventStrayRequests();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    PlatformKycSetting::current()->update(['review_mode' => 'AUTOMATIC']);
});

function imageUrlOcrResult(string $text): array
{
    return ['texts' => explode("\n", $text)];
}

function submitImageUrlOcr($test, KycDocumentType $type = KycDocumentType::NationalId): KycApplication
{
    return app(SubmitKycApplicationAction::class)->execute($test->tenant, $test->user, 'CN', 'CLIENT-IGNORED', kycTestImage(), $type === KycDocumentType::Passport ? null : kycTestImage('back.png'), documentType: $type);
}

it('recognizes each retained original independently and encrypts the back validity evidence', function () {
    $sentOptions = [];
    $responses = ['11010519491231002x', '有效期限 2020.01.01-2099.12.31'];
    Http::fake(function ($request, array $options) use (&$sentOptions, &$responses) {
        $sentOptions[] = $options;

        return Http::response(imageUrlOcrResult(array_shift($responses)));
    });
    $application = submitImageUrlOcr($this);
    expect($application->processing_status)->toBe('QUEUED')->and($application->identity_hash)->toBeNull();
    Http::assertNothingSent();
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    $application->refresh();
    foreach ($sentOptions as $options) {
        expect($options['connect_timeout'])->toBe(10)->and($options['timeout'])->toBe(120)
            ->and($options['allow_redirects'])->toBeFalse();
    }
    $images = app(ImageStorage::class);
    foreach ([$application->front_object_key, $application->back_object_key] as $key) {
        $imageId = $images->record('private', $key)->id;
        Http::assertSent(function ($request) use ($imageId) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $imageUrl = $query['image'] ?? '';

            return str_starts_with($request->url(), 'http://202.95.12.200:9601/ocr?')
                && $request->method() === 'GET' && count($query) === 1
                && str_contains($imageUrl, $imageId) && str_starts_with($imageUrl, 'http://a.localhost/')
                && ! $request->hasHeader('apikey') && ! $request->hasHeader('Authorization')
                && ! $request->hasHeader('Cookie') && $request->body() === '';
        });
    }
    expect($application->automatically_approved)->toBeTrue()->and($application->ocr_provider)->toBe('image_url')
        ->and($application->identity_number_encrypted)->not->toContain('11010519491231002X')
        ->and($application->back_ocr_result_encrypted)->not->toBeNull()
        ->and($application->back_ocr_result_encrypted)->not->toContain('2099-12-31')
        ->and(IdentityRecord::count())->toBe(1);
    Http::assertSentCount(2);
});

it('retains failed front OCR applications without saving an identity or calling back OCR', function ($text) {
    Http::fake(['*' => Http::response(imageUrlOcrResult($text))]);
    $application = submitImageUrlOcr($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(1)
        ->and($application->fresh()->processing_status)->toBe('FAILED')
        ->and($application->fresh()->processing_error)->toBe('KYC_OCR_MISMATCH');
    Http::assertSentCount(1);
})->with(['', 'some text', '110105194912310021', "11010519491231002X\n320311197707060018", '011010519491231002X', '11010519491331002X']);

it('supports passport labelled numbers and TD3 MRZ without requiring back validity', function ($text, $valid) {
    Http::fake(['*' => Http::response(imageUrlOcrResult($text))]);
    $application = submitImageUrlOcr($this, KycDocumentType::Passport);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($application->fresh()->automatically_approved)->toBe($valid)
        ->and($application->fresh()->back_ocr_result_encrypted)->toBeNull()
        ->and(IdentityRecord::count())->toBe($valid ? 1 : 0);
    if (! $valid) expect($application->fresh()->processing_status)->toBe('FAILED');
    Http::assertSentCount(1);
})->with([
    ['Passport No: E12345678', true], ['护照号码：E12345678', true], ['E12345678', false],
    ["P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10", true],
    ["P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C35UTO7408122F1204159ZE184226B<<<<<10", false],
    ["Passport No: E12345678\nPassport No: E99999999", false],
]);

it('recognizes only labelled unambiguous valid back dates and applies Beijing validity', function (string $text, ?string $error) {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-30T15:59:59Z'));
    Http::fakeSequence()->push(imageUrlOcrResult('11010519491231002X'))->push(imageUrlOcrResult($text));
    $application = submitImageUrlOcr($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    $application->refresh();
    expect($application->ocr_status->value)->toBe('SUCCEEDED')
        ->and($application->processing_error)->toBe($error)
        ->and($application->processing_status)->toBe($error === null ? 'COMPLETE' : 'FAILED')
        ->and(IdentityRecord::count())->toBe($error === null ? 1 : 0);
    Http::assertSentCount(2);
})->with([
    'dot separated through today' => ['有效期限 2020.01.01-2026.10.30', null],
    'slash separated' => ['有效期：2020/1/1 至 2030/1/1', null],
    'hyphen separated' => ['有效期限：2020-01-01—2030-01-01', null],
    'Chinese dates' => ['有效期限：2020年01月01日至2030年01月01日', null],
    'label on preceding line' => ["有效期限\n2020.01.01-2030.01.01", null],
    'explicit long term' => ['有效期限 2020.01.01-长期', null],
    'expired' => ['有效期限 2020.01.01-2026.10.29', 'KYC_DOCUMENT_EXPIRED'],
    'future' => ['有效期限 2026.10.31-2036.10.31', 'KYC_DOCUMENT_NOT_YET_VALID'],
    'no text' => ['', 'KYC_BACK_VALIDITY_UNRECOGNIZED'],
    'unlabelled dates' => ['2020.01.01-2030.01.01', 'KYC_BACK_VALIDITY_UNRECOGNIZED'],
    'other date fields' => ['出生日期 1990.01.01 签发日期 2020.01.01', 'KYC_BACK_VALIDITY_UNRECOGNIZED'],
    'missing start for long term' => ['有效期限 长期', 'KYC_BACK_VALIDITY_UNRECOGNIZED'],
    'invalid calendar day' => ['有效期限 2020.02.30-2030.01.01', 'KYC_BACK_VALIDITY_UNRECOGNIZED'],
    'reversed date range' => ['有效期限 2030.01.01-2020.01.01', 'KYC_BACK_VALIDITY_UNRECOGNIZED'],
    'conflicting ranges' => ["有效期2020.01.01-2030.01.01\n有效期2021.01.01-2031.01.01", 'KYC_BACK_VALIDITY_UNRECOGNIZED'],
]);

it('queues failed or malformed OCR responses without logging OCR text or image URLs', function ($body, $status) {
    Log::spy();
    Http::fake(['*' => Http::response($body, $status)]);
    $application = submitImageUrlOcr($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(1)
        ->and($application->fresh()->processing_status)->toBe('QUEUED')
        ->and($application->fresh()->processing_error)->toBe('KYC_OCR_UNAVAILABLE');
    Log::shouldHaveReceived('warning')->with('Image URL KYC OCR failed', ['phase' => 'response', 'http_status' => $status])->once();
    Log::shouldNotHaveReceived('warning', [Mockery::any(), Mockery::on(fn ($context) => str_contains(json_encode($context), 'PRIVATE') || str_contains(json_encode($context), 'signature=') || str_contains(json_encode($context), '11010519491231002X'))]);
    Http::assertSentCount(1);
})->with([
    [['error' => 'PRIVATE'], 403], [['texts' => ['PRIVATE']], 302], ['PRIVATE html', 200],
    [['texts' => '11010519491231002X'], 200], [['texts' => [123]], 200],
    [['texts' => ['11010519491231002X'], 'success' => false], 200],
    [['texts' => ['11010519491231002X'], 'error' => 'PRIVATE'], 200],
    [['other' => '11010519491231002X'], 200],
]);

it('sanitizes transport failures and bounds background retries while retaining originals', function () {
    Log::spy();
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        throw new ConnectionException('PRIVATE URL and key');
    });
    $application = submitImageUrlOcr($this);
    foreach (range(1, 3) as $attempt) {
        app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
        expect($application->fresh()->processing_error)->toBe('KYC_OCR_UNAVAILABLE');
        $this->travel(181)->seconds();
    }
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($calls)->toBe(3)->and(IdentityRecord::count())->toBe(0)
        ->and($application->fresh()->processing_status)->toBe('FAILED')
        ->and(\App\Domain\Media\StoredImage::where('business_reference', $application->id)->where('state', 'ready')->count())->toBe(2);
    Log::shouldNotHaveReceived('warning', [Mockery::any(), Mockery::on(fn ($context) => str_contains(json_encode($context), 'PRIVATE'))]);
});

it('tolerates an OCR provider logging failure without exposing the transport exception', function () {
    Log::shouldReceive('warning')->with('Image URL KYC OCR failed', ['phase' => 'transport', 'http_status' => null])
        ->once()->andThrow(new RuntimeException('PRIVATE log'));
    Log::shouldReceive('warning')->with('KYC background processing failed', Mockery::on(fn ($context) => $context['code'] === 'KYC_OCR_UNAVAILABLE' && ! str_contains(json_encode($context), 'PRIVATE')))->once();
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        throw new ConnectionException('PRIVATE URL and key');
    });
    $application = submitImageUrlOcr($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($application->fresh()->processing_error)->toBe('KYC_OCR_UNAVAILABLE')->and($application->fresh()->processing_status)->toBe('QUEUED');
    expect($calls)->toBe(1);
});

it('fails closed for an unknown driver', function () {
    config(['kyc.ocr_driver' => 'unknown']);
    expect(app(KycOcrProviderInterface::class))->toBeInstanceOf(UnavailableKycOcrProvider::class);
    Http::assertNothingSent();
});
it('enforces the current five MiB boundary on every document side before OCR', function (string $type, string $side, int $bytes, bool $rejected): void {
    $request = new SubmitKycApplicationRequest;
    $request->merge(['document_type' => $type]);
    $image = kycTestImage('boundary.png');
    $contents = file_get_contents($image->getRealPath());
    $file = UploadedFile::fake()->createWithContent('boundary.png', str_pad($contents, $bytes, "\0"));
    $validator = Validator::make([$side => $file], [$side => $request->rules()[$side]]);
    expect($validator->fails())->toBe($rejected);
    Http::assertNothingSent();
})->with([
    ['NATIONAL_ID', 'front', 1048576, false],
    ['NATIONAL_ID', 'front', 5242880, false],
    ['NATIONAL_ID', 'back', 5242880, false],
    ['PASSPORT', 'front', 5242880, false],
    ['PASSPORT', 'front', 5242881, true],
]);
