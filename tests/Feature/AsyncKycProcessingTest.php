<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\ProcessPendingKyc;
use App\Application\Kyc\RejectKycAction;
use App\Application\Kyc\RetryKycProcessing;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Media\DirectImageUploads;
use App\Application\Media\OssSettings;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Kyc\Enums\KycReviewReason;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Media\OssConfiguration;
use App\Domain\Media\StoredImage;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Storage\OssImages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    $this->freezeTime();
    Storage::fake('private');
    Http::preventStrayRequests();
    config(['media.storage' => 'oss']);
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->where('email', 'user@a.localhost')->firstOrFail();
    $this->admin = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $configuration = app(OssSettings::class)->save(['region' => 'cn-beijing', 'bucket' => 'test-images', 'endpoint' => 'https://images.example.test',
        'public_url' => 'https://images.example.test', 'access_key_id' => 'synthetic-key', 'access_key_secret' => 'synthetic-secret'], $this->admin);
    app(OssSettings::class)->activate($configuration, $this->admin);
    PlatformKycSetting::current()->update(['enabled' => true, 'review_mode' => 'AUTOMATIC']);
    $this->oss = new class extends OssImages {
        public array $objects = [];
        public bool $offline = false;
        public int $puts = 0;
        public function put(OssConfiguration $config, string $key, string $bytes, string $mime): void {
            $this->puts++;
            if ($this->offline) throw new RuntimeException('Synthetic timeout');
            $this->objects[$key] = $bytes;
        }
        public function getBounded(OssConfiguration $config, string $key, int $maxBytes): string {
            if ($this->offline) throw new RuntimeException('Synthetic timeout');
            return substr($this->objects[$key], 0, $maxBytes + 1);
        }
    };
    app()->instance(OssImages::class, $this->oss);
});

function queuedKyc($test): KycApplication
{
    $uploads = app(DirectImageUploads::class);
    $files = [];
    foreach (['front', 'back'] as $side) {
        $ticket = $uploads->authorize($test->tenant->id, $test->user->id, 'kyc', $side, 'image/png');
        expect($ticket['mode'])->toBe('server')->and($ticket)->not->toHaveKey('fields');
        $uploads->backup($test->tenant->id, $test->user->id, $ticket['id'], kycTestImage()->getContent());
        $uploads->complete($test->tenant->id, $test->user->id, $ticket['id']);
        $files[$side] = $uploads->resolve($test->tenant->id, $test->user->id, $ticket['id'], 'kyc', $side);
    }
    $test->files = $files;
    return app(SubmitKycApplicationAction::class)->execute($test->tenant, $test->user, 'CN', '', $files['front'], $files['back'], (string) Str::uuid());
}

function asyncOcrOnce(): void
{
    $provider = Mockery::mock(KycOcrProviderInterface::class);
    $provider->shouldReceive('name')->andReturn('TEST');
    $provider->shouldReceive('extractIdentityDocument')->once()->with(Mockery::on(fn ($request) => $request->backUrl === '' && str_starts_with($request->frontUrl, 'http://images.example.test/')))
        ->andReturn(new KycOcrResultDTO(KycOcrOutcome::Success, '11010519491231002X'));
    app()->instance(KycOcrProviderInterface::class, $provider);
}

it('accepts both retained originals without OSS or OCR and finishes after a background retry', function () {
    $this->oss->offline = true;
    asyncOcrOnce();
    $application = queuedKyc($this);
    expect($application->processing_status)->toBe('QUEUED')->and($application->identity_hash)->toBeNull()->and($this->oss->puts)->toBe(0);
    $again = app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', '', $this->files['front'], $this->files['back'], (string) Str::uuid());
    expect($again->id)->toBe($application->id)->and(KycApplication::count())->toBe(1);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($application->fresh()->processing_status)->toBe('QUEUED')->and(IdentityRecord::count())->toBe(0);
    $this->oss->offline = false;
    $this->travel(61)->seconds();
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($application->fresh()->review_status->value)->toBe('APPROVED')->and($application->fresh()->processing_status)->toBe('COMPLETE');
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect(IdentityRecord::count())->toBe(1)->and(DB::table('inbox_events')->where('template', 'kyc_approved')->count())->toBe(1);
    Http::assertNothingSent();
});

