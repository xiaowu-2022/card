<?php

use App\Application\Kyc\SubmitKycApplicationAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\Services\IdentityHashGenerator;
use App\Domain\Kyc\Services\IdentityNumberNormalizer;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Providers\Kyc\UnavailableKycOcrProvider;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
});

it('uses a canonical tenant type country and normalized-number identity hash', function (): void {
    $normalizer = app(IdentityNumberNormalizer::class);
    $hashes = app(IdentityHashGenerator::class);
    $normalized = $normalizer->normalize("  ab\u{FF0D}12  ");
    $same = $hashes->generate($this->tenant->id, 'national_id', 'my', $normalized);

    expect($normalized)->toBe('AB-12')
        ->and($hashes->generate($this->tenant->id, 'NATIONAL_ID', 'MY', 'AB-12'))->toBe($same)
        ->and($hashes->generate((string) Str::uuid(), 'NATIONAL_ID', 'MY', 'AB-12'))->not->toBe($same)
        ->and($hashes->generate($this->tenant->id, 'NATIONAL_ID', 'SG', 'AB-12'))->not->toBe($same)
        ->and($hashes->generate($this->tenant->id, 'PASSPORT', 'MY', 'AB-12'))->not->toBe($same)
        ->and($hashes->advisoryLockKey($same))->toBe($hashes->advisoryLockKey($same))
        ->and((string) $hashes->advisoryLockKey($same))->not->toContain('AB-12');
});

it('uses randomized authenticated KYC encryption and fails closed with a wrong key', function (): void {
    $protector = app(IdentityNumberProtector::class);
    $first = $protector->protect($this->tenant->id, 'NATIONAL_ID', 'MY', ' Secret-123 ');
    $second = $protector->protect($this->tenant->id, 'NATIONAL_ID', 'MY', 'secret-123');

    expect($first['hash'])->toBe($second['hash'])
        ->and($first['encrypted'])->not->toBe($second['encrypted'])
        ->and($protector->decrypt($first['encrypted']))->toBe('SECRET-123');

    $original = config('kyc.data_encryption_key');
    config(['kyc.data_encryption_key' => 'base64:ZWVlZWVlZWVlZWVlZWVlZWVlZWVlZWVlZWVlZWVlZWU=']);
    expect(fn () => (new KycDataCipher)->decrypt($first['encrypted']))->toThrow(DecryptException::class);
    config(['kyc.data_encryption_key' => $original]);
});

it('fails closed when either persistent KYC key is missing or malformed', function (): void {
    $dataKey = config('kyc.data_encryption_key');
    $hashKey = config('kyc.identity_hash_key');
    config(['kyc.data_encryption_key' => null]);
    expect(fn () => new KycDataCipher)->toThrow(RuntimeException::class);
    config(['kyc.data_encryption_key' => $dataKey, 'kyc.identity_hash_key' => null]);
    expect(fn () => app(IdentityHashGenerator::class)->generate($this->tenant->id, 'NATIONAL_ID', 'MY', 'ABC'))->toThrow(RuntimeException::class);
    config(['kyc.identity_hash_key' => $hashKey]);
});

it('migrates legacy APP_KEY ciphertext and hashes without exposing plaintext', function (): void {
    $id = (string) Str::uuid();
    $reviewer = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $oldCiphertext = Crypt::encryptString('LEGACY-123');
    $oldOcr = Crypt::encryptString(json_encode(['candidate_identity_number' => 'LEGACY-123'], JSON_THROW_ON_ERROR));
    DB::table('kyc_applications')->insert([
        'id' => $id, 'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
        'document_type' => 'NATIONAL_ID', 'document_country' => 'MY', 'identity_number_encrypted' => $oldCiphertext,
        'identity_hash' => hash_hmac('sha256', $this->tenant->id.':LEGACY-123', (string) config('kyc.identity_hash_key')),
        'front_object_key' => 'private/front', 'back_object_key' => 'private/back', 'ocr_status' => 'SUCCEEDED',
        'ocr_provider' => 'legacy', 'ocr_result_encrypted' => $oldOcr, 'review_status' => 'APPROVED',
        'reviewed_by_admin_user_id' => $reviewer->id, 'submitted_at' => now(), 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('identity_records')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
        'source_kyc_application_id' => $id, 'document_type' => 'NATIONAL_ID', 'document_country' => 'MY',
        'identity_number_encrypted' => $oldCiphertext, 'identity_hash' => hash('sha256', 'legacy'),
        'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_09_09_000400_reprotect_kyc_sensitive_data.php');
    $migration->up();
    $application = DB::table('kyc_applications')->where('id', $id)->first();
    $expectedHash = app(IdentityHashGenerator::class)->generate($this->tenant->id, 'NATIONAL_ID', 'MY', 'LEGACY-123');

    expect($application->identity_number_encrypted)->not->toBe($oldCiphertext)
        ->and($application->identity_number_encrypted)->not->toContain('LEGACY-123')
        ->and(app(KycDataCipher::class)->decrypt($application->identity_number_encrypted))->toBe('LEGACY-123')
        ->and($application->identity_hash)->toBe($expectedHash)
        ->and(DB::table('identity_records')->where('user_id', $this->user->id)->value('identity_hash'))->toBe($expectedHash)
        ->and(app(KycDataCipher::class)->decrypt($application->ocr_result_encrypted))->toContain('candidate_identity_match', 'MATCH')
        ->not->toContain('candidate_identity_number', 'LEGACY-123');
});

it('never resolves the mock OCR provider in production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    try {
        expect(app(KycOcrProviderInterface::class))->toBeInstanceOf(UnavailableKycOcrProvider::class);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
});

it('prevents submitted identity data and reviewed decisions from being rewritten', function (): void {
    $application = app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'MY', 'IMMUTABLE-123', kycTestImage('front.jpg'), kycTestImage('back.jpg'));
    expect(fn () => $application->forceFill(['document_country' => 'SG'])->save())->toThrow(LogicException::class);

    $application->refresh()->forceFill([
        'review_status' => 'REJECTED', 'review_reason_code' => 'OTHER', 'review_message' => 'Final decision.',
        'reviewed_by_admin_user_id' => AdminUser::query()->where('email', 'owner@a.localhost')->value('id'), 'reviewed_at' => now(),
    ])->save();
    expect(fn () => $application->forceFill(['review_message' => 'Changed decision.'])->save())->toThrow(LogicException::class);
});
