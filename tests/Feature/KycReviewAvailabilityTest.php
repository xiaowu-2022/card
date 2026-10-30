<?php

use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Kyc\KycReviewAvailability;
use App\Application\Media\ImageStorage;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-30T12:00:00+08:00'));
    Storage::fake('private');
    Queue::fake();
    Http::preventStrayRequests();
    config(['media.storage' => 'server', 'inertia.ssr.enabled' => false]);
    DB::table('media_storage_settings')->where('id', 1)->update(['storage_driver' => 'server']);
    PlatformKycSetting::current()->update(['enabled' => true, 'review_mode' => 'AUTOMATIC']);
    $this->tenant = Tenant::where('slug', 'tenant-a')->sole();
    $this->user = User::where('tenant_id', $this->tenant->id)->where('email', 'user@a.localhost')->sole();
    $this->admin = AdminUser::where('email', 'owner@platform.local')->sole();
    $this->actingAs($this->admin, 'platform_admin');
    $provider = Mockery::mock(KycOcrProviderInterface::class);
    $provider->shouldNotReceive('extractIdentityDocument');
    app()->instance(KycOcrProviderInterface::class, $provider);
});

function reviewAvailabilityApplication($test, bool $failed = true, bool $expiredBack = false, ?Tenant $tenant = null, ?User $user = null): KycApplication
{
    $tenant ??= $test->tenant;
    $user ??= $test->user;
    $application = app(SubmitKycApplicationAction::class)->execute($tenant, $user, 'CN', '',
        kycTestImage('synthetic-front.png'), kycTestImage('synthetic-back.png'), (string) Str::uuid());
    if ($expiredBack) {
        $application->forceFill(['processing_status' => 'PROCESSING'])->save();
        $image = app(ImageStorage::class)->record('private', $application->back_object_key);
        $application->forceFill(['back_ocr_result_encrypted' => app(KycDataCipher::class)->encrypt(json_encode([
            'version' => 1, 'document_type' => 'NATIONAL_ID', 'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'back_object_key' => $application->back_object_key, 'sha256' => $image->sha256, 'provider' => 'TEST',
            'valid_from' => '2020-01-01', 'valid_until' => '2026-10-29', 'long_term' => false,
        ], JSON_THROW_ON_ERROR))])->save();
    }
    if ($failed) {
        $application->forceFill(['processing_status' => 'FAILED', 'processing_error' => $expiredBack ? 'KYC_DOCUMENT_EXPIRED' : 'KYC_OCR_UNAVAILABLE',
            'ocr_status' => 'FAILED', 'next_processing_at' => null])->save();
    }

    return $application->refresh();
}

function reviewAvailabilityUrl(KycApplication $application): string
{
    return 'https://admin.localhost/platform/tenants/'.$application->tenant_id.'/kyc/'.$application->id;
}

function reviewAvailabilityPayload(string $decision): array
{
    return $decision === 'approve' ? ['decision' => 'approve', 'identity_number' => '11010519491231002X']
        : ['decision' => 'reject', 'reason_code' => 'OTHER', 'review_message' => 'Synthetic manual document review'];
}

function reviewAvailabilityFlags(KycApplication $application, KycApplication $current, bool $reviewable, bool $retryable): array
{
    return ['isSuperseded' => $application->id !== $current->id, 'currentApplicationId' => $current->id,
        'reviewable' => $reviewable, 'retryable' => $retryable];
}