it('retains failed originals sends one terminal notice and deduplicates administrator retry', function () {
    $this->oss->offline = true;
    asyncOcrOnce();
    $application = queuedKyc($this);
    foreach (range(1, 3) as $attempt) {
        app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
        $this->travel(181)->seconds();
    }
    expect($application->fresh()->processing_status)->toBe('FAILED')->and(StoredImage::where('business_reference', $application->id)->where('state', 'ready')->count())->toBe(2);
    expect(DB::table('inbox_events')->where('template', 'kyc_processing_failed')->count())->toBe(1);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    $request = (string) Str::uuid();
    $retry = app(RetryKycProcessing::class);
    $retry->execute($this->tenant->id, $application->id, $this->admin, $request, 'Retry after storage repair');
    $retry->execute($this->tenant->id, $application->id, $this->admin, $request, 'Retry after storage repair');
    expect($application->fresh()->processing_generation)->toBe(2)->and(DB::table('kyc_processing_retries')->count())->toBe(1);
    $this->oss->offline = false;
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($application->fresh()->review_status->value)->toBe('APPROVED')->and(DB::table('inbox_events')->count())->toBe(2);
});

it('uses manual review and keeps a rejected attempt immutable when reopening its retained documents', function () {
    PlatformKycSetting::current()->update(['review_mode' => 'MANUAL']);
    asyncOcrOnce();
    $application = queuedKyc($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($application->fresh()->processing_status)->toBe('WAITING_REVIEW')->and(IdentityRecord::count())->toBe(0);
    app(RejectKycAction::class)->execute($this->tenant->id, $application->id, $this->admin, KycReviewReason::Other, 'Please review these documents');
    $this->travel(1)->seconds();
    $new = app(RetryKycProcessing::class)->execute($this->tenant->id, $application->id, $this->admin, (string) Str::uuid(), 'Review retained documents');
    expect($new->id)->not->toBe($application->id)->and($new->front_object_key)->toBe($application->front_object_key)
        ->and($application->fresh()->review_status->value)->toBe('REJECTED');
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $new->id);
    app(ApproveKycAction::class)->execute($this->tenant->id, $new->id, $this->admin);
    expect(DB::table('inbox_events')->where('template', 'kyc_approved')->count())->toBe(1)
        ->and(DB::table('inbox_events')->where('template', 'kyc_rejected')->count())->toBe(1);
});

