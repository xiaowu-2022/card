<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\ProcessPendingKyc;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Kyc\UserKycQuery;
use App\Application\Media\DirectImageUploads;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->withoutVite();
    $this->freezeTime();
    Storage::fake('private');
    Queue::fake();
    Http::preventStrayRequests();
    config(['media.storage' => 'server', 'inertia.ssr.enabled' => false]);
    DB::table('media_storage_settings')->where('id', 1)->update(['storage_driver' => 'server']);
    PlatformKycSetting::current()->update(['enabled' => true, 'review_mode' => 'MANUAL']);
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->where('email', 'user@a.localhost')->firstOrFail();
    $this->admin = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
});

function reverifySubmit($test, bool $reverify = false, ?array $files = null): KycApplication
{
    return app(SubmitKycApplicationAction::class)->execute($test->tenant, $test->user, 'CN', '',
        $files['front'] ?? kycTestImage('front.png'), $files['back'] ?? kycTestImage('back.png'),
        (string) Str::uuid(), reverify: $reverify);
}

function platformVerifiedKycFixture($test): void
{
    // Synthetic, scoped provenance only; no account creation or financial actions.
    DB::table('platform_user_creations')->insert([
        'tenant_id' => $test->tenant->id, 'user_id' => $test->user->id, 'actor_id' => $test->admin->id,
        'request_id' => (string) Str::uuid(), 'verified_at' => now(),
    ]);
}

function reverifyRetainedUploads($test): array
{
    $uploads = app(DirectImageUploads::class);
    $files = [];
    foreach (['front', 'back'] as $side) {
        $ticket = $uploads->authorize($test->tenant->id, $test->user->id, 'kyc', $side, 'image/png');
        $uploads->backup($test->tenant->id, $test->user->id, $ticket['id'], kycTestImage()->getContent());
        $uploads->complete($test->tenant->id, $test->user->id, $ticket['id']);
        $files[$side] = $uploads->resolve($test->tenant->id, $test->user->id, $ticket['id'], 'kyc', $side);
    }

    return $files;
}

it('retains the old identity until new asynchronous documents are approved then rebinds with history and audit', function (): void {
    fakeMatchingKycOcr('11010519491231002X');
    $old = reverifySubmit($this);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $old->id);
    expect($old->fresh()->processing_status)->toBe('WAITING_REVIEW');
    $identity = app(ApproveKycAction::class)->execute($this->tenant->id, $old->id, $this->admin);
    $oldState = $old->fresh()->getAttributes();
    $hash = $identity->identity_hash;
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/kyc')->assertOk()
        ->assertInertia(fn ($page) => $page->where('canReverify', true));

    $this->travel(1)->seconds();
    fakeMatchingKycOcr('');
    $failed = reverifySubmit($this, true);
    expect($failed->processing_status)->toBe('QUEUED')->and($identity->fresh()->identity_hash)->toBe($hash);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $failed->id);
    expect($failed->fresh()->processing_status)->toBe('FAILED')->and($identity->fresh()->identity_hash)->toBe($hash)
        ->and(app(UserKycQuery::class)->get($this->tenant->id, $this->user->id)['status'])->toBe('APPROVED');

    $this->travel(1)->seconds();
    fakeMatchingKycOcr('320311197707060018');
    $new = reverifySubmit($this, true);
    expect($new->resubmission_of_id)->toBe($failed->id)
        ->and($identity->fresh()->source_kyc_application_id)->toBe($old->id)
        ->and(app(UserKycQuery::class)->get($this->tenant->id, $this->user->id)['reverificationPending'])->toBeTrue();
    expect(fn () => reverifySubmit($this, true))->toThrow(DomainException::class, 'Identity verification is already under review.');
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $new->id);
    app(ApproveKycAction::class)->execute($this->tenant->id, $new->id, $this->admin);
    expect($identity->fresh()->identity_hash)->not->toBe($hash)
        ->and($identity->fresh()->source_kyc_application_id)->toBe($new->id)
        ->and(IdentityRecord::where('user_id', $this->user->id)->count())->toBe(1)
        ->and($old->fresh()->getAttributes())->toBe($oldState);
    $this->assertDatabaseHas('audit_logs', ['action' => 'KYC_IDENTITY_REBOUND', 'resource_id' => $identity->id]);

    PlatformKycSetting::current()->update(['review_mode' => 'AUTOMATIC', 'max_accounts_per_identity' => 1]);
    $this->travel(1)->seconds();
    $automatic = reverifySubmit($this, true);
    expect($automatic->processing_status)->toBe('QUEUED')->and($automatic->fresh()->automatically_approved)->toBeFalse();
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $automatic->id);
    expect($automatic->fresh()->automatically_approved)->toBeTrue()
        ->and($identity->fresh()->source_kyc_application_id)->toBe($automatic->id);
    Http::assertNothingSent();
});

