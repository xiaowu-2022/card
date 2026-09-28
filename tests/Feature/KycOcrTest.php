<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Kyc\Enums\KycOcrStatus;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\IdentityNumberNormalizer;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Jobs\ProcessKycOcrJob;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    Http::preventStrayRequests();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
});
function submitCurrentOcr($test): KycApplication
{
    return app(SubmitKycApplicationAction::class)->execute($test->tenant, $test->user, 'CN', 'OCR-1234', kycTestImage('front.png'), kycTestImage('back.png'));
}
it('runs synchronous OCR before the submission transaction and stores only encrypted match evidence', function () {
    $provider = new class implements KycOcrProviderInterface
    {
        public int $transactionLevel = -1;

        public function name(): string
        {
            return 'boundary-test';
        }

        public function extractIdentityDocument(KycOcrRequestDTO $r): KycOcrResultDTO
        {
            $this->transactionLevel = DB::transactionLevel();

            return new KycOcrResultDTO(KycOcrOutcome::Success, 'OCR-1234', 'TEST PERSON', '0.98', 'SAFE-REF');
        }
    };
    app()->instance(KycOcrProviderInterface::class, $provider);
    $outer = DB::transactionLevel();
    $a = submitCurrentOcr($this);
    expect($provider->transactionLevel)->toBe($outer)->and($a->ocr_status)->toBe(KycOcrStatus::Succeeded)
        ->and($a->review_status)->toBe(KycReviewStatus::Pending)
        ->and(json_decode(app(KycDataCipher::class)->decrypt($a->ocr_result_encrypted), true))->toBe(['candidate_identity_match' => 'MATCH'])
        ->and($a->ocr_result_encrypted)->not->toContain('OCR-1234', 'TEST PERSON');
    Queue::assertNotPushed(ProcessKycOcrJob::class);
});
it('fails closed before storage when recognition fails or mismatches', function ($outcome, $number) {
    $p = Mockery::mock(KycOcrProviderInterface::class);
    $p->shouldReceive('extractIdentityDocument')->once()->andReturn(new KycOcrResultDTO($outcome, $number));
    app()->instance(KycOcrProviderInterface::class, $p);
    expect(fn () => submitCurrentOcr($this))->toThrow(DomainException::class, 'could not be recognized or does not match');
    expect(KycApplication::count())->toBe(0)->and(IdentityRecord::count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
})->with([[KycOcrOutcome::Failed, null], [KycOcrOutcome::Success, 'WRONG'], [KycOcrOutcome::Success, '']]);
it('allows a safe resubmission after upstream timeout without queued retries or orphaned documents', function () {
    $p = Mockery::mock(KycOcrProviderInterface::class);
    $p->shouldReceive('extractIdentityDocument')->once()->andThrow(new RuntimeException('secret upstream details'));
    app()->instance(KycOcrProviderInterface::class, $p);
    expect(fn () => submitCurrentOcr($this))->toThrow(DomainException::class, 'Document recognition is temporarily unavailable.');
    expect(KycApplication::count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
    fakeMatchingKycOcr('OCR-1234');
    submitCurrentOcr($this);
    expect(KycApplication::count())->toBe(1);
    Queue::assertNotPushed(ProcessKycOcrJob::class);
});
it('does not rerun OCR on duplicate submission or an obsolete queued job', function () {
    fakeMatchingKycOcr('OCR-1234');
    $a = submitCurrentOcr($this);
    $encrypted = $a->ocr_result_encrypted;
    $p = Mockery::mock(KycOcrProviderInterface::class);
    $p->shouldNotReceive('extractIdentityDocument');
    app()->instance(KycOcrProviderInterface::class, $p);
    expect(fn () => submitCurrentOcr($this))->toThrow(DomainException::class, 'already under review');
    $job = new ProcessKycOcrJob($this->tenant->id, $a->id);
    $job->handle(app(TenantContext::class), $p, app(IdentityNumberNormalizer::class), app(IdentityNumberProtector::class), app(KycDataCipher::class));
    expect($a->fresh()->ocr_result_encrypted)->toBe($encrypted)->and(serialize($job))->not->toContain('OCR-1234', 'front.png')
        ->and(app(TenantContext::class)->hasTenant())->toBeFalse();
});
it('preserves approved review and recognition if an obsolete worker reports failure', function () {
    fakeMatchingKycOcr('OCR-1234');
    $a = submitCurrentOcr($this);
    app(ApproveKycAction::class)->execute($this->tenant->id, $a->id, AdminUser::where('email', 'owner@a.localhost')->firstOrFail());
    (new ProcessKycOcrJob($this->tenant->id, $a->id))->failed(new RuntimeException('test'));
    expect($a->fresh()->review_status)->toBe(KycReviewStatus::Approved)->and($a->fresh()->ocr_status)->toBe(KycOcrStatus::Succeeded);
});
it('rejects untrusted identity output instead of retaining it as approved evidence', function () {
    fakeMatchingKycOcr('<script>different</script>');
    expect(fn () => submitCurrentOcr($this))->toThrow(DomainException::class);
    expect(KycApplication::count())->toBe(0)->and(IdentityRecord::count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
});
