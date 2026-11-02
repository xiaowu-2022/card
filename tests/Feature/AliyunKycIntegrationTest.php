<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\ProcessPendingKyc;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Media\ImageStorage;
use App\Application\Media\OssSettings;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\Media\StoredImage;
use App\Domain\Media\OssConfiguration;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Providers\Kyc\AliyunKycOcrProvider;
use App\Infrastructure\Storage\OssImages;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-10-30T15:59:59Z'));
    Storage::fake('private');
    Http::preventStrayRequests();
    config([
        'kyc.ocr_driver' => 'aliyun',
        'kyc.aliyun.access_key_id' => 'synthetic-aliyun-key',
        'kyc.aliyun.access_key_secret' => 'synthetic-aliyun-secret',
        'kyc.aliyun.security_token' => '',
        'media.storage' => 'server',
    ]);
    DB::table('media_storage_settings')->where('id', 1)->update(['storage_driver' => 'server']);
    PlatformKycSetting::current()->update(['enabled' => true, 'review_mode' => 'AUTOMATIC']);
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->where('email', 'user@a.localhost')->firstOrFail();
    $this->admin = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
});

/** Synthetic Aliyun structured responses; no upstream service is contacted. */
function aliyunFeatureResult(array $data): array
{
    return ['RequestId' => '88888888-8888-4888-8888-888888888888', 'Data' => json_encode(['data' => $data], JSON_THROW_ON_ERROR)];
}

function aliyunFeatureFront(string $number = '11010519491231002X'): array
{
    return aliyunFeatureResult(['face' => ['data' => ['idNumber' => $number]]]);
}

function aliyunFeatureBack(string $period = '2020.01.01-2099.12.31'): array
{
    return aliyunFeatureResult(['back' => ['data' => ['validPeriod' => $period]]]);
}

function submitAliyunFeature($test, KycDocumentType $type = KycDocumentType::NationalId, string $country = 'CN'): KycApplication
{
    return app(SubmitKycApplicationAction::class)->execute(
        $test->tenant, $test->user, $country, 'IGNORED-CLIENT-NUMBER', kycTestImage('front.png'),
        $type === KycDocumentType::Passport ? null : kycTestImage('back.png'), (string) Str::uuid(), documentType: $type,
    );
}

function assertAliyunFeatureRequests(KycApplication $application, array $sides, string $action = 'RecognizeIdcard'): void
{
    $records = Http::recorded();
    expect($records)->toHaveCount(count($sides));
    foreach ($records as $index => [$request]) {
        expect(parse_url($request->url(), PHP_URL_SCHEME))->toBe('https')
            ->and(parse_url($request->url(), PHP_URL_HOST))->toBe('ocr-api.cn-hangzhou.aliyuncs.com')
            ->and($request->method())->toBe('POST')
            ->and($request->body())->toBe('')
            ->and($request->header('x-acs-action'))->toBe([$action])
            ->and($request->header('x-acs-version'))->toBe(['2021-07-07'])
            ->and($request->header('Authorization')[0] ?? '')->toStartWith('ACS3-HMAC-SHA256 Credential=synthetic-aliyun-key,')
            ->and($request->hasHeader('Cookie'))->toBeFalse();
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
        expect(array_keys($query))->toBe(['Url']);
        $imageUrl = $query['Url'];
        $image = app(ImageStorage::class)->record('private', $application->{$sides[$index].'_object_key'});
        expect(parse_url($imageUrl, PHP_URL_HOST))->toBe('a.localhost')
            ->and(parse_url($imageUrl, PHP_URL_PATH))->toBe('/media/images/'.$image->id);
        parse_str(parse_url($imageUrl, PHP_URL_QUERY) ?: '', $imageQuery);
        expect($imageQuery['profile'] ?? null)->toBe('original')
            ->and($imageQuery['signature'] ?? null)->toMatch('/^[a-f0-9]{64}$/')
            ->and(array_keys($imageQuery))->toBe(['profile', 'signature']);
    }
}