it('accepts the advertised re-verification flow for platform-verified accounts without inventing prior identity evidence', function (): void {
    platformVerifiedKycFixture($this);
    expect(IdentityRecord::where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->exists())->toBeFalse();
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/kyc')->assertOk()
        ->assertInertia(fn ($page) => $page->where('kyc.status', 'APPROVED')->where('canReverify', true)
            ->where('kyc.frontUrl', null)->where('kyc.maskedIdentityNumber', null));
    $response = $this->post('http://a.localhost/kyc/applications', [
        'document_type' => 'NATIONAL_ID', 'document_country' => 'CN', 'reverify' => true,
        'front' => kycTestImage('front.png'), 'back' => kycTestImage('back.png'),
    ], ['Accept' => 'application/json'])->assertStatus(202)->assertJsonPath('processingStatus', 'QUEUED');
    $application = KycApplication::where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->sole();
    expect($response->json('applicationId'))->toBe($application->id)
        ->and($application->resubmission_of_id)->toBeNull()
        ->and(IdentityRecord::where('user_id', $this->user->id)->count())->toBe(0)
        ->and($application->identity_hash)->toBeNull()->and($application->ocr_result_encrypted)->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'KYC_APPLICATION_SUBMITTED', 'resource_id' => $application->id]);
    Http::assertNothingSent();
});

it('preserves platform pre-verification after failed processing and permits an immediate linked re-verification', function (): void {
    platformVerifiedKycFixture($this);
    $before = (array) DB::table('platform_user_creations')->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->first();
    fakeMatchingKycOcr('');
    $failed = reverifySubmit($this, true);
    app(ProcessPendingKyc::class)->execute($this->tenant->id, $failed->id);
    expect($failed->fresh()->processing_status)->toBe('FAILED')
        ->and(app(UserKycQuery::class)->get($this->tenant->id, $this->user->id)['status'])->toBe('APPROVED')
        ->and(IdentityRecord::where('user_id', $this->user->id)->count())->toBe(0);
    $this->travel(1)->seconds();
    $next = reverifySubmit($this, true);
    expect($next->resubmission_of_id)->toBe($failed->id)->and($next->processing_status)->toBe('QUEUED')
        ->and((array) DB::table('platform_user_creations')->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->first())->toBe($before);
    Http::assertNothingSent();
});

it('returns an explicit state-conflict code when a client flag disagrees with trusted verification state', function (bool $platformVerified): void {
    if ($platformVerified) platformVerifiedKycFixture($this);
    try {
        reverifySubmit($this, ! $platformVerified);
        $this->fail('A mismatched re-verification flag must be rejected.');
    } catch (DomainException $error) {
        expect($error->errorCode)->toBe('KYC_REVERIFICATION_STATE_CHANGED')->and($error->httpStatus)->toBe(409);
    }
    expect(KycApplication::where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->count())->toBe(0);
    Http::assertNothingSent();
})->with([false, true]);

it('keeps pending and accepted-request replay guards for platform-verified re-verification', function (): void {
    platformVerifiedKycFixture($this);
    $files = reverifyRetainedUploads($this);
    $application = reverifySubmit($this, true, $files);
    $replay = reverifySubmit($this, true, $files);
    expect($replay->id)->toBe($application->id);
    try {
        reverifySubmit($this, false, $files);
        $this->fail('Changing an accepted request must not create another application.');
    } catch (DomainException $error) {
        expect($error->errorCode)->toBe('KYC_SUBMISSION_REPLAY_CONFLICT')->and($error->httpStatus)->toBe(409);
    }
    expect(fn () => reverifySubmit($this, true))->toThrow(DomainException::class, 'Identity verification is already under review.');
    expect(KycApplication::where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($application->fresh()->processing_status)->toBe('QUEUED');
    Http::assertNothingSent();
});
