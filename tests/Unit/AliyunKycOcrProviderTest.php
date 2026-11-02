<?php

use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycOcrFailureReason;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Kyc\Enums\KycOcrSide;
use App\Infrastructure\Providers\Kyc\AliyunKycOcrProvider;
use App\Infrastructure\Sms\AliyunAcs3Signer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config(['kyc.aliyun.access_key_id' => 'offline-ocr-key', 'kyc.aliyun.access_key_secret' => 'offline-ocr-secret',
        'kyc.aliyun.security_token' => '']);
    Http::preventStrayRequests();
    $this->ocrLogs = new TestHandler;
    Log::swap(new Logger('offline-ocr', [$this->ocrLogs]));
});

function offlineAliyunEnvelope(array $data, bool $encoded = true): array
{
    return ['RequestId' => '11111111-2222-4333-8444-555555555555',
        'Data' => $encoded ? json_encode(['data' => $data], JSON_THROW_ON_ERROR) : ['data' => $data]];
}

function offlineAliyunRequest(KycOcrSide $side = KycOcrSide::Front, KycDocumentType $type = KycDocumentType::NationalId, string $country = 'CN'): KycOcrRequestDTO
{
    return new KycOcrRequestDTO($type, $country, 'https://images.example.test/front?profile=original&signature=offline%2Bcapability',
        'https://images.example.test/back?profile=original&signature=offline%2Fcapability', $side);
}

function offlineAliyunIdNumber(string $birth): string
{
    $body = '110105'.$birth.'002';
    $sum = 0;
    foreach ([7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2] as $i => $weight) {
        $sum += (int) $body[$i] * $weight;
    }

    return $body.'10X98765432'[$sum % 11];
}

it('signs the exact URL only HTTPS request including content type and optional STS token', function (string $token): void {
    config(['kyc.aliyun.security_token' => $token]);
    $options = [];
    Http::fake(function (Request $request, array $sentOptions) use (&$options) {
        $options = $sentOptions;

        return Http::response(offlineAliyunEnvelope(['face' => ['data' => ['idNumber' => '11010519491231002X']]]));
    });
    $input = offlineAliyunRequest();
    $provider = new AliyunKycOcrProvider;
    $result = $provider->extractIdentityDocument($input);
    expect($provider->name())->toBe('aliyun')->and($result->outcome)->toBe(KycOcrOutcome::Success)
        ->and($result->candidateIdentityNumber)->toBe('11010519491231002X')
        ->and($result->providerReference)->toBe('11111111-2222-4333-8444-555555555555')
        ->and($result->validity)->toBeNull();
    Http::assertSent(function (Request $request) use ($input, $token): bool {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $headers = [];
        foreach ($request->headers() as $name => $values) {
            $name = strtolower($name);
            if ($name === 'host' || $name === 'content-type' || str_starts_with($name, 'x-acs-')) {
                $headers[$name] = $values[0];
            }
        }
        $signature = (new AliyunAcs3Signer)->authorization('POST', '/', $query, $headers, 'offline-ocr-key', 'offline-ocr-secret');

        return $request->method() === 'POST' && $request->body() === ''
            && parse_url($request->url(), PHP_URL_SCHEME) === 'https'
            && parse_url($request->url(), PHP_URL_HOST) === 'ocr-api.cn-hangzhou.aliyuncs.com'
            && $query === ['Url' => $input->frontUrl]
            && $headers['content-type'] === 'application/octet-stream'
            && $headers['x-acs-action'] === 'RecognizeIdcard' && $headers['x-acs-version'] === '2021-07-07'
            && $headers['x-acs-content-sha256'] === hash('sha256', '')
            && ($headers['x-acs-security-token'] ?? '') === $token
            && $request->header('Authorization')[0] === $signature;
    });
    expect($options['connect_timeout'])->toBe(10)->and($options['timeout'])->toBe(120)
        ->and($options['allow_redirects'])->toBeFalse();
    Http::assertSentCount(1);
})->with(['permanent credentials' => '', 'STS credentials' => 'offline-security-token']);

it('uses the documented passport action and structured number without requiring a second image', function (string $country, string $action, bool $encoded): void {
    Http::fake(fn () => Http::response(offlineAliyunEnvelope(['passportNumber' => 'E12345678'], $encoded)));
    $result = (new AliyunKycOcrProvider)->extractIdentityDocument(offlineAliyunRequest(type: KycDocumentType::Passport, country: $country));
    expect($result->outcome)->toBe(KycOcrOutcome::Success)->and($result->candidateIdentityNumber)->toBe('E12345678');
    Http::assertSent(fn (Request $request) => $request->header('x-acs-action')[0] === $action);
    Http::assertSentCount(1);
})->with([
    ['CN', 'RecognizeChinesePassport', true], ['HK', 'RecognizeChinesePassport', false],
    ['MO', 'RecognizeChinesePassport', true], ['TW', 'RecognizeChinesePassport', false],
    ['MY', 'RecognizePassport', true], ['US', 'RecognizePassport', false],
]);