it('uses the Aliyun adapter for sequential asynchronous front and back original-URL recognition', function (): void {
    expect(app(KycOcrProviderInterface::class))->toBeInstanceOf(AliyunKycOcrProvider::class);
    $application = submitAliyunFeature($this);
    expect($application->processing_status)->toBe('QUEUED')->and($application->identity_hash)->toBeNull();
    Http::assertNothingSent();
    $counts = collect(['wallets', 'ledger_entries', 'ledger_accounts', 'user_cards'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
    $stages = [];
    Http::fake(function () use ($application, &$stages) {
        $current = $application->fresh();
        $stages[] = [$current->processing_status, $current->ocr_status->value, $current->identity_hash !== null, $current->back_ocr_result_encrypted !== null];

        return Http::response(count($stages) === 1 ? aliyunFeatureFront() : aliyunFeatureBack());
    });
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    $application->refresh();
    assertAliyunFeatureRequests($application, ['front', 'back']);
    expect($stages)->toBe([['PROCESSING', 'NOT_STARTED', false, false], ['PROCESSING', 'SUCCEEDED', true, false]])
        ->and($application->ocr_provider)->toBe('aliyun')
        ->and($application->ocr_reference)->toBe('88888888-8888-4888-8888-888888888888')
        ->and($application->processing_attempts)->toBe(1)
        ->and($application->processing_status)->toBe('COMPLETE')
        ->and($application->automatically_approved)->toBeTrue()
        ->and($application->identity_number_encrypted)->not->toContain('11010519491231002X')
        ->and($application->back_ocr_result_encrypted)->not->toContain('2099-12-31')
        ->and(IdentityRecord::sole()->verification_basis)->toBe('OCR');
    $evidence = json_decode(app(KycDataCipher::class)->decrypt($application->back_ocr_result_encrypted), true, flags: JSON_THROW_ON_ERROR);
    expect($evidence)->toMatchArray([
        'provider' => 'aliyun', 'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
        'back_object_key' => $application->back_object_key, 'valid_from' => '2020-01-01', 'valid_until' => '2099-12-31', 'long_term' => false,
        'sha256' => hash('sha256', kycTestImage()->getContent()),
    ]);
    foreach ($counts as $table => $count) expect(DB::table($table)->count())->toBe($count);
});

it('applies Beijing expiry-day and explicit long-term rules to Aliyun back evidence', function (string $period, ?string $error): void {
    Http::fakeSequence()->push(aliyunFeatureFront())->push(aliyunFeatureBack($period));
    $application = submitAliyunFeature($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    $application->refresh();
    assertAliyunFeatureRequests($application, ['front', 'back']);
    expect($application->ocr_status->value)->toBe('SUCCEEDED')
        ->and($application->processing_error)->toBe($error)
        ->and($application->processing_status)->toBe($error === null ? 'COMPLETE' : 'FAILED')
        ->and($application->automatically_approved)->toBe($error === null)
        ->and(IdentityRecord::count())->toBe($error === null ? 1 : 0);
})->with([
    'expiry day included' => ['2020.01.01-2026.10.30', null],
    'expired' => ['2020.01.01-2026.10.29', 'KYC_DOCUMENT_EXPIRED'],
    'not yet valid' => ['2026.10.31-2036.10.31', 'KYC_DOCUMENT_NOT_YET_VALID'],
    'explicit long term' => ['2020.01.01-长期', null],
    'future long term' => ['2026.10.31-长期', 'KYC_DOCUMENT_NOT_YET_VALID'],
    'missing validity' => ['', 'KYC_BACK_VALIDITY_UNRECOGNIZED'],
    'malformed calendar dates' => ['2020.02.30-2030.01.01', 'KYC_BACK_VALIDITY_UNRECOGNIZED'],
]);

it('recognizes passports through their matching Aliyun action without back-side requirements', function (string $country, string $action): void {
    Http::fake(['*' => Http::response(aliyunFeatureResult(['passportNumber' => 'E12345678']))]);
    $application = submitAliyunFeature($this, KycDocumentType::Passport, $country);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    $application->refresh();
    assertAliyunFeatureRequests($application, ['front'], $action);
    expect($application->processing_status)->toBe('COMPLETE')->and($application->automatically_approved)->toBeTrue()
        ->and($application->ocr_provider)->toBe('aliyun')->and($application->back_object_key)->toBeNull()
        ->and($application->back_ocr_result_encrypted)->toBeNull()
        ->and(StoredImage::where('business_reference', $application->id)->where('state', 'ready')->count())->toBe(1)
        ->and(IdentityRecord::sole()->document_type->value)->toBe('PASSPORT');
})->with([
    'Chinese passport' => ['CN', 'RecognizeChinesePassport'],
    'foreign passport' => ['MY', 'RecognizePassport'],
]);

it('sends configured HTTPS OSS original URLs without downgrading or uploading image bytes to Aliyun', function (): void {
    config(['media.storage' => 'oss']);
    DB::table('media_storage_settings')->where('id', 1)->update(['storage_driver' => 'oss']);
    $configuration = app(OssSettings::class)->save([
        'region' => 'cn-beijing', 'bucket' => 'test-images', 'endpoint' => 'https://images.example.test',
        'public_url' => 'https://images.example.test', 'access_key_id' => 'synthetic-oss-key', 'access_key_secret' => 'synthetic-oss-secret',
    ], $this->admin);
    app(OssSettings::class)->activate($configuration, $this->admin);
    $oss = new class extends OssImages
    {
        public array $objects = [];

        public function put(OssConfiguration $config, string $key, string $bytes, string $mime): void
        {
            $this->objects[$key] = $bytes;
        }

        public function getBounded(OssConfiguration $config, string $key, int $maxBytes): string
        {
            return substr($this->objects[$key], 0, $maxBytes + 1);
        }
    };
    app()->instance(OssImages::class, $oss);
    Http::fakeSequence()->push(aliyunFeatureFront())->push(aliyunFeatureBack());
    $application = submitAliyunFeature($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($application->fresh()->automatically_approved)->toBeTrue();
    $records = Http::recorded();
    expect($records)->toHaveCount(2);
    $storedKeys = [];
    foreach ($records as $index => [$request]) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
        $key = $index === 0 ? $application->front_object_key : $application->back_object_key;
        $storedKey = app(ImageStorage::class)->record('private', $key)->object_key;
        $storedKeys[] = $storedKey;
        expect(parse_url($request->url(), PHP_URL_HOST))->toBe('ocr-api.cn-hangzhou.aliyuncs.com')
            ->and($request->body())->toBe('')->and(array_keys($query))->toBe(['Url'])
            ->and($query['Url'])->toBe('https://images.example.test/'.$storedKey)
            ->and(parse_url($query['Url'], PHP_URL_QUERY))->toBeNull();
    }
    expect(array_keys($oss->objects))->toBe($storedKeys);
});

it('rejects expiry after the Beijing calendar date rolls over without re-recognizing history', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-30T16:00:00Z'));
    Http::fakeSequence()->push(aliyunFeatureFront())->push(aliyunFeatureBack('2020.01.01-2026.10.30'));
    $application = submitAliyunFeature($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($application->fresh()->processing_status)->toBe('FAILED')
        ->and($application->fresh()->processing_error)->toBe('KYC_DOCUMENT_EXPIRED')
        ->and($application->fresh()->back_ocr_result_encrypted)->not->toBeNull()
        ->and(IdentityRecord::count())->toBe(0);
    Http::assertSentCount(2);
});

it('leaves failed Aliyun number recognition manually approvable without calling the back or OCR again', function (): void {
    Http::fake(['*' => Http::response(aliyunFeatureFront('invalid-number'))]);
    $application = submitAliyunFeature($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    assertAliyunFeatureRequests($application, ['front']);
    $application->refresh();
    expect($application->review_status->value)->toBe('PENDING')
        ->and($application->processing_status)->toBe('FAILED')
        ->and($application->processing_error)->toBe('KYC_OCR_MISMATCH')
        ->and($application->identity_hash)->toBeNull()
        ->and($application->back_ocr_result_encrypted)->toBeNull();
    app(ApproveKycAction::class)->execute($this->tenant->id, $application->id, $this->admin, null, '11010519491231002X');
    $application->refresh();
    expect($application->review_status->value)->toBe('APPROVED')->and($application->automatically_approved)->toBeFalse()
        ->and($application->identity_number_source)->toBe('ADMIN')->and($application->ocr_status->value)->toBe('FAILED')
        ->and($application->ocr_result_encrypted)->toBeNull()->and($application->back_ocr_result_encrypted)->toBeNull()
        ->and(IdentityRecord::sole()->verification_basis)->toBe('MANUAL');
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    Http::assertSentCount(1);
});

it('allows immediate linked resubmission after Aliyun failure while preserving superseded originals', function (): void {
    Http::fakeSequence()->push(aliyunFeatureFront('invalid-number'))->push(aliyunFeatureFront())->push(aliyunFeatureBack());
    $old = submitAliyunFeature($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $old->id);
    $old->refresh();
    $oldState = $old->getAttributes();
    expect($old->processing_status)->toBe('FAILED');
    $this->travel(1)->seconds();
    $current = submitAliyunFeature($this);
    expect($current->resubmission_of_id)->toBe($old->id)->and($current->processing_status)->toBe('QUEUED');
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $old->id);
    Http::assertSentCount(1);
    expect(fn () => app(ApproveKycAction::class)->execute($this->tenant->id, $old->id, $this->admin, null, '11010519491231002X'))
        ->toThrow(DomainException::class, 'This application has been replaced by a newer submission.');
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $current->id);
    expect($current->fresh()->automatically_approved)->toBeTrue()
        ->and($old->fresh()->getAttributes())->toBe($oldState)
        ->and(StoredImage::where('business_reference', $old->id)->where('state', 'ready')->count())->toBe(2)
        ->and(IdentityRecord::sole()->source_kyc_application_id)->toBe($current->id);
    Http::assertSentCount(3);
});

it('fails closed without Aliyun credentials and retains bounded retry and manual-review eligibility', function (string $missing): void {
    config(['kyc.aliyun.'.$missing => '']);
    $application = submitAliyunFeature($this);
    foreach (range(1, 3) as $attempt) {
        app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
        expect($application->fresh()->processing_error)->toBe('KYC_OCR_UNAVAILABLE')
            ->and($application->fresh()->processing_status)->toBe($attempt === 3 ? 'FAILED' : 'QUEUED');
        $this->travel(181)->seconds();
    }
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($application->fresh()->processing_attempts)->toBe(3)->and(IdentityRecord::count())->toBe(0)
        ->and(StoredImage::where('business_reference', $application->id)->where('state', 'ready')->count())->toBe(2);
    app(ApproveKycAction::class)->execute($this->tenant->id, $application->id, $this->admin, null, '11010519491231002X');
    expect($application->fresh()->review_status->value)->toBe('APPROVED')->and($application->fresh()->automatically_approved)->toBeFalse();
    Http::assertNothingSent();
})->with(['access_key_id', 'access_key_secret']);

it('keeps transient Aliyun failures on existing retry bounds without another OCR endpoint or evidence leakage', function (): void {
    Log::spy();
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        throw new ConnectionException('PRIVATE transport and synthetic-secret signature=not-for-logs');
    });
    $application = submitAliyunFeature($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($calls)->toBe(1)->and($application->fresh()->processing_status)->toBe('QUEUED')
        ->and($application->fresh()->processing_error)->toBe('KYC_OCR_UNAVAILABLE');
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($calls)->toBe(1);
    $this->travel(61)->seconds();
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($calls)->toBe(2);
    $this->travel(181)->seconds();
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($calls)->toBe(3)->and($application->fresh()->processing_status)->toBe('FAILED')
        ->and(IdentityRecord::count())->toBe(0);
    Log::shouldNotHaveReceived('warning', [Mockery::any(), Mockery::on(fn ($context) => str_contains(json_encode($context), 'PRIVATE') || str_contains(json_encode($context), 'signature=') || str_contains(json_encode($context), 'synthetic-secret'))]);
});

it('never reprocesses completed or waiting-review Aliyun evidence after driver configuration changes', function (string $mode): void {
    PlatformKycSetting::current()->update(['review_mode' => $mode]);
    Http::fakeSequence()->push(aliyunFeatureFront())->push(aliyunFeatureBack());
    $application = submitAliyunFeature($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    $before = $application->fresh()->getAttributes();
    expect($before['processing_status'])->toBe($mode === 'AUTOMATIC' ? 'COMPLETE' : 'WAITING_REVIEW');
    config(['kyc.aliyun.access_key_id' => '', 'kyc.aliyun.access_key_secret' => '', 'kyc.ocr_driver' => 'unknown']);
    $this->travel(10)->days();
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($application->fresh()->getAttributes())->toBe($before);
    Http::assertSentCount(2);
})->with(['AUTOMATIC', 'MANUAL']);