it('does not recognize corrupt replicas or other company submissions', function () {
    $provider = Mockery::mock(KycOcrProviderInterface::class);
    $provider->shouldNotReceive('extractIdentityDocument');
    app()->instance(KycOcrProviderInterface::class, $provider);
    $application = queuedKyc($this);
    $image = StoredImage::where('business_reference', $application->id)->firstOrFail();
    Storage::disk('private')->put($image->backup_key, 'corrupted');
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($application->fresh()->review_status->value)->toBe('PENDING')->and(IdentityRecord::count())->toBe(0);
    $other = Tenant::where('id', '<>', $this->tenant->id)->firstOrFail();
    expect(fn () => app(RetryKycProcessing::class)->execute($other->id, $application->id, $this->admin, (string) Str::uuid(), 'Wrong company'))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('retains unreadable OCR evidence as a failed submission and succeeds only after an explicit retry', function () {
    $provider = Mockery::mock(KycOcrProviderInterface::class);
    $provider->shouldReceive('extractIdentityDocument')->once()->andReturn(new KycOcrResultDTO(KycOcrOutcome::Failed));
    app()->instance(KycOcrProviderInterface::class, $provider);
    $application = queuedKyc($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($application->fresh()->processing_status)->toBe('FAILED')->and($application->fresh()->identity_hash)->toBeNull()
        ->and(KycApplication::count())->toBe(1)->and(DB::table('inbox_events')->where('template', 'kyc_processing_failed')->count())->toBe(1);
    app(RetryKycProcessing::class)->execute($this->tenant->id, $application->id, $this->admin, (string) Str::uuid(), 'Retry retained originals');
    asyncOcrOnce();
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($application->fresh()->review_status->value)->toBe('APPROVED')->and(IdentityRecord::count())->toBe(1);
});

it('rejects retries without reviewer permission and rejects changed retry intent', function () {
    $this->oss->offline = true;
    $application = queuedKyc($this);
    $application->forceFill(['processing_status' => 'FAILED', 'next_processing_at' => null])->save();
    $tenantAdmin = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    expect(fn () => app(RetryKycProcessing::class)->execute($this->tenant->id, $application->id, $tenantAdmin, (string) Str::uuid(), 'Unauthorized retry'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $request = (string) Str::uuid();
    app(RetryKycProcessing::class)->execute($this->tenant->id, $application->id, $this->admin, $request, 'Original reason');
    expect(fn () => app(RetryKycProcessing::class)->execute($this->tenant->id, $application->id, $this->admin, $request, 'Changed reason'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('retains failed history while accepting new documents and blocks retrying the superseded attempt', function () {
    $old = queuedKyc($this);
    $old->forceFill(['processing_status' => 'FAILED', 'processing_error' => 'KYC_PROCESSING_UNAVAILABLE', 'next_processing_at' => null])->save();
    $original = $old->fresh()->getAttributes();
    $this->travel(1)->seconds();
    $new = queuedKyc($this);
    expect($new->id)->not->toBe($old->id)
        ->and($new->resubmission_of_id)->toBe($old->id)
        ->and($old->fresh()->getAttributes())->toBe($original);
    expect(fn () => app(RetryKycProcessing::class)->execute($this->tenant->id, $old->id, $this->admin, (string) Str::uuid(), 'Stale retry'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => queuedKyc($this))->toThrow(\App\Support\Errors\DomainException::class);
    asyncOcrOnce();
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $new->id);
    expect($new->fresh()->review_status->value)->toBe('APPROVED')
        ->and($old->fresh()->getAttributes())->toBe($original);
});

it('allows new documents after rejection without changing the completed review', function () {
    PlatformKycSetting::current()->update(['review_mode' => 'MANUAL']);
    asyncOcrOnce();
    $old = queuedKyc($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $old->id);
    app(RejectKycAction::class)->execute($this->tenant->id, $old->id, $this->admin, KycReviewReason::Other, 'Please upload clearer documents');
    $original = $old->fresh()->getAttributes();
    $this->travel(1)->seconds();
    $new = queuedKyc($this);
    expect($new->resubmission_of_id)->toBe($old->id)
        ->and($old->fresh()->getAttributes())->toBe($original);
});

it('issues retained server tickets through the HTTP controller even with OSS selected', function () {
    $this->oss->offline = true;
    $base = 'http://a.localhost/api/mobile/v1';
    $token = $this->postJson($base.'/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password', 'device_name' => 'Offline test'])->assertCreated()->json('token');
    $this->withToken($token);
    foreach (['front', 'back'] as $side) {
        $ticket = $this->postJson($base.'/images/direct', ['purpose' => 'kyc', 'field' => $side, 'mime' => 'image/png'])->assertOk()->json();
        expect($ticket['mode'])->toBe('server')->and($ticket)->not->toHaveKey('fields');
    }
    expect($this->oss->puts)->toBe(0);
    Http::assertNothingSent();
});

it('offers consumer resubmission only after failure and still respects the global KYC switch', function () {
    $application = queuedKyc($this);
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/kyc')
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('canSubmit', false));
    $application->forceFill(['processing_status' => 'FAILED', 'next_processing_at' => null])->save();
    $this->get('http://a.localhost/kyc')
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('canSubmit', true));
    PlatformKycSetting::current()->update(['enabled' => false]);
    $this->get('http://a.localhost/kyc')
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('canSubmit', false));
});

it('rejects legacy URL-only documents before creating an asynchronous application', function () {
    $front = new \App\Application\Media\DirectKycImage((string) Str::uuid(), $this->tenant->id, $this->user->id, 'front', 'https://images.example.test/front');
    $back = new \App\Application\Media\DirectKycImage((string) Str::uuid(), $this->tenant->id, $this->user->id, 'back', 'https://images.example.test/back');
    try {
        app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', '', $front, $back);
        $this->fail('URL-only documents must require re-upload.');
    } catch (\App\Support\Errors\DomainException $error) {
        expect($error->errorCode)->toBe('IMAGE_STORAGE_CHANGED');
    }
    expect(KycApplication::count())->toBe(0)->and($this->oss->puts)->toBe(0);
});

it('exposes only safe failure categories to the consumer and clears them while processing', function () {
    $application = queuedKyc($this);
    foreach (['IDENTITY_ACCOUNT_LIMIT_REACHED', 'KYC_OCR_UNAVAILABLE', 'KYC_OCR_MISMATCH'] as $code) {
        $application->forceFill(['processing_status' => 'FAILED', 'processing_error' => $code, 'next_processing_at' => null])->save();
        expect(app(\App\Application\Kyc\UserKycQuery::class)->get($this->tenant->id, $this->user->id)['processingError'])->toBe($code);
    }
    $application->forceFill(['processing_error' => 'INTERNAL_DIAGNOSTIC'])->save();
    expect(app(\App\Application\Kyc\UserKycQuery::class)->get($this->tenant->id, $this->user->id)['processingError'])->toBe('KYC_PROCESSING_UNAVAILABLE');
    $application->forceFill(['processing_status' => 'QUEUED'])->save();
    expect(app(\App\Application\Kyc\UserKycQuery::class)->get($this->tenant->id, $this->user->id)['processingError'])->toBeNull();
});

it('allows platform manual decisions before or after OCR failure without fabricating recognition', function (string $state, string $decision) {
    $application = queuedKyc($this);
    $ocr = $state === 'FAILED' ? 'FAILED' : 'NOT_STARTED';
    $application->forceFill(['processing_status' => $state, 'ocr_status' => $ocr,
        'processing_error' => $state === 'FAILED' ? 'KYC_OCR_UNAVAILABLE' : null])->save();
    $provider = Mockery::mock(KycOcrProviderInterface::class);
    $provider->shouldNotReceive('extractIdentityDocument');
    app()->instance(KycOcrProviderInterface::class, $provider);
    $this->actingAs($this->admin, 'platform_admin')->postJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/kyc/{$application->id}/review", [
        'decision' => $decision, 'identity_number' => $decision === 'approve' ? '11010519491231002x' : null,
        'reason_code' => 'OTHER', 'review_message' => 'Manual document review',
    ])->assertOk();
    $application->refresh();
    expect($application->review_status->value)->toBe($decision === 'approve' ? 'APPROVED' : 'REJECTED')
        ->and($application->reviewed_by_admin_user_id)->toBe($this->admin->id)
        ->and($application->automatically_approved)->toBeFalse()
        ->and($application->next_processing_at)->toBeNull()
        ->and($application->ocr_status->value)->toBe($ocr)
        ->and($application->ocr_result_encrypted)->toBeNull();
    if ($decision === 'approve') {
        $identity = IdentityRecord::sole();
        expect($identity->verification_basis)->toBe('MANUAL')->and($identity->identity_hash)->not->toBeNull()
            ->and($application->identity_number_source)->toBe('ADMIN')
            ->and(app(\App\Domain\Kyc\Services\IdentityNumberProtector::class)->decrypt($identity->identity_number_encrypted))->toBe('11010519491231002X');
        $view = app(\App\Application\Kyc\UserKycQuery::class)->get($this->tenant->id, $this->user->id);
        expect($view['status'])->toBe('APPROVED')->and($view['maskedIdentityNumber'])->toBe('**************002X')->and($view['verifiedAt'])->not->toBeNull();
        expect(app(\App\Application\Card\AccountCardholderMaterials::class)->requiresBirthDate($this->tenant->id, $this->user->id))->toBeFalse();
    } else {
        expect(IdentityRecord::count())->toBe(0);
    }
    $before = $application->getAttributes();
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($application->fresh()->getAttributes())->toBe($before);
    $this->postJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/kyc/{$application->id}/review", [
        'decision' => $decision, 'reason_code' => 'OTHER', 'review_message' => 'Repeated manual decision',
    ])->assertStatus(409);
    expect(DB::table('inbox_events')->where('template', $decision === 'approve' ? 'kyc_approved' : 'kyc_rejected')->count())->toBe(1);
    DB::statement('SET CONSTRAINTS manual_identity_source_check IMMEDIATE');
    Http::assertNothingSent();
})->with(['QUEUED', 'PROCESSING', 'FAILED'])->with(['approve', 'reject']);

it('keeps automatic approval closed without successful OCR', function () {
    $application = queuedKyc($this);
    expect(fn () => app(ApproveKycAction::class)->executeAutomatic($this->tenant->id, $application->id))
        ->toThrow(\App\Support\Errors\DomainException::class);
    expect($application->fresh()->review_status->value)->toBe('PENDING')->and(IdentityRecord::count())->toBe(0);
});

it('retains manual decisions made while OCR is in flight', function (string $decision) {
    $application = queuedKyc($this);
    $provider = Mockery::mock(KycOcrProviderInterface::class);
    $provider->shouldReceive('name')->andReturn('TEST');
    $provider->shouldReceive('extractIdentityDocument')->once()->andReturnUsing(function () use ($application, $decision) {
        if ($decision === 'approve') {
            app(ApproveKycAction::class)->execute($this->tenant->id, $application->id, $this->admin, null, '11010519491231002X');
        } else {
            app(RejectKycAction::class)->execute($this->tenant->id, $application->id, $this->admin, KycReviewReason::Other, 'Manual review while processing');
        }
        return new KycOcrResultDTO(KycOcrOutcome::Success, '11010519491231002X');
    });
    app()->instance(KycOcrProviderInterface::class, $provider);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    expect($application->fresh()->review_status->value)->toBe($decision === 'approve' ? 'APPROVED' : 'REJECTED')
        ->and($application->fresh()->identity_number_source)->toBe($decision === 'approve' ? 'ADMIN' : null)->and($application->fresh()->ocr_status->value)->toBe('NOT_STARTED')
        ->and($application->fresh()->next_processing_at)->toBeNull();
    DB::statement('SET CONSTRAINTS manual_identity_source_check IMMEDIATE');
})->with(['approve', 'reject']);

it('does not manually review superseded failures or bypass platform permissions and company scope', function () {
    $old = queuedKyc($this);
    $old->forceFill(['processing_status' => 'FAILED', 'next_processing_at' => null])->save();
    $this->travel(1)->seconds();
    $current = queuedKyc($this);
    foreach (['approve', 'reject'] as $decision) {
        $payload = ['decision' => $decision, 'reason_code' => 'OTHER', 'review_message' => 'Manual decision'];
        $this->actingAs($this->admin, 'platform_admin')->postJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/kyc/{$old->id}/review", $payload)->assertStatus(409);
        $other = Tenant::where('id', '<>', $this->tenant->id)->firstOrFail();
        $this->postJson("http://admin.localhost/platform/tenants/{$other->id}/kyc/{$current->id}/review", $payload)->assertNotFound();
        $unauthorized = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
        $this->actingAs($unauthorized, 'platform_admin')->postJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/kyc/{$current->id}/review", $payload)->assertForbidden();
    }
    expect(IdentityRecord::count())->toBe(0)->and($current->fresh()->review_status->value)->toBe('PENDING');
});

it('preserves known number limits for manual approval after successful recognition', function () {
    PlatformKycSetting::current()->update(['review_mode' => 'MANUAL', 'max_accounts_per_identity' => 1]);
    fakeMatchingKycOcr('11010519491231002X');
    $first = queuedKyc($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $first->id);
    app(ApproveKycAction::class)->execute($this->tenant->id, $first->id, $this->admin);
    $this->user = User::create(['tenant_id' => $this->tenant->id, 'email' => 'manual-duplicate@example.test',
        'password_hash' => \Illuminate\Support\Facades\Hash::make('synthetic-password'), 'status' => 'ACTIVE']);
    $second = queuedKyc($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $second->id);
    $this->actingAs($this->admin, 'platform_admin')->postJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/kyc/{$second->id}/review", ['decision' => 'approve'])
        ->assertStatus(409);
    expect($second->fresh()->review_status->value)->toBe('PENDING')->and(IdentityRecord::count())->toBe(1);
    DB::statement('SET CONSTRAINTS manual_identity_source_check IMMEDIATE');
});

it('keeps legacy OCR jobs from touching manually approved applications', function () {
    $application = queuedKyc($this);
    app(ApproveKycAction::class)->execute($this->tenant->id, $application->id, $this->admin, null, '11010519491231002X');
    $provider = Mockery::mock(KycOcrProviderInterface::class);
    $provider->shouldNotReceive('extractIdentityDocument');
    app()->instance(KycOcrProviderInterface::class, $provider);
    $before = $application->fresh()->getAttributes();
    $job = new \App\Jobs\ProcessKycOcrJob($this->tenant->id, $application->id);
    app()->call([$job, 'handle']);
    $job->failed(new RuntimeException('Late failure'));
    expect($application->fresh()->getAttributes())->toBe($before);
});

it('requires real manual approval provenance for a numberless identity at the database boundary', function () {
    $application = queuedKyc($this);
    expect(fn () => DB::transaction(function () use ($application) {
        DB::table('identity_records')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'source_kyc_application_id' => $application->id, 'document_type' => 'NATIONAL_ID', 'document_country' => 'CN',
            'identity_number_encrypted' => null, 'identity_hash' => null, 'verification_basis' => 'MANUAL',
            'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET CONSTRAINTS manual_identity_source_check IMMEDIATE');
    }))->toThrow(\Illuminate\Database\QueryException::class);
    expect(IdentityRecord::count())->toBe(0);
    expect(fn () => DB::transaction(fn () => DB::table('kyc_applications')->where('id', $application->id)
        ->update(['review_status' => 'APPROVED', 'automatically_approved' => true, 'reviewed_at' => now()])))
        ->toThrow(\Illuminate\Database\QueryException::class);
    expect($application->fresh()->review_status->value)->toBe('PENDING');
});

