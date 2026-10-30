<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\RejectKycAction;
use App\Application\Kyc\RequireCurrentBackValidity;
use App\Application\Kyc\RetryKycProcessing;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Media\ImageStorage;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Enums\KycOcrStatus;
use App\Domain\Kyc\Enums\KycReviewReason;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-10-30T12:00:00+08:00'));
    Storage::fake('private');
    Queue::fake();
    Http::preventStrayRequests();
    config(['media.storage' => 'server']);
    DB::table('media_storage_settings')->where('id', 1)->update(['storage_driver' => 'server']);
    PlatformKycSetting::current()->update(['enabled' => true, 'review_mode' => 'AUTOMATIC']);
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->where('email', 'user@a.localhost')->firstOrFail();
    $this->admin = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
});

function kycBackApplication($test): KycApplication
{
    return app(SubmitKycApplicationAction::class)->execute($test->tenant, $test->user, 'CN', '',
        kycTestImage('front.png'), kycTestImage('back.png'), (string) Str::uuid());
}

function kycBackPayload(KycApplication $application, array $overrides = []): array
{
    $image = app(ImageStorage::class)->record((string) config('kyc.document_disk'), $application->back_object_key);

    return array_replace([
        'version' => 1, 'document_type' => 'NATIONAL_ID', 'tenant_id' => $application->tenant_id,
        'user_id' => $application->user_id, 'back_object_key' => $application->back_object_key,
        'sha256' => $image->sha256, 'provider' => 'TEST', 'valid_from' => '2020-10-30',
        'valid_until' => '2030-10-30', 'long_term' => false,
    ], $overrides);
}

function kycSaveBack(KycApplication $application, array $overrides = []): void
{
    $application->forceFill(['processing_status' => 'PROCESSING'])->save();
    $application->forceFill(['back_ocr_result_encrypted' => app(KycDataCipher::class)->encrypt(
        json_encode(kycBackPayload($application, $overrides), JSON_THROW_ON_ERROR))])->save();
}

function kycBackRecognizedFront(KycApplication $application): void
{
    $protected = app(IdentityNumberProtector::class)->protect($application->tenant_id, 'NATIONAL_ID', 'CN', '11010519491231002X');
    $application->forceFill(['processing_status' => 'PROCESSING'])->save();
    $application->forceFill(['identity_number_encrypted' => $protected['encrypted'], 'identity_hash' => $protected['hash'],
        'identity_number_source' => 'OCR', 'ocr_status' => KycOcrStatus::Succeeded, 'ocr_provider' => 'TEST',
        'ocr_result_encrypted' => app(KycDataCipher::class)->encrypt(json_encode([
            'identity_number_source' => 'OCR', 'identity_number_recognized' => true,
        ], JSON_THROW_ON_ERROR))])->save();
}

it('requires back evidence for automatic approval even when front recognition already succeeded', function (): void {
    $application = kycBackApplication($this);
    kycBackRecognizedFront($application);
    $before = $application->fresh()->getAttributes();
    expect(fn () => app(ApproveKycAction::class)->executeAutomatic($this->tenant->id, $application->id))
        ->toThrow(DomainException::class, 'The identity card validity period could not be verified.');
    expect($application->fresh()->getAttributes())->toBe($before)->and(IdentityRecord::count())->toBe(0);
    kycSaveBack($application);
    app(ApproveKycAction::class)->executeAutomatic($this->tenant->id, $application->id);
    expect($application->fresh()->review_status)->toBe(KycReviewStatus::Approved)
        ->and($application->fresh()->automatically_approved)->toBeTrue()
        ->and($application->fresh()->toArray())->not->toHaveKey('back_ocr_result_encrypted');
    Http::assertNothingSent();
});

it('rejects malformed or mismatched retained back evidence', function (array $override): void {
    $application = kycBackApplication($this);
    kycSaveBack($application, $override);
    try {
        app(RequireCurrentBackValidity::class)->execute($application);
        $this->fail('Invalid back evidence must not be accepted.');
    } catch (DomainException $error) {
        expect($error->errorCode)->toBe('KYC_BACK_VALIDITY_UNRECOGNIZED');
    }
    Http::assertNothingSent();
})->with([
    'wrong version' => [['version' => 2]],
    'wrong version type' => [['version' => '1']],
    'wrong tenant' => [['tenant_id' => '00000000-0000-0000-0000-000000000001']],
    'wrong user' => [['user_id' => '00000000-0000-0000-0000-000000000002']],
    'wrong original' => [['back_object_key' => 'other/back']],
    'wrong checksum' => [['sha256' => str_repeat('a', 64)]],
    'wrong type' => [['document_type' => 'PASSPORT']],
    'empty provider' => [['provider' => '']],
    'missing expiration' => [['valid_until' => null]],
    'invalid calendar date' => [['valid_from' => '2020-02-30']],
    'wrong long-term type' => [['long_term' => 'true']],
    'long-term with expiration' => [['long_term' => true]],
    'unrecognized text' => [['raw_text' => 'must not be retained']],
]);

