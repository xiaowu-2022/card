<?php

use App\Application\Kyc\SubmitKycApplicationAction;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Http\Requests\SubmitKycApplicationRequest;
use App\Support\Errors\DomainException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    config(['kyc.ocr_driver' => 'aliyun', 'kyc.aliyun.access_key_id' => 'synthetic-key', 'kyc.aliyun.access_key_secret' => 'synthetic-secret']);
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false]);
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    PlatformKycSetting::current()->update(['review_mode' => 'AUTOMATIC']);
});

function aliyunKycFake(string $number = 'E12345678'): void
{
    Http::fake(['ocr-api.cn-hangzhou.aliyuncs.com/*' => Http::response(['RequestId' => 'synthetic-request', 'Data' => json_encode(['data' => ['passportNumber' => $number, 'nameEn' => 'TEST USER']])])]);
}

it('recognizes passport number before approving and stores only one private image', function (): void {
    aliyunKycFake();
    $application = app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', 'E12345678', kycTestImage('passport.jpg'), null, documentType: KycDocumentType::Passport);
    expect($application->automatically_approved)->toBeTrue()->and($application->back_object_key)->toBeNull()
        ->and(IdentityRecord::count())->toBe(1)->and(Storage::disk('private')->allFiles())->toHaveCount(1);
    Http::assertSent(fn ($request) => $request->hasHeader('x-acs-action', 'RecognizeChinesePassport') && $request->hasHeader('Authorization'));
});

it('rejects missing or mismatched recognized numbers without creating an approved identity', function (string $number): void {
    aliyunKycFake($number);
    expect(fn () => app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', 'E12345678', kycTestImage('passport.jpg'), null, documentType: KycDocumentType::Passport))->toThrow(DomainException::class);
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
})->with(['', 'E99999999']);

it('fails closed on upstream failure without exposing raw errors', function (): void {
    Http::fake(['*' => Http::response(['Code' => 'noPermission', 'Message' => 'sensitive-marker'], 403)]);
    expect(fn () => app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', 'E12345678', kycTestImage('passport.jpg'), null, documentType: KycDocumentType::Passport))
        ->toThrow(DomainException::class, 'Document recognition is temporarily unavailable.');
    expect(IdentityRecord::count())->toBe(0);
});

it('recognizes both sides of mainland identity cards and checks the number checksum', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push(['Data' => json_encode(['data' => ['face' => ['data' => ['idNumber' => '11010519491231002X', 'name' => '测试']]]])])
        ->push(['Data' => json_encode(['data' => ['back' => ['data' => ['issueAuthority' => '测试签发机关', 'validPeriod' => '2020.01.01-2040.01.01']]]])])]);
    $application = app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', '11010519491231002X', kycTestImage('front.jpg'), kycTestImage('back.jpg'));
    expect($application->automatically_approved)->toBeTrue();
    Http::assertSentCount(2);
});

it('requires the correct image count and mainland country at the HTTP boundary', function (): void {
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/kyc/applications', [
        'document_type' => 'NATIONAL_ID', 'document_country' => 'MY', 'identity_number' => '11010519491231002X', 'front' => kycTestImage('front.jpg'),
    ])->assertSessionHasErrors(['document_country', 'back']);
    Http::assertNothingSent();
});