it('requires a valid missing national identity number for manual approval without changing OCR', function ($number) {
    $application = queuedKyc($this);
    $before = $application->fresh()->getAttributes();
    $this->actingAs($this->admin, 'platform_admin')->postJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/kyc/{$application->id}/review", [
        'decision' => 'approve', 'identity_number' => $number,
    ])->assertUnprocessable()->assertJsonValidationErrors('identity_number');
    expect($application->fresh()->getAttributes())->toBe($before)->and(IdentityRecord::count())->toBe(0);
    Http::assertNothingSent();
})->with([null, '', '110105194912310021', '11010519491331002X', 'not-a-number']);

it('uses existing recognized numbers without reentry and rejects attempted replacement', function () {
    PlatformKycSetting::current()->update(['review_mode' => 'MANUAL']);
    asyncOcrOnce();
    $application = queuedKyc($this);
    expect(app(\App\Application\Kyc\TenantKycQueueQuery::class)->detail($this->tenant->id, $application->id)['application']['requiresIdentityNumber'])->toBeTrue();
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $application->id);
    $before = $application->fresh()->identity_number_encrypted;
    expect(app(\App\Application\Kyc\TenantKycQueueQuery::class)->detail($this->tenant->id, $application->id)['application']['requiresIdentityNumber'])->toBeFalse();
    $url = "http://admin.localhost/platform/tenants/{$this->tenant->id}/kyc/{$application->id}/review";
    $this->actingAs($this->admin, 'platform_admin')->postJson($url, ['decision' => 'approve', 'identity_number' => '320311197707060018'])
        ->assertUnprocessable()->assertJsonValidationErrors('identity_number');
    $this->postJson($url, ['decision' => 'approve'])->assertOk();
    expect($application->fresh()->identity_number_encrypted)->toBe($before)->and($application->fresh()->identity_number_source)->toBe('OCR');
});