it('fails closed for undecryptable back evidence without any external reads', function (): void {
    $application = kycBackApplication($this);
    $application->forceFill(['processing_status' => 'PROCESSING'])->save();
    $application->forceFill(['back_ocr_result_encrypted' => 'synthetic-invalid-ciphertext'])->save();
    expect(fn () => app(RequireCurrentBackValidity::class)->execute($application))->toThrow(DomainException::class);
    Http::assertNothingSent();
});

it('rechecks the current Beijing date at automatic approval after retaining valid recognition', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-30T15:59:59+00:00'));
    $application = kycBackApplication($this);
    kycBackRecognizedFront($application);
    kycSaveBack($application, ['valid_until' => '2026-10-30']);
    app(RequireCurrentBackValidity::class)->execute($application);
    $this->travel(2)->seconds();
    try {
        app(ApproveKycAction::class)->executeAutomatic($this->tenant->id, $application->id);
        $this->fail('A card expiring before Beijing midnight must not pass after midnight.');
    } catch (DomainException $error) {
        expect($error->errorCode)->toBe('KYC_DOCUMENT_EXPIRED');
    }
    expect($application->fresh()->review_status)->toBe(KycReviewStatus::Pending)->and(IdentityRecord::count())->toBe(0);
});

it('accepts explicit long-term validity and rejects future validity', function (bool $future): void {
    $application = kycBackApplication($this);
    kycSaveBack($application, ['valid_from' => $future ? '2026-10-31' : '2020-10-30', 'valid_until' => null, 'long_term' => true]);
    if ($future) {
        try {
            app(RequireCurrentBackValidity::class)->execute($application);
            $this->fail('Future validity must not be accepted.');
        } catch (DomainException $error) {
            expect($error->errorCode)->toBe('KYC_DOCUMENT_NOT_YET_VALID');
        }
    } else {
        app(RequireCurrentBackValidity::class)->execute($application);
        expect($application->fresh()->back_ocr_result_encrypted)->not->toBeNull();
    }
})->with([false, true]);

it('leaves manual approval available without back recognition or after an expired back result', function (string $state): void {
    $application = kycBackApplication($this);
    if ($state === 'expired') {
        kycSaveBack($application, ['valid_until' => '2026-10-29']);
        $application->forceFill(['processing_status' => 'FAILED', 'processing_error' => 'KYC_DOCUMENT_EXPIRED'])->save();
    } else {
        $application->forceFill(['processing_status' => $state])->save();
    }
    $originalBack = $application->back_ocr_result_encrypted;
    app(ApproveKycAction::class)->execute($this->tenant->id, $application->id, $this->admin, null, '11010519491231002X');
    expect($application->fresh()->review_status)->toBe(KycReviewStatus::Approved)
        ->and($application->fresh()->automatically_approved)->toBeFalse()
        ->and($application->fresh()->back_ocr_result_encrypted)->toBe($originalBack)
        ->and($application->fresh()->ocr_result_encrypted)->toBeNull();
    Http::assertNothingSent();
})->with(['QUEUED', 'PROCESSING', 'FAILED', 'expired']);

it('keeps back evidence immutable in the model and database', function (): void {
    $application = kycBackApplication($this);
    kycSaveBack($application);
    $ciphertext = $application->back_ocr_result_encrypted;
    expect(fn () => $application->forceFill(['back_ocr_result_encrypted' => null])->save())->toThrow(LogicException::class);
    $application->refresh();
    expect(fn () => DB::transaction(fn () => DB::table('kyc_applications')->where('id', $application->id)
        ->update(['back_ocr_result_encrypted' => null])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('kyc_applications')->where('id', $application->id)
        ->update(['back_object_key' => 'different/back'])))->toThrow(QueryException::class);
    expect($application->fresh()->back_ocr_result_encrypted)->toBe($ciphertext);
});

