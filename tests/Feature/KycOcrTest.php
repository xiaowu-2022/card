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
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\IdentityNumberNormalizer;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Infrastructure\Providers\Kyc\MockKycOcrProvider;
use App\Jobs\ProcessKycOcrJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->application = app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'MY', 'OCR-1234', kycTestImage('front.jpg'), kycTestImage('back.jpg'));
});

function runPhaseThreeOcr(ProcessKycOcrJob $job, KycOcrProviderInterface $provider): void
{
    $job->handle(
        app(TenantContext::class),
        $provider,
        app(IdentityNumberNormalizer::class),
        app(IdentityNumberProtector::class),
        app(KycDataCipher::class),
    );
}

it('runs OCR from an explicit tenant-scoped job outside a database transaction', function (): void {
    $provider = new class implements KycOcrProviderInterface
    {
        public int $transactionLevel = -1;

        public function name(): string
        {
            return 'boundary-test';
        }

        public function extractIdentityDocument(KycOcrRequestDTO $request): KycOcrResultDTO
        {
            $this->transactionLevel = DB::transactionLevel();

            return new KycOcrResultDTO(KycOcrOutcome::Success, 'OCR-1234', 'TEST PERSON', '0.98', 'SAFE-REF');
        }
    };
    $outerTransactionLevel = DB::transactionLevel();
    runPhaseThreeOcr(new ProcessKycOcrJob($this->tenant->id, $this->application->id), $provider);
    $fresh = $this->application->fresh();

    expect($provider->transactionLevel)->toBe($outerTransactionLevel)
        ->and($fresh->ocr_status)->toBe(KycOcrStatus::Succeeded)
        ->and($fresh->review_status)->toBe(KycReviewStatus::Pending)
        ->and($fresh->ocr_result_encrypted)->not->toContain('OCR-1234')
        ->and(json_decode(app(KycDataCipher::class)->decrypt($fresh->ocr_result_encrypted), true)['candidate_identity_match'])->toBe('MATCH')
        ->and(app(TenantContext::class)->hasTenant())->toBeFalse();
});

it('marks OCR failure without rejecting or approving KYC', function (): void {
    runPhaseThreeOcr(new ProcessKycOcrJob($this->tenant->id, $this->application->id), new MockKycOcrProvider('FAILED'));
    expect($this->application->fresh()->ocr_status)->toBe(KycOcrStatus::Failed)
        ->and($this->application->fresh()->review_status)->toBe(KycReviewStatus::Pending);
});

it('is idempotent after OCR success and carries no image or identity payload', function (): void {
    $job = new ProcessKycOcrJob($this->tenant->id, $this->application->id);
    $provider = new MockKycOcrProvider('SUCCESS');
    runPhaseThreeOcr($job, $provider);
    $first = $this->application->fresh()->ocr_result_encrypted;
    runPhaseThreeOcr($job, $provider);

    expect($this->application->fresh()->ocr_result_encrypted)->toBe($first)
        ->and(serialize($job))->not->toContain('OCR-1234', 'front.jpg', 'back.jpg')
        ->and(KycApplication::query()->count())->toBe(1);
});

it('recovers from a worker exception and marks only exhausted jobs failed', function (): void {
    $job = new ProcessKycOcrJob($this->tenant->id, $this->application->id);
    $timeout = new MockKycOcrProvider('TIMEOUT');
    try {
        runPhaseThreeOcr($job, $timeout);
        $this->fail('Expected the provider timeout to be retried by the queue.');
    } catch (RuntimeException $exception) {
        expect($this->application->fresh()->ocr_status)->toBe(KycOcrStatus::Processing);
        runPhaseThreeOcr($job, new MockKycOcrProvider('SUCCESS'));
        expect($this->application->fresh()->ocr_status)->toBe(KycOcrStatus::Succeeded)
            ->and($this->application->fresh()->review_status)->toBe(KycReviewStatus::Pending);
    }

    $this->application->forceFill(['ocr_status' => KycOcrStatus::Processing])->save();
    $job->failed(new RuntimeException('safe failure'));
    expect($this->application->fresh()->ocr_status)->toBe(KycOcrStatus::Failed)
        ->and($this->application->fresh()->review_status)->toBe(KycReviewStatus::Pending);
});

it('allows late OCR metadata without changing a terminal review result', function (): void {
    $reviewer = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    app(ApproveKycAction::class)->execute($this->tenant->id, $this->application->id, $reviewer);
    runPhaseThreeOcr(new ProcessKycOcrJob($this->tenant->id, $this->application->id), new MockKycOcrProvider('SUCCESS'));

    expect($this->application->fresh()->review_status)->toBe(KycReviewStatus::Approved)
        ->and($this->application->fresh()->ocr_status)->toBe(KycOcrStatus::Succeeded);
});

it('minimizes and validates untrusted OCR output before encrypted persistence', function (): void {
    $provider = new class implements KycOcrProviderInterface
    {
        public function name(): string
        {
            return 'untrusted-test';
        }

        public function extractIdentityDocument(KycOcrRequestDTO $request): KycOcrResultDTO
        {
            return new KycOcrResultDTO(KycOcrOutcome::Success, '<script>different</script>', '<script>alert(1)</script>', '999', str_repeat('R', 500));
        }
    };
    runPhaseThreeOcr(new ProcessKycOcrJob($this->tenant->id, $this->application->id), $provider);
    $fresh = $this->application->fresh();
    $payload = json_decode(app(KycDataCipher::class)->decrypt($fresh->ocr_result_encrypted), true);

    expect($payload)->toBe(['candidate_identity_match' => 'UNKNOWN', 'candidate_name' => 'alert(1)', 'confidence' => null])
        ->and($fresh->ocr_reference)->toHaveLength(255)
        ->and($fresh->ocr_result_encrypted)->not->toContain('<script>', 'different');
});