it('preserves current national number normalization calendar and checksum gates', function (mixed $number, ?KycOcrFailureReason $reason): void {
    Http::fake(fn () => Http::response(offlineAliyunEnvelope(['face' => ['data' => ['idNumber' => $number]]], false)));
    $result = (new AliyunKycOcrProvider)->extractIdentityDocument(offlineAliyunRequest());
    expect($result->outcome)->toBe($reason ? KycOcrOutcome::Failed : KycOcrOutcome::Success)
        ->and($result->failureReason)->toBe($reason);
    if ($reason === null) {
        expect($result->candidateIdentityNumber)->toBe('11010519491231002X');
    }
    Http::assertSentCount(1);
})->with([
    ['１１０１０５１９４９１２３１００２ｘ', null], ['110105 19491231 002x', null],
    ['110105194912310020', KycOcrFailureReason::NumberChecksum],
    [offlineAliyunIdNumber('20230229'), KycOcrFailureReason::NumberFormat],
    ['123456', KycOcrFailureReason::NumberFormat], [null, KycOcrFailureReason::NumberMissing],
    [110105194912310020.0, KycOcrFailureReason::NumberMissing], [[], KycOcrFailureReason::NumberMissing],
]);

it('preserves lossless integer identity and passport fields returned by documented schemas', function (KycDocumentType $type, int $number, string $expected): void {
    $data = $type === KycDocumentType::Passport ? ['passportNumber' => $number] : ['face' => ['data' => ['idNumber' => $number]]];
    Http::fake(fn () => Http::response(offlineAliyunEnvelope($data)));
    $result = (new AliyunKycOcrProvider)->extractIdentityDocument(offlineAliyunRequest(type: $type));
    expect($result->outcome)->toBe(KycOcrOutcome::Success)->and($result->candidateIdentityNumber)->toBe($expected);
    Http::assertSentCount(1);
})->with([
    [KycDocumentType::NationalId, 110105194912310011, '110105194912310011'],
    [KycDocumentType::Passport, 31526354, '31526354'],
]);

it('recognizes only the requested back original and retains normalized finite or long term validity', function (string $period, string $from, ?string $until): void {
    Http::fake(fn () => Http::response(offlineAliyunEnvelope(['back' => ['data' => ['validPeriod' => $period]]])));
    $input = offlineAliyunRequest(KycOcrSide::Back);
    $result = (new AliyunKycOcrProvider)->extractIdentityDocument($input);
    expect($result->outcome)->toBe(KycOcrOutcome::Success)->and($result->candidateIdentityNumber)->toBeNull()
        ->and($result->validity?->validFrom)->toBe($from)->and($result->validity?->validUntil)->toBe($until)
        ->and($result->validity?->longTerm)->toBe($until === null);
    Http::assertSent(function (Request $request) use ($input): bool {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query === ['Url' => $input->backUrl] && $request->header('x-acs-action')[0] === 'RecognizeIdcard';
    });
    Http::assertSentCount(1);
})->with([
    ['2020.01.01-2040.01.01', '2020-01-01', '2040-01-01'],
    ['20200101-长期', '2020-01-01', null], ['２０２０年２月２９日至２０４０年２月２９日', '2020-02-29', '2040-02-29'],
]);

it('fails incomplete ambiguous or malformed back validity without requiring issuing authority', function (mixed $period): void {
    Http::fake(fn () => Http::response(offlineAliyunEnvelope(['back' => ['data' => ['validPeriod' => $period, 'issueAuthority' => '']]])));
    $result = (new AliyunKycOcrProvider)->extractIdentityDocument(offlineAliyunRequest(KycOcrSide::Back));
    expect($result->outcome)->toBe(KycOcrOutcome::Failed)->and($result->validity)->toBeNull()
        ->and($result->failureReason)->toBe(KycOcrFailureReason::BackFields);
})->with([[''], [null], ['长期'], ['2020.01.01'], ['2020.01.01-2030.02.30'],
    ['2030.01.01-2020.01.01'], ['2020.01.01-2030.01.01 2031.01.01'], [[]]]);

it('reports front back swaps and does not silently recognize the other side', function (KycOcrSide $side, array $data, KycOcrFailureReason $reason): void {
    Http::fake(fn () => Http::response(offlineAliyunEnvelope($data)));
    $result = (new AliyunKycOcrProvider)->extractIdentityDocument(offlineAliyunRequest($side));
    expect($result->outcome)->toBe(KycOcrOutcome::Failed)->and($result->failureReason)->toBe($reason);
    Http::assertSentCount(1);
})->with([
    [KycOcrSide::Front, ['back' => ['data' => ['validPeriod' => '2020.01.01-长期']]], KycOcrFailureReason::FrontSide],
    [KycOcrSide::Back, ['face' => ['data' => ['idNumber' => '11010519491231002X']]], KycOcrFailureReason::BackSide],
]);