it('allows manual approval of a current failed application with absent or expired back OCR', function (bool $expiredBack): void {
    $application = reviewAvailabilityApplication($this, expiredBack: $expiredBack);
    $url = reviewAvailabilityUrl($application);
    $evidence = $application->only(['front_object_key', 'back_object_key', 'back_ocr_result_encrypted', 'ocr_status', 'ocr_result_encrypted']);
    $read = $this->getJson($url)->assertOk()->assertJsonPath('canReview', true);
    foreach (reviewAvailabilityFlags($application, $application, true, true) as $key => $value) $read->assertJsonPath('application.'.$key, $value);
    $this->postJson($url.'/review', reviewAvailabilityPayload('approve'))->assertOk()->assertJsonPath('reviewed', true);
    expect($application->fresh()->review_status->value)->toBe('APPROVED')
        ->and($application->fresh()->automatically_approved)->toBeFalse()
        ->and($application->fresh()->reviewed_by_admin_user_id)->toBe($this->admin->id)
        ->and($application->fresh()->only(array_keys($evidence)))->toBe($evidence)
        ->and(IdentityRecord::sole()->verification_basis)->toBe('MANUAL')
        ->and(IdentityRecord::sole()->source_kyc_application_id)->toBe($application->id);
    $this->getJson($url)->assertOk()->assertJsonPath('application.reviewable', false)->assertJsonPath('application.retryable', false);
    Http::assertNothingSent();
})->with(['no back OCR' => false, 'expired back OCR' => true]);

it('allows manual rejection of a current failed application and advertises only an explicit retry afterwards', function (): void {
    $application = reviewAvailabilityApplication($this);
    $this->postJson(reviewAvailabilityUrl($application).'/review', reviewAvailabilityPayload('reject'))->assertOk();
    $response = $this->getJson(reviewAvailabilityUrl($application))->assertOk()->assertJsonPath('canReview', true)
        ->assertJsonPath('application.reviewStatus', 'REJECTED');
    foreach (reviewAvailabilityFlags($application, $application, false, true) as $key => $value) $response->assertJsonPath('application.'.$key, $value);
    expect(IdentityRecord::count())->toBe(0)->and($application->fresh()->ocr_result_encrypted)->toBeNull();
    Http::assertNothingSent();
});