it('applies identity account limits atomically to administrator entered numbers', function () {
    PlatformKycSetting::current()->update(['max_accounts_per_identity' => 1]);
    $first = queuedKyc($this);
    app(ApproveKycAction::class)->execute($this->tenant->id, $first->id, $this->admin, null, '11010519491231002X');
    $this->user = User::create(['tenant_id' => $this->tenant->id, 'email' => 'manual-number-duplicate@example.test',
        'password_hash' => \Illuminate\Support\Facades\Hash::make('synthetic-password'), 'status' => 'ACTIVE']);
    $second = queuedKyc($this);
    $this->actingAs($this->admin, 'platform_admin')->postJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/kyc/{$second->id}/review", [
        'decision' => 'approve', 'identity_number' => '11010519491231002X',
    ])->assertStatus(409);
    expect($second->fresh()->identity_hash)->toBeNull()->and($second->fresh()->review_status->value)->toBe('PENDING');
    DB::statement('SET CONSTRAINTS manual_identity_source_check IMMEDIATE');
});

it('keeps database identity fills atomic with recognition or administrator approval', function () {
    $application = queuedKyc($this);
    $protected = app(\App\Domain\Kyc\Services\IdentityNumberProtector::class)->protect($this->tenant->id, 'NATIONAL_ID', 'CN', '11010519491231002X');
    foreach ([null, 'ADMIN'] as $source) {
        expect(fn () => DB::transaction(fn () => DB::table('kyc_applications')->where('id', $application->id)->update([
            'identity_hash' => $protected['hash'], 'identity_number_encrypted' => $protected['encrypted'], 'identity_number_source' => $source,
        ])))->toThrow(\Illuminate\Database\QueryException::class);
    }
    expect(fn () => DB::transaction(fn () => DB::table('kyc_applications')->where('id', $application->id)->update([
        'review_status' => 'APPROVED', 'reviewed_at' => now(), 'reviewed_by_admin_user_id' => $this->admin->id,
    ])))->toThrow(\Illuminate\Database\QueryException::class);
    app(ApproveKycAction::class)->execute($this->tenant->id, $application->id, $this->admin, null, '11010519491231002X');
    expect(fn () => DB::transaction(fn () => DB::table('kyc_applications')->where('id', $application->id)->update([
        'identity_number_source' => 'OCR',
    ])))->toThrow(\Illuminate\Database\QueryException::class);
    expect($application->fresh()->identity_number_source)->toBe('ADMIN')->and($application->fresh()->ocr_status->value)->toBe('NOT_STARTED');
});