it('rejects first back evidence writes outside the same active pending processing generation', function (string $state): void {
    $application = kycBackApplication($this);
    $application->forceFill(['processing_status' => $state])->save();
    $ciphertext = app(KycDataCipher::class)->encrypt(json_encode(kycBackPayload($application), JSON_THROW_ON_ERROR));
    expect(fn () => DB::transaction(fn () => DB::table('kyc_applications')->where('id', $application->id)
        ->update(['back_ocr_result_encrypted' => $ciphertext])))->toThrow(QueryException::class);
    expect($application->fresh()->back_ocr_result_encrypted)->toBeNull();
})->with(['QUEUED', 'FAILED', 'WAITING_REVIEW']);

it('prevents direct automatic approval without back evidence at the database boundary', function (): void {
    $application = kycBackApplication($this);
    kycBackRecognizedFront($application);
    expect(fn () => DB::transaction(fn () => DB::table('kyc_applications')->where('id', $application->id)->update([
        'review_status' => 'APPROVED', 'automatically_approved' => true, 'reviewed_at' => now(),
    ])))->toThrow(QueryException::class);
    expect($application->fresh()->review_status)->toBe(KycReviewStatus::Pending);
});

it('rejects recording evidence while changing the captured processing generation', function (): void {
    $application = kycBackApplication($this);
    $application->forceFill(['processing_status' => 'PROCESSING'])->save();
    $ciphertext = app(KycDataCipher::class)->encrypt(json_encode(kycBackPayload($application), JSON_THROW_ON_ERROR));
    expect(fn () => $application->forceFill(['back_ocr_result_encrypted' => $ciphertext,
        'processing_generation' => $application->processing_generation + 1])->save())->toThrow(LogicException::class);
    $application->refresh();
    expect(fn () => DB::transaction(fn () => DB::table('kyc_applications')->where('id', $application->id)->update([
        'back_ocr_result_encrypted' => $ciphertext, 'processing_generation' => $application->processing_generation + 1,
    ])))->toThrow(QueryException::class);
    expect($application->fresh()->back_ocr_result_encrypted)->toBeNull();
});

it('does not append late back evidence after a manual review has completed', function (): void {
    $application = kycBackApplication($this);
    $application->forceFill(['processing_status' => 'PROCESSING'])->save();
    $ciphertext = app(KycDataCipher::class)->encrypt(json_encode(kycBackPayload($application), JSON_THROW_ON_ERROR));
    app(ApproveKycAction::class)->execute($this->tenant->id, $application->id, $this->admin, null, '11010519491231002X');
    $application->refresh();
    expect(fn () => $application->forceFill(['back_ocr_result_encrypted' => $ciphertext])->save())->toThrow(LogicException::class);
    expect(fn () => DB::transaction(fn () => DB::table('kyc_applications')->where('id', $application->id)
        ->update(['back_ocr_result_encrypted' => $ciphertext])))->toThrow(QueryException::class);
    expect($application->fresh()->review_status)->toBe(KycReviewStatus::Approved)
        ->and($application->fresh()->back_ocr_result_encrypted)->toBeNull();
});

it('copies retained back evidence on rejected retries and reuses it without changing the original review', function (): void {
    $application = kycBackApplication($this);
    kycBackRecognizedFront($application);
    kycSaveBack($application, ['valid_until' => '2026-10-29']);
    app(RejectKycAction::class)->execute($this->tenant->id, $application->id, $this->admin, KycReviewReason::Other, 'Synthetic retained original review');
    $before = $application->fresh()->getAttributes();
    $retry = app(RetryKycProcessing::class)->execute($this->tenant->id, $application->id, $this->admin,
        (string) Str::uuid(), 'Recheck retained originals');
    expect($retry->id)->not->toBe($application->id)
        ->and($retry->back_ocr_result_encrypted)->toBe($application->back_ocr_result_encrypted)
        ->and($retry->ocr_result_encrypted)->toBe($application->ocr_result_encrypted)
        ->and($application->fresh()->getAttributes())->toBe($before);
    try {
        app(RequireCurrentBackValidity::class)->execute($retry);
        $this->fail('A retry must not override an expired original.');
    } catch (DomainException $error) {
        expect($error->errorCode)->toBe('KYC_DOCUMENT_EXPIRED');
    }
    Http::assertNothingSent();
});

it('rejects back evidence copied to an unrelated new application at the database boundary', function (): void {
    $application = kycBackApplication($this);
    kycSaveBack($application);
    $values = $application->getAttributes();
    $values['id'] = (string) Str::uuid();
    $values['submission_key'] = null;
    $values['processing_status'] = 'QUEUED';
    expect(fn () => DB::transaction(fn () => DB::table('kyc_applications')->insert($values)))->toThrow(QueryException::class);
});