it('rejects a stale opened review or retry after a new linked submission without deciding the new application', function (string $action): void {
    $old = reviewAvailabilityApplication($this, expiredBack: true);
    $this->getJson(reviewAvailabilityUrl($old))->assertOk()->assertJsonPath('application.reviewable', true);
    $oldEvidence = $old->getAttributes();
    $this->travel(1)->seconds();
    $current = reviewAvailabilityApplication($this, failed: false);
    expect($current->resubmission_of_id)->toBe($old->id);
    $currentEvidence = $current->getAttributes();
    $response = $action === 'retry'
        ? $this->postJson(reviewAvailabilityUrl($old).'/retry', ['request_id' => (string) Str::uuid(), 'reason' => 'Retry old retained originals'])
        : $this->postJson(reviewAvailabilityUrl($old).'/review', reviewAvailabilityPayload($action));
    $response->assertConflict()->assertJsonPath('error.code', 'KYC_APPLICATION_SUPERSEDED')
        ->assertJsonPath('error.details.currentApplicationId', $current->id);
    expect(Str::isUuid($response->json('error.request_id')))->toBeTrue()
        ->and($old->fresh()->getAttributes())->toBe($oldEvidence)
        ->and($current->fresh()->getAttributes())->toBe($currentEvidence)
        ->and(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(2)
        ->and(DB::table('kyc_processing_retries')->count())->toBe(0);
    $this->getJson(reviewAvailabilityUrl($old))->assertOk()->assertJsonPath('application.isSuperseded', true)
        ->assertJsonPath('application.currentApplicationId', $current->id)->assertJsonPath('application.reviewable', false)->assertJsonPath('application.retryable', false);
    Http::assertNothingSent();
})->with(['approve', 'reject', 'retry']);

it('allows manual decisions on the current failed child and retains the superseded parent unchanged', function (string $decision): void {
    $old = reviewAvailabilityApplication($this);
    $before = $old->getAttributes();
    $this->travel(1)->seconds();
    $current = reviewAvailabilityApplication($this);
    $this->getJson(reviewAvailabilityUrl($current))->assertOk()->assertJsonPath('application.isSuperseded', false)
        ->assertJsonPath('application.currentApplicationId', $current->id)->assertJsonPath('application.reviewable', true)->assertJsonPath('application.retryable', true);
    $this->postJson(reviewAvailabilityUrl($current).'/review', reviewAvailabilityPayload($decision))->assertOk();
    expect($current->fresh()->review_status->value)->toBe($decision === 'approve' ? 'APPROVED' : 'REJECTED')
        ->and($old->fresh()->getAttributes())->toBe($before)
        ->and(IdentityRecord::count())->toBe($decision === 'approve' ? 1 : 0);
    if ($decision === 'approve') expect(IdentityRecord::sole()->source_kyc_application_id)->toBe($current->id);
    Http::assertNothingSent();
})->with(['approve', 'reject']);

it('shares leaf availability across the platform list detail and paginated user history without read side effects', function (): void {
    $old = reviewAvailabilityApplication($this);
    $this->travel(1)->seconds();
    $middle = reviewAvailabilityApplication($this);
    $this->travel(1)->seconds();
    $leaf = reviewAvailabilityApplication($this);
    $before = collect(['kyc_applications', 'identity_records', 'wallets', 'ledger_entries', 'audit_logs', 'kyc_processing_retries'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
    $this->get('https://admin.localhost/platform/kyc?company='.$this->tenant->id.'&search='.$this->user->id.'&status=FAILED')
        ->assertOk()->assertInertia(function ($page) use ($old, $middle, $leaf): void {
            $page->where('applications.total', 3);
            foreach ([$leaf, $middle, $old] as $index => $application) {
                $page->where('applications.data.'.$index.'.id', $application->id);
                foreach (reviewAvailabilityFlags($application, $leaf, $application->id === $leaf->id, $application->id === $leaf->id) as $key => $value) {
                    $page->where('applications.data.'.$index.'.'.$key, $value);
                }
            }
        });
    $drawer = 'https://admin.localhost/platform/tenants/'.$this->tenant->id.'/users/'.$this->user->id.'/kyc';
    $response = $this->getJson($drawer.'?application='.$old->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('canReview', true)->assertJsonPath('application.id', $old->id)->assertJsonPath('applications.total', 3);
    foreach (reviewAvailabilityFlags($old, $leaf, false, false) as $key => $value) $response->assertJsonPath('application.'.$key, $value);
    foreach ([$leaf, $middle, $old] as $index => $application) {
        $response->assertJsonPath('applications.items.'.$index.'.id', $application->id);
        foreach (reviewAvailabilityFlags($application, $leaf, $application->id === $leaf->id, $application->id === $leaf->id) as $key => $value) {
            $response->assertJsonPath('applications.items.'.$index.'.'.$key, $value);
        }
    }
    $this->getJson(reviewAvailabilityUrl($middle))->assertOk()->assertJsonPath('application.currentApplicationId', $leaf->id)
        ->assertJsonPath('application.isSuperseded', true)->assertJsonMissingPath('application.front_object_key')
        ->assertJsonMissingPath('application.back_ocr_result_encrypted')->assertDontSee('11010519491231002X');
    foreach ($before as $table => $count) expect(DB::table($table)->count())->toBe($count);
    Http::assertNothingSent();
});

it('keeps current application IDs scoped to the selected company and user across independent chains', function (): void {
    $old = reviewAvailabilityApplication($this);
    $this->travel(1)->seconds();
    $ownLeaf = reviewAvailabilityApplication($this);
    $otherUser = User::create(['tenant_id' => $this->tenant->id, 'email' => 'availability-other@example.test',
        'password_hash' => Hash::make('local-password'), 'status' => 'ACTIVE']);
    $this->travel(1)->seconds();
    $sameCompany = reviewAvailabilityApplication($this, user: $otherUser);
    $otherTenant = Tenant::where('slug', 'tenant-b')->sole();
    $otherTenantUser = User::where('tenant_id', $otherTenant->id)->where('email', 'user@b.localhost')->sole();
    $this->travel(1)->seconds();
    $otherCompany = reviewAvailabilityApplication($this, tenant: $otherTenant, user: $otherTenantUser);
    $this->getJson(reviewAvailabilityUrl($old))->assertOk()->assertJsonPath('application.currentApplicationId', $ownLeaf->id);
    $this->getJson(reviewAvailabilityUrl($sameCompany))->assertOk()->assertJsonPath('application.currentApplicationId', $sameCompany->id);
    $this->getJson(reviewAvailabilityUrl($otherCompany))->assertOk()->assertJsonPath('application.currentApplicationId', $otherCompany->id);
    $drawer = 'https://admin.localhost/platform/tenants/'.$this->tenant->id.'/users/'.$this->user->id.'/kyc';
    $this->getJson($drawer.'?application='.$sameCompany->id)->assertNotFound();
    $this->getJson($drawer.'?application='.$otherCompany->id)->assertNotFound();
    $wrongCompany = str_replace($this->tenant->id, $otherTenant->id, reviewAvailabilityUrl($old));
    $this->getJson($wrongCompany)->assertNotFound();
    $this->postJson($wrongCompany.'/review', reviewAvailabilityPayload('approve'))->assertNotFound();
    $this->postJson(reviewAvailabilityUrl($old).'/review', reviewAvailabilityPayload('approve'))->assertConflict()
        ->assertJsonPath('error.code', 'KYC_APPLICATION_SUPERSEDED')->assertJsonPath('error.details.currentApplicationId', $ownLeaf->id);
    expect(IdentityRecord::count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps terminal rejection immutable and requires explicit retry to open a linked review', function (): void {
    $old = reviewAvailabilityApplication($this, expiredBack: true);
    $this->postJson(reviewAvailabilityUrl($old).'/review', reviewAvailabilityPayload('reject'))->assertOk();
    $before = $old->fresh()->getAttributes();
    foreach (['approve', 'reject'] as $decision) {
        $this->postJson(reviewAvailabilityUrl($old).'/review', reviewAvailabilityPayload($decision))->assertConflict()
            ->assertJsonPath('error.code', 'KYC_ALREADY_REVIEWED');
    }
    $requestId = (string) Str::uuid();
    $this->travel(1)->seconds();
    $response = $this->postJson(reviewAvailabilityUrl($old).'/retry', ['request_id' => $requestId, 'reason' => 'Explicitly review retained originals'])
        ->assertStatus(202)->assertJsonPath('processingStatus', 'QUEUED');
    $leaf = KycApplication::findOrFail($response->json('applicationId'));
    expect($leaf->id)->not->toBe($old->id)->and($leaf->resubmission_of_id)->toBe($old->id)
        ->and($leaf->back_ocr_result_encrypted)->toBe($old->back_ocr_result_encrypted)
        ->and($old->fresh()->getAttributes())->toBe($before)->and(IdentityRecord::count())->toBe(0);
    $this->getJson(reviewAvailabilityUrl($old))->assertOk()->assertJsonPath('application.isSuperseded', true)
        ->assertJsonPath('application.currentApplicationId', $leaf->id)->assertJsonPath('application.reviewable', false)->assertJsonPath('application.retryable', false);
    $this->getJson(reviewAvailabilityUrl($leaf))->assertOk()->assertJsonPath('application.isSuperseded', false)
        ->assertJsonPath('application.reviewable', true)->assertJsonPath('application.retryable', false);
    $this->postJson(reviewAvailabilityUrl($old).'/retry', ['request_id' => $requestId, 'reason' => 'Explicitly review retained originals'])
        ->assertStatus(202)->assertJsonPath('applicationId', $leaf->id);
    expect(KycApplication::count())->toBe(2)->and(DB::table('kyc_processing_retries')->count())->toBe(1);
    Http::assertNothingSent();
});

it('preserves the independent review permission gate even when the application is eligible', function (): void {
    $application = reviewAvailabilityApplication($this);
    $before = $application->getAttributes();
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'kyc.review')->value('id'))->delete();
    $this->actingAs($this->admin->fresh(), 'platform_admin');
    $this->getJson(reviewAvailabilityUrl($application))->assertOk()->assertJsonPath('canReview', false)
        ->assertJsonPath('application.reviewable', true)->assertJsonPath('application.retryable', true);
    $this->postJson(reviewAvailabilityUrl($application).'/review', reviewAvailabilityPayload('approve'))->assertForbidden();
    $this->postJson(reviewAvailabilityUrl($application).'/retry', ['request_id' => (string) Str::uuid(), 'reason' => 'Unauthorized manual retry'])->assertForbidden();
    expect($application->fresh()->getAttributes())->toBe($before)->and(IdentityRecord::count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps the current leaf retryable when several failed generations share the same submission second', function (): void {
    $old = reviewAvailabilityApplication($this);
    $linked = function (KycApplication $parent, string $id): KycApplication {
        $application = new KycApplication;
        $application->forceFill($parent->only(['tenant_id', 'user_id', 'document_type', 'document_country', 'front_object_key', 'back_object_key']));
        $application->forceFill(['id' => $id, 'resubmission_of_id' => $parent->id, 'review_status' => 'PENDING', 'ocr_status' => 'FAILED',
            'processing_status' => 'FAILED', 'processing_error' => 'KYC_OCR_UNAVAILABLE', 'processing_generation' => 1,
            'processing_attempts' => 3, 'submitted_at' => now(), 'next_processing_at' => null])->save();

        return $application->refresh();
    };
    $middle = $linked($old, 'ffffffff-ffff-4fff-bfff-fffffffffff1');
    $leaf = $linked($middle, '00000000-0000-4000-8000-000000000001');
    expect($leaf->submitted_at->equalTo($old->submitted_at))->toBeTrue()
        ->and($middle->submitted_at->equalTo($old->submitted_at))->toBeTrue();
    $snapshots = [$old->id => $old->getAttributes(), $middle->id => $middle->getAttributes()];
    $this->getJson(reviewAvailabilityUrl($leaf))->assertOk()->assertJsonPath('application.retryable', true)
        ->assertJsonPath('application.currentApplicationId', $leaf->id);
    foreach ([$old, $middle] as $ancestor) {
        $this->postJson(reviewAvailabilityUrl($ancestor).'/retry', ['request_id' => (string) Str::uuid(), 'reason' => 'Do not revive the ancestor'])
            ->assertConflict()->assertJsonPath('error.code', 'KYC_APPLICATION_SUPERSEDED')
            ->assertJsonPath('error.details.currentApplicationId', $leaf->id);
    }
    $this->postJson(reviewAvailabilityUrl($leaf).'/retry', ['request_id' => (string) Str::uuid(), 'reason' => 'Retry the current failed leaf'])
        ->assertStatus(202)->assertJsonPath('applicationId', $leaf->id)->assertJsonPath('processingStatus', 'QUEUED');
    expect($leaf->fresh()->processing_generation)->toBe(2)->and(DB::table('kyc_processing_retries')->count())->toBe(1);
    foreach ([$old, $middle] as $ancestor) expect($ancestor->fresh()->getAttributes())->toBe($snapshots[$ancestor->id]);
    Http::assertNothingSent();
});

it('uses database review state when a caller supplies stale pending availability inputs', function (): void {
    $application = reviewAvailabilityApplication($this);
    $this->postJson(reviewAvailabilityUrl($application).'/review', reviewAvailabilityPayload('approve'))->assertOk();
    $flags = app(KycReviewAvailability::class)->forApplication($application->tenant_id, $application->user_id, $application->id, 'PENDING', 'FAILED');
    expect($flags)->toBe(reviewAvailabilityFlags($application, $application, false, false));
    Http::assertNothingSent();
});