it('retains passport MRZ conflict and check digit rejection with structured output', function (string $number, string $mrz, bool $success): void {
    Http::fake(fn () => Http::response(offlineAliyunEnvelope(['passportNumber' => $number,
        'mrzLine1' => 'P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<', 'mrzLine2' => $mrz])));
    $result = (new AliyunKycOcrProvider)->extractIdentityDocument(offlineAliyunRequest(type: KycDocumentType::Passport, country: 'US'));
    expect($result->outcome)->toBe($success ? KycOcrOutcome::Success : KycOcrOutcome::Failed);
})->with([
    ['L898902C3', 'L898902C36UTO7408122F1204159ZE184226B<<<<<10', true],
    ['L898902C3', 'L898902C35UTO7408122F1204159ZE184226B<<<<<10', false],
    ['E12345678', 'L898902C36UTO7408122F1204159ZE184226B<<<<<10', false],
]);

it('fails missing credentials or invalid original URLs before sending any request', function (array $overrides, string $url): void {
    config($overrides);
    Http::fake();
    try {
        (new AliyunKycOcrProvider)->extractIdentityDocument(new KycOcrRequestDTO(KycDocumentType::NationalId, 'CN', $url, ''));
        $this->fail('Unavailable configuration or invalid URLs must fail closed.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('OCR unavailable.')->and($exception->getPrevious())->toBeNull();
    }
    Http::assertNothingSent();
})->with([
    [['kyc.aliyun.access_key_id' => ''], 'https://images.example.test/front'],
    [['kyc.aliyun.access_key_secret' => ''], 'https://images.example.test/front'],
    [[], 'data:image/png;base64,private'], [[], 'file:///private/image'],
    [[], 'https://user:secret@images.example.test/front'], [[], 'https://images.example.test/'.str_repeat('a', 2048)],
]);

it('keeps authentication quota URL and transport failures unavailable without retries fallback or unsafe diagnostics', function (int $status, mixed $response): void {
    Http::fake(fn () => Http::response($response, $status));
    expect(fn () => (new AliyunKycOcrProvider)->extractIdentityDocument(offlineAliyunRequest()))
        ->toThrow(RuntimeException::class, 'OCR unavailable.');
    Http::assertSentCount(1);
    $text = json_encode($this->ocrLogs->getRecords(), JSON_THROW_ON_ERROR);
    expect($text)->not->toContain('offline-ocr-key', 'offline-ocr-secret', 'private-upstream-message',
        'offline%2Bcapability', '11010519491231002X', 'https://private.example.test');
})->with([
    [403, ['Code' => 'InvalidAccessKeyId.NotFound', 'Message' => 'private-upstream-message']],
    [429, ['Code' => 'Throttling.User', 'Message' => 'private-upstream-message']],
    [400, ['Code' => 'InvalidURL', 'Message' => 'https://private.example.test']],
    [500, ['Code' => 'illegalImageContent', 'Message' => 'private-upstream-message']],
    [200, ['Code' => '11010519491231002X', 'RequestId' => 'offline-ocr-secret', 'Message' => 'private-upstream-message']],
    [302, 'redirect-private-upstream-message'], [200, 'not-json-private-upstream-message'],
    [200, ['Data' => '{malformed']], [200, ['Data' => ['data' => 'private-upstream-message']]],
    [200, str_repeat('x', 2097153)],
]);

it('classifies only explicit image content errors as failed recognition', function (KycOcrSide $side, KycOcrFailureReason $reason): void {
    Http::fake(fn () => Http::response(['Code' => 'illegalImageContent', 'Message' => 'private-message',
        'RequestId' => '11111111-2222-4333-8444-555555555555'], 400));
    $result = (new AliyunKycOcrProvider)->extractIdentityDocument(offlineAliyunRequest($side));
    expect($result->outcome)->toBe(KycOcrOutcome::Failed)->and($result->failureReason)->toBe($reason)
        ->and($result->providerReference)->toBe('11111111-2222-4333-8444-555555555555');
    Http::assertSentCount(1);
})->with([[KycOcrSide::Front, KycOcrFailureReason::NumberMissing], [KycOcrSide::Back, KycOcrFailureReason::BackFields]]);

it('does not retain raw transport errors or repeat a failed request', function (): void {
    Http::fake(fn () => Http::failedConnection('private-original-url offline-ocr-secret'));
    expect(fn () => (new AliyunKycOcrProvider)->extractIdentityDocument(offlineAliyunRequest()))
        ->toThrow(RuntimeException::class, 'OCR unavailable.');
    Http::assertSentCount(1);
    expect(json_encode($this->ocrLogs->getRecords()))->not->toContain('private-original-url', 'offline-ocr-secret');
});

it('never returns a credential echoed as a provider request reference', function (): void {
    config(['kyc.aliyun.security_token' => '11111111-2222-4333-8444-555555555555']);
    Http::fake(fn () => Http::response(offlineAliyunEnvelope(['face' => ['data' => ['idNumber' => '11010519491231002X']]])));
    $result = (new AliyunKycOcrProvider)->extractIdentityDocument(offlineAliyunRequest());
    expect($result->outcome)->toBe(KycOcrOutcome::Success)->and($result->providerReference)->toBeNull();
});