it('logs only allowlisted failure metadata and preserves fail-closed approval', function (int $status) {
    Log::spy();
    $requestId = '86B83935-DD36-195B-B6E4-D07BE370C8B6';
    Http::fake(['*' => Http::response(['Code' => 'noPermission', 'RequestId' => $requestId,
        'Message' => 'PRIVATE response with synthetic-secret', 'Data' => ['idNumber' => 'PRIVATE-IDENTITY', 'Authorization' => 'PRIVATE-AUTH']], $status)]);
    expect(fn () => app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', 'E12345678', kycTestImage(), null, documentType: KycDocumentType::Passport))->toThrow(DomainException::class);
    Log::shouldHaveReceived('warning')->once()->with('Aliyun KYC OCR failed', [
        'action' => 'RecognizeChinesePassport', 'phase' => 'upstream', 'http_status' => $status,
        'provider_code' => 'noPermission', 'provider_request_id' => $requestId, 'transport_code' => null,
    ]);
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(0);
})->with([200, 403]);

it('omits arbitrary error codes and request IDs instead of logging echoed secrets', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['Code' => 'synthetic-secret', 'RequestId' => 'E12345678', 'Message' => 'PRIVATE'], 400)]);
    expect(fn () => app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', 'E12345678', kycTestImage(), null, documentType: KycDocumentType::Passport))->toThrow(DomainException::class);
    Log::shouldHaveReceived('warning')->once()->with('Aliyun KYC OCR failed', [
        'action' => 'RecognizeChinesePassport', 'phase' => 'upstream', 'http_status' => 400,
        'provider_code' => 'UNRECOGNIZED', 'provider_request_id' => null, 'transport_code' => null,
    ]);
});

it('distinguishes invalid JSON and malformed data without logging response contents', function (mixed $response, string $phase) {
    Log::spy();
    Http::fake(['*' => Http::response($response, 200)]);
    expect(fn () => app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', 'E12345678', kycTestImage(), null, documentType: KycDocumentType::Passport))->toThrow(DomainException::class);
    Log::shouldHaveReceived('warning')->once()->with('Aliyun KYC OCR failed', [
        'action' => 'RecognizeChinesePassport', 'phase' => $phase, 'http_status' => 200,
        'provider_code' => null, 'provider_request_id' => null, 'transport_code' => null,
    ]);
})->with([['<html>PRIVATE</html>', 'response_json'], [['Data' => 'PRIVATE'], 'response_data']]);

it('reports missing credentials locally and never sends an image', function () {
    Log::spy();
    config(['kyc.aliyun.access_key_secret' => '']);
    expect(fn () => app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', 'E12345678', kycTestImage(), null, documentType: KycDocumentType::Passport))->toThrow(DomainException::class);
    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->with('Aliyun KYC OCR failed', [
        'action' => 'RecognizeChinesePassport', 'phase' => 'configuration', 'http_status' => null,
        'provider_code' => null, 'provider_request_id' => null, 'transport_code' => null,
    ]);
});

it('reports transport errno without including the exception message or chain', function () {
    Log::spy();
    Http::fake(function () {
        $previous = new ConnectException('cURL error 28: PRIVATE URL and secret', new Request('POST', 'https://example.invalid/private'));
        throw new ConnectionException('PRIVATE', 0, $previous);
    });
    expect(fn () => app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', 'E12345678', kycTestImage(), null, documentType: KycDocumentType::Passport))->toThrow(DomainException::class);
    Log::shouldHaveReceived('warning')->once()->with('Aliyun KYC OCR failed', [
        'action' => 'RecognizeChinesePassport', 'phase' => 'transport', 'http_status' => null,
        'provider_code' => null, 'provider_request_id' => null, 'transport_code' => 28,
    ]);
    expect(IdentityRecord::count())->toBe(0);
});

it('does not approve or expose an upstream exception when diagnostic logging fails', function () {
    Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('PRIVATE logging failure'));
    Http::fake(['*' => Http::response(['Code' => 'noPermission', 'Message' => 'PRIVATE'], 403)]);
    try {
        app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', 'E12345678', kycTestImage(), null, documentType: KycDocumentType::Passport);
        $this->fail('Expected closed approval path');
    } catch (DomainException $error) {
        expect($error->getPrevious())->toBeNull()->and($error->getMessage())->not->toContain('PRIVATE');
    }
    expect(IdentityRecord::count())->toBe(0);
});

it('reports documented image rejections as unrecognized documents without storing identity data', function (string $code, int $status): void {
    Log::spy();
    Http::fake(['*' => Http::response(['Code' => $code, 'Message' => 'PRIVATE upstream image detail'], $status)]);
    try {
        app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', '11010519491231002X', kycTestImage('front.jpg'), kycTestImage('back.jpg'));
        $this->fail('Expected document rejection');
    } catch (DomainException $error) {
        expect($error->errorCode)->toBe('KYC_OCR_MISMATCH')->and($error->httpStatus)->toBe(422)
            ->and($error->getMessage())->toBe('The document number could not be recognized or does not match. Please upload a clear document image.');
    }
    Http::assertSentCount(1);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'Aliyun KYC OCR failed'
        && $context['provider_code'] === $code && ! str_contains(json_encode($context), 'PRIVATE'));
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
})->with([
    ['unmatchedImageType', 400], ['unmatchedImageType', 200],
    ['unsupportedImageFormat', 415], ['illegalImageContent', 400],
    ['exceededImageContent', 413], ['illegalImageSize', 416],
    ['ExceededImageSize', 400], ['ExceededFaceBackCount', 400],
]);

it('rejects an unrecognizable identity card back even when the front number matches', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push(['Data' => json_encode(['data' => ['face' => ['data' => ['idNumber' => '11010519491231002X']]]])])
        ->push(['Code' => 'unmatchedImageType', 'Message' => 'PRIVATE'], 400)]);
    try {
        app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', '11010519491231002X', kycTestImage('front.jpg'), kycTestImage('back.jpg'));
        $this->fail('Expected back image rejection');
    } catch (DomainException $error) {
        expect($error->errorCode)->toBe('KYC_OCR_MISMATCH');
    }
    Http::assertSentCount(2);
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('keeps service and ambiguous failures distinct from image rejections', function (string $code, int $status): void {
    Http::fake(['*' => Http::response(['Code' => $code, 'Message' => 'PRIVATE'], $status)]);
    try {
        app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', 'E12345678', kycTestImage(), null, documentType: KycDocumentType::Passport);
        $this->fail('Expected unavailable service');
    } catch (DomainException $error) {
        expect($error->errorCode)->toBe('KYC_OCR_UNAVAILABLE')->and($error->httpStatus)->toBe(503)
            ->and($error->getMessage())->not->toContain('PRIVATE');
    }
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(0);
})->with([
    ['algorithmError', 503], ['AlgorithmTimeout', 503], ['ServiceTimeout', 504],
    ['ocrServiceNotOpen', 401], ['OcrServiceExpired', 401], ['noPermission', 403],
    ['Throttling.User', 400], ['InvalidParameter', 400], ['InternalError.Algo', 400],
    ['unknown-image-error', 400], ['unmatchedImageType', 503], ['unmatchedImageType', 403],
]);

