<?php

use App\Application\Kyc\SubmitKycApplicationAction;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Kyc\Enums\KycOcrStatus;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Infrastructure\Providers\Kyc\MockKycOcrProvider;
use App\Jobs\ProcessKycOcrJob;
use Illuminate\Support\Facades\Crypt;
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
    (new ProcessKycOcrJob($this->tenant->id, $this->application->id))->handle(app(TenantContext::class), $provider);
    $fresh = $this->application->fresh();

    expect($provider->transactionLevel)->toBe($outerTransactionLevel)
        ->and($fresh->ocr_status)->toBe(KycOcrStatus::Succeeded)
        ->and($fresh->review_status)->toBe(KycReviewStatus::Pending)
        ->and($fresh->ocr_result_encrypted)->not->toContain('OCR-1234')
        ->and(json_decode(Crypt::decryptString($fresh->ocr_result_encrypted), true)['candidate_identity_number'])->toBe('OCR-1234')
        ->and(app(TenantContext::class)->hasTenant())->toBeFalse();
});

it('marks OCR failure without rejecting or approving KYC', function (): void {
    (new ProcessKycOcrJob($this->tenant->id, $this->application->id))->handle(app(TenantContext::class), new MockKycOcrProvider('FAILED'));
    expect($this->application->fresh()->ocr_status)->toBe(KycOcrStatus::Failed)
        ->and($this->application->fresh()->review_status)->toBe(KycReviewStatus::Pending);
});

it('is idempotent after OCR success and carries no image or identity payload', function (): void {
    $job = new ProcessKycOcrJob($this->tenant->id, $this->application->id);
    $provider = new MockKycOcrProvider('SUCCESS');
    $job->handle(app(TenantContext::class), $provider);
    $first = $this->application->fresh()->ocr_result_encrypted;
    $job->handle(app(TenantContext::class), $provider);

    expect($this->application->fresh()->ocr_result_encrypted)->toBe($first)
        ->and(serialize($job))->not->toContain('OCR-1234', 'front.jpg', 'back.jpg')
        ->and(KycApplication::query()->count())->toBe(1);
});
