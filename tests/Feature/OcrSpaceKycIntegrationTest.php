<?php

use App\Application\Kyc\SubmitKycApplicationAction;
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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->seed();
    Storage::fake('private');
    config(['kyc.ocr_driver' => 'ocr_space', 'kyc.ocr_space.api_key_encrypted' => Crypt::encryptString('synthetic-key')]);
    Http::preventStrayRequests();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    PlatformKycSetting::current()->update(['review_mode' => 'AUTOMATIC']);
});

function ocrSpaceResult(string $text): array
{
    return ['OCRExitCode' => 1, 'IsErroredOnProcessing' => false, 'ParsedResults' => [['FileParseExitCode' => 1, 'ParsedText' => $text]]];
}

function submitOcrSpace($test, KycDocumentType $type = KycDocumentType::NationalId): KycApplication
{
    return app(SubmitKycApplicationAction::class)->execute($test->tenant, $test->user, 'CN', 'CLIENT-IGNORED', kycTestImage(), $type === KycDocumentType::Passport ? null : kycTestImage('back.png'), documentType: $type);
}

it('extracts only the front number without requiring name or back OCR and encrypts its evidence', function () {
    $sentOptions = null;
    Http::fake(function ($request, array $options) use (&$sentOptions) {
        $sentOptions = $options;

        return Http::response(ocrSpaceResult('11010519491231002x'));
    });
    $application = submitOcrSpace($this);
    expect($sentOptions['connect_timeout'])->toBe(10)->and($sentOptions['timeout'])->toBe(120)
        ->and($sentOptions['allow_redirects'])->toBeFalse();
    Http::assertSent(fn ($request) => $request->url() === 'https://api.ocr.space/parse/image'
        && $request->hasHeader('apikey', 'synthetic-key') && $request->hasFile('url')
        && ! $request->hasFile('file') && ! $request->hasFile('base64Image'));
    expect($application->automatically_approved)->toBeTrue()->and($application->ocr_provider)->toBe('ocr_space')
        ->and($application->identity_number_encrypted)->not->toContain('11010519491231002X')
        ->and(IdentityRecord::count())->toBe(1);
    Http::assertSentCount(1);
});

it('rejects empty ambiguous malformed and invalid-checksum numbers without saving an identity', function ($text) {
    Http::fake(['*' => Http::response(ocrSpaceResult($text))]);
    expect(fn () => submitOcrSpace($this))->toThrow(DomainException::class);
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(0);
    Http::assertSentCount(1);
})->with(['', 'some text', '110105194912310021', "11010519491231002X\n320311197707060018", '011010519491231002X', '11010519491331002X']);

it('supports passport labelled numbers and TD3 MRZ without guessing unrelated text', function ($text, $valid) {
    Http::fake(['*' => Http::response(ocrSpaceResult($text))]);
    if ($valid) {
        expect(submitOcrSpace($this, KycDocumentType::Passport)->automatically_approved)->toBeTrue();
    } else {
        expect(fn () => submitOcrSpace($this, KycDocumentType::Passport))->toThrow(DomainException::class);
        expect(IdentityRecord::count())->toBe(0);
    }
})->with([
    ['Passport No: E12345678', true], ['护照号码：E12345678', true], ['E12345678', false],
    ["P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10", true],
    ["P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C35UTO7408122F1204159ZE184226B<<<<<10", false],
    ["Passport No: E12345678\nPassport No: E99999999", false],
]);

it('fails closed on HTTP malformed partial and page errors without logging sensitive content', function ($body, $status, $reason, $diagnostics = []) {
    Log::spy();
    Http::fake(['*' => Http::response($body, $status)]);
    expect(fn () => submitOcrSpace($this))->toThrow(DomainException::class, 'Document recognition is temporarily unavailable.');
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->with('OCR.Space KYC OCR failed', ['phase' => 'response', 'http_status' => $status, 'reason' => $reason] + $diagnostics);
})->with([
    [['ErrorMessage' => 'PRIVATE synthetic-key'], 403, 'http_error'], [['ErrorMessage' => 'PRIVATE'], 429, 'http_error'], ['PRIVATE html', 200, 'invalid_json'],
    [array_replace(ocrSpaceResult('11010519491231002X'), ['OCRExitCode' => 2]), 200, 'provider_result_rejected', ['ocr_exit_code' => 2, 'file_parse_exit_code' => 1, 'provider_error_category' => 'unclassified']],
    [array_replace(ocrSpaceResult('11010519491231002X'), ['IsErroredOnProcessing' => true]), 200, 'provider_result_rejected', ['ocr_exit_code' => 1, 'file_parse_exit_code' => 1, 'provider_error_category' => 'unclassified']],
    [['OCRExitCode' => 1, 'IsErroredOnProcessing' => false, 'ParsedResults' => [['FileParseExitCode' => -20, 'ParsedText' => '11010519491231002X']]], 200, 'page_result_rejected', ['ocr_exit_code' => 1, 'file_parse_exit_code' => -20, 'provider_error_category' => 'unclassified']],
    [array_replace(ocrSpaceResult('11010519491231002X'), ['ErrorMessage' => 'PRIVATE']), 200, 'provider_result_rejected', ['ocr_exit_code' => 1, 'file_parse_exit_code' => 1, 'provider_error_category' => 'unclassified']],
]);

it('logs only fixed categories and allowlisted codes for provider errors returned with HTTP 200', function ($message, $category) {
    Log::spy();
    Http::fake(['*' => Http::response([
        'OCRExitCode' => '99', 'IsErroredOnProcessing' => true,
        'ErrorMessage' => [$message.' PRIVATE synthetic-key https://private.example/image?signature=secret'],
        'ErrorDetails' => ['unexpected' => ['nested private data']],
        'ParsedResults' => [['FileParseExitCode' => '11010519491231002X', 'ParsedText' => 'PRIVATE ID text']],
    ])]);
    expect(fn () => submitOcrSpace($this))->toThrow(DomainException::class);
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->with('OCR.Space KYC OCR failed', [
        'phase' => 'response', 'http_status' => 200, 'reason' => 'provider_result_rejected',
        'ocr_exit_code' => 99, 'file_parse_exit_code' => null, 'provider_error_category' => $category,
    ]);
    Http::assertSentCount(1);
})->with([
    ['Invalid API key', 'api_key_rejected'], ['API key is invalid', 'api_key_rejected'],
    ['Maximum number of requests exceeded', 'quota_or_rate_limit'],
    ['File size limit exceeded', 'file_size_limit'], ['Unable to recognize the file type', 'file_type_error'],
    ['Unable to download the file', 'image_download_failed'], ['Processing timed out', 'provider_timeout'],
    ['Unexpected provider failure', 'unclassified'],
]);

it('does not send requests with missing or corrupt encrypted credentials', function ($encrypted) {
    config(['kyc.ocr_space.api_key_encrypted' => $encrypted]);
    expect(fn () => submitOcrSpace($this))->toThrow(DomainException::class);
    Http::assertNothingSent();
})->with(['', 'invalid-ciphertext']);

it('sanitizes transport errors and logging failures and never retries', function () {
    Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('PRIVATE log'));
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        throw new ConnectionException('PRIVATE URL and key');
    });
    try {
        submitOcrSpace($this);
        $this->fail('Expected failure');
    } catch (DomainException $error) {
        expect($error->getPrevious())->toBeNull()->and($error->getMessage())->not->toContain('PRIVATE');
    }
    expect($calls)->toBe(1)->and(IdentityRecord::count())->toBe(0);
});

it('does not resolve the retired aliyun driver', function () {
    config(['kyc.ocr_driver' => 'aliyun']);
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