it('reports an empty recognized document as an image problem', function (): void {
    Http::fake(['*' => Http::response(['Data' => json_encode(['data' => []])])]);
    try {
        app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', '11010519491231002X', kycTestImage('front.jpg'), kycTestImage('back.jpg'));
        $this->fail('Expected missing number rejection');
    } catch (DomainException $error) {
        expect($error->errorCode)->toBe('KYC_OCR_MISMATCH');
    }
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(0);
});

it('preserves passport image rejection even when diagnostic logging is unavailable', function (string $country): void {
    Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('PRIVATE log error'));
    Http::fake(['*' => Http::response(['Code' => 'unmatchedImageType', 'Message' => 'PRIVATE'], 400)]);
    try {
        app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, $country, 'E12345678', kycTestImage(), null, documentType: KycDocumentType::Passport);
        $this->fail('Expected passport rejection');
    } catch (DomainException $error) {
        expect($error->errorCode)->toBe('KYC_OCR_MISMATCH')->and($error->getPrevious())->toBeNull()
            ->and($error->getMessage())->not->toContain('PRIVATE');
    }
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
})->with(['CN', 'MY']);

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

it('allows time for document upload and OCR without retrying the request', function (): void {
    Http::fake(function ($request, array $options) {
        expect($options['connect_timeout'])->toBe(10)->and($options['timeout'])->toBe(120);
        return Http::response(['Data' => json_encode(['data' => ['passportNumber' => 'E12345678']])]);
    });
    app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', 'E12345678', kycTestImage(), null, documentType: KycDocumentType::Passport);
    Http::assertSentCount(1);
});
