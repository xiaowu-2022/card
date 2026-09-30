<?php

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
use App\Support\Errors\DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->seed();
    Storage::fake('private');
    config(['kyc.ocr_driver' => 'image_url']);
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

it('extracts only the front number without requiring name or back OCR and encrypts its evidence', function () {
    $sentOptions = null;
    Http::fake(function ($request, array $options) use (&$sentOptions) {
        $sentOptions = $options;

        return Http::response(imageUrlOcrResult('11010519491231002x'));
    });
    $application = submitImageUrlOcr($this);
    expect($sentOptions['connect_timeout'])->toBe(10)->and($sentOptions['timeout'])->toBe(120)
        ->and($sentOptions['allow_redirects'])->toBeFalse();
    Http::assertSent(function ($request) use ($application) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'http://202.95.12.185:9601/ocr?')
            && $request->method() === 'GET'
            && $query === ['image' => app(ImageStorage::class)->ocrUrl('private', $application->front_object_key)]
            && ! $request->hasHeader('apikey') && ! $request->hasHeader('Authorization')
            && $request->body() === '';
    });
    expect($application->automatically_approved)->toBeTrue()->and($application->ocr_provider)->toBe('image_url')
        ->and($application->identity_number_encrypted)->not->toContain('11010519491231002X')
        ->and(IdentityRecord::count())->toBe(1);
    Http::assertSentCount(1);
});

it('rejects empty ambiguous malformed and invalid-checksum numbers without saving an identity', function ($text) {
    Http::fake(['*' => Http::response(imageUrlOcrResult($text))]);
    expect(fn () => submitImageUrlOcr($this))->toThrow(DomainException::class);
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(0);
    Http::assertSentCount(1);
})->with(['', 'some text', '110105194912310021', "11010519491231002X\n320311197707060018", '011010519491231002X', '11010519491331002X']);

it('supports passport labelled numbers and TD3 MRZ without guessing unrelated text', function ($text, $valid) {
    Http::fake(['*' => Http::response(imageUrlOcrResult($text))]);
    if ($valid) {
        expect(submitImageUrlOcr($this, KycDocumentType::Passport)->automatically_approved)->toBeTrue();
    } else {
        expect(fn () => submitImageUrlOcr($this, KycDocumentType::Passport))->toThrow(DomainException::class);
        expect(IdentityRecord::count())->toBe(0);
    }
})->with([
    ['Passport No: E12345678', true], ['护照号码：E12345678', true], ['E12345678', false],
    ["P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10", true],
    ["P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C35UTO7408122F1204159ZE184226B<<<<<10", false],
    ["Passport No: E12345678\nPassport No: E99999999", false],
]);

it('fails closed on failed or malformed responses without logging OCR text or image URLs', function ($body, $status) {
    Log::spy();
    Http::fake(['*' => Http::response($body, $status)]);
    expect(fn () => submitImageUrlOcr($this))->toThrow(DomainException::class, 'Document recognition is temporarily unavailable.');
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->with('Image URL KYC OCR failed', ['phase' => 'response', 'http_status' => $status]);
    Http::assertSentCount(1);
})->with([
    [['error' => 'PRIVATE'], 403], [['texts' => ['PRIVATE']], 302], ['PRIVATE html', 200],
    [['texts' => '11010519491231002X'], 200], [['texts' => [123]], 200],
    [['texts' => ['11010519491231002X'], 'success' => false], 200],
    [['texts' => ['11010519491231002X'], 'error' => 'PRIVATE'], 200],
    [['other' => '11010519491231002X'], 200],
]);

it('sanitizes transport errors and logging failures and never retries', function () {
    Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('PRIVATE log'));
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        throw new ConnectionException('PRIVATE URL and key');
    });
    try {
        submitImageUrlOcr($this);
        $this->fail('Expected failure');
    } catch (DomainException $error) {
        expect($error->getPrevious())->toBeNull()->and($error->getMessage())->not->toContain('PRIVATE');
    }
    expect($calls)->toBe(1)->and(IdentityRecord::count())->toBe(0);
});

it('fails closed for an unknown driver', function () {
    config(['kyc.ocr_driver' => 'unknown']);
    expect(app(KycOcrProviderInterface::class))->toBeInstanceOf(UnavailableKycOcrProvider::class);
    Http::assertNothingSent();
});
it('enforces the restored ten MiB boundary on every document side before OCR', function (string $type, string $side, int $bytes, bool $rejected): void {
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
    ['NATIONAL_ID', 'front', 10485760, false],
    ['NATIONAL_ID', 'back', 10485760, false],
    ['PASSPORT', 'front', 10485760, false],
    ['PASSPORT', 'front', 10485761, true],
]);
