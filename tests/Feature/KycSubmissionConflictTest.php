<?php

use App\Application\User\CreatePlatformUserAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Media\DirectImageUpload;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->freezeTime();
    Storage::fake('private');
    Queue::fake();
    Http::preventStrayRequests();
    config(['media.storage' => 'server', 'inertia.ssr.enabled' => false]);
    DB::table('media_storage_settings')->where('id', 1)->update(['storage_driver' => 'server']);
    PlatformKycSetting::current()->update(['enabled' => true, 'review_mode' => 'AUTOMATIC']);
    $this->tenant = Tenant::where('slug', 'tenant-a')->sole();
    $this->user = User::where('tenant_id', $this->tenant->id)->where('email', 'user@a.localhost')->sole();
    $this->admin = AdminUser::where('email', 'owner@platform.local')->sole();
    $this->base = 'http://a.localhost/api/mobile/v1';
    $provider = Mockery::mock(KycOcrProviderInterface::class);
    $provider->shouldNotReceive('extractIdentityDocument');
    app()->instance(KycOcrProviderInterface::class, $provider);
});

function kycConflictPlatformUser($test): User
{
    return app(CreatePlatformUserAction::class)->execute($test->tenant->id, $test->admin, [
        'email' => 'platform-kyc-conflict@example.test', 'display_name' => 'Synthetic KYC user',
        'password' => 'local-password', 'request_id' => (string) Str::uuid(),
    ]);
}

function kycConflictLogin($test, User $user, ?string $base = null): void
{
    $test->base = $base ?? 'http://a.localhost/api/mobile/v1';
    $test->flushHeaders();
    $token = $test->postJson($test->base.'/login', ['identifier' => $user->email, 'password' => 'local-password', 'device_name' => 'Offline KYC conflict test'])
        ->assertCreated()->json('token');
    $test->withToken($token);
    $flow = $test->getJson($test->base.'/bootstrap')->assertOk()->headers->get('X-Consumer-Flow');
    expect($flow)->toBeString()->toHaveLength(64);
    $test->withHeader('X-Consumer-Flow', $flow);
}

function kycConflictUploads($test, bool $complete = true): array
{
    $data = ['document_type' => 'NATIONAL_ID', 'document_country' => 'CN'];
    foreach (['front', 'back'] as $side) {
        $ticket = $test->postJson($test->base.'/images/direct', ['purpose' => 'kyc', 'field' => $side, 'mime' => 'image/png'])
            ->assertOk()->assertJsonPath('mode', 'server')->json();
        $test->post($test->base.'/images/direct/'.$ticket['id'].'/backup', ['file' => kycTestImage($side.'.png')])->assertNoContent();
        if ($complete) $test->postJson($test->base.'/images/direct/'.$ticket['id'].'/complete')->assertNoContent();
        $data[$side.'_upload_id'] = $ticket['id'];
    }

    return $data;
}

function assertKycConflictUnclaimed(array $data): void
{
    foreach (['front', 'back'] as $side) {
        $upload = DirectImageUpload::findOrFail($data[$side.'_upload_id']);
        expect($upload->verified_at)->not->toBeNull()->and($upload->claimed_at)->toBeNull();
    }
}

it('accepts the submission mode advertised by the authenticated KYC page for ordinary and platform verified users', function (bool $platformVerified, bool $textFlag): void {
    $user = $platformVerified ? kycConflictPlatformUser($this) : $this->user;
    $baseline = [DB::table('wallets')->count(), DB::table('ledger_entries')->count()];
    kycConflictLogin($this, $user);
    $this->getJson($this->base.'/client/kyc')->assertOk()
        ->assertJsonPath('props.kyc.status', $platformVerified ? 'APPROVED' : 'NOT_SUBMITTED')
        ->assertJsonPath('props.canSubmit', ! $platformVerified)
        ->assertJsonPath('props.canReverify', $platformVerified);
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(0);
    $data = kycConflictUploads($this);
    $data['reverify'] = $textFlag ? ($platformVerified ? 'true' : 'false') : $platformVerified;
    $response = $this->postJson($this->base.'/client/kyc/applications', $data)
        ->assertStatus(202)->assertJsonPath('processingStatus', 'QUEUED');
    $application = KycApplication::sole();
    expect($response->json('applicationId'))->toBe($application->id)
        ->and($application->tenant_id)->toBe($this->tenant->id)->and($application->user_id)->toBe($user->id)
        ->and($application->identity_hash)->toBeNull()->and($application->review_status->value)->toBe('PENDING');
    foreach (['front', 'back'] as $side) expect(DirectImageUpload::findOrFail($data[$side.'_upload_id'])->claimed_at)->not->toBeNull();
    $this->getJson($this->base.'/client/kyc')->assertOk()
        ->assertJsonPath('props.kyc.hasPendingApplication', true)
        ->assertJsonPath('props.kyc.reverificationPending', $platformVerified)
        ->assertJsonPath('props.canSubmit', false)->assertJsonPath('props.canReverify', false);
    expect(IdentityRecord::count())->toBe(0)
        ->and([DB::table('wallets')->count(), DB::table('ledger_entries')->count()])->toBe($baseline);
    Http::assertNothingSent();
})->with(['ordinary user' => false, 'platform verified user' => true])->with(['JSON flag' => false, 'text flag' => true]);

it('returns a safe conflict without claiming uploads when the requested KYC mode disagrees with current verification', function (bool $platformVerified): void {
    $user = $platformVerified ? kycConflictPlatformUser($this) : $this->user;
    kycConflictLogin($this, $user);
    $data = kycConflictUploads($this);
    $response = $this->postJson($this->base.'/client/kyc/applications', $data + ['reverify' => ! $platformVerified])
        ->assertConflict()->assertJsonPath('error.code', 'KYC_REVERIFICATION_STATE_CHANGED');
    expect($response->json('error.message'))->toBeString()->not->toBe('')
        ->and(Str::isUuid($response->json('error.request_id')))->toBeTrue()
        ->and($response->getContent())->not->toContain($data['front_upload_id'], $data['back_upload_id'], $user->email)
        ->and(KycApplication::count())->toBe(0)->and(IdentityRecord::count())->toBe(0);
    assertKycConflictUnclaimed($data);
    Http::assertNothingSent();
})->with([false, true]);

it('keeps one active review and leaves a new upload pair unclaimed after a pending conflict', function (): void {
    kycConflictLogin($this, $this->user);
    $first = kycConflictUploads($this) + ['reverify' => false];
    $id = $this->postJson($this->base.'/client/kyc/applications', $first)->assertStatus(202)->json('applicationId');
    $second = kycConflictUploads($this) + ['reverify' => false];
    $this->postJson($this->base.'/client/kyc/applications', $second)->assertConflict()->assertJsonPath('error.code', 'KYC_ALREADY_PENDING');
    expect(KycApplication::sole()->id)->toBe($id)->and(IdentityRecord::count())->toBe(0);
    assertKycConflictUnclaimed($second);
    Http::assertNothingSent();
});

it('replays an identical accepted request but rejects changed intent without creating another application', function (): void {
    kycConflictLogin($this, $this->user);
    $data = kycConflictUploads($this) + ['reverify' => false];
    $id = $this->postJson($this->base.'/client/kyc/applications', $data)->assertStatus(202)->json('applicationId');
    $this->postJson($this->base.'/client/kyc/applications', $data)->assertStatus(202)->assertJsonPath('applicationId', $id);
    $this->postJson($this->base.'/client/kyc/applications', [...$data, 'reverify' => true])
        ->assertConflict()->assertJsonPath('error.code', 'KYC_SUBMISSION_REPLAY_CONFLICT');
    $changed = kycConflictUploads($this);
    $this->postJson($this->base.'/client/kyc/applications', [...$data, 'back_upload_id' => $changed['back_upload_id']])
        ->assertConflict()->assertJsonPath('error.code', 'KYC_SUBMISSION_REPLAY_CONFLICT');
    expect(KycApplication::sole()->id)->toBe($id)->and(IdentityRecord::count())->toBe(0);
    assertKycConflictUnclaimed($changed);
    Http::assertNothingSent();
});

it('rejects cross company credentials and cross company upload references without creating an application', function (): void {
    kycConflictLogin($this, $this->user);
    $data = kycConflictUploads($this) + ['reverify' => false];
    $otherBase = 'http://b.localhost/api/mobile/v1';
    $this->postJson($otherBase.'/client/kyc/applications', $data)->assertUnauthorized();
    $otherTenant = Tenant::where('slug', 'tenant-b')->sole();
    $otherUser = User::where('tenant_id', $otherTenant->id)->where('email', 'user@b.localhost')->sole();
    kycConflictLogin($this, $otherUser, $otherBase);
    $this->postJson($this->base.'/client/kyc/applications', $data)->assertNotFound();
    expect(KycApplication::count())->toBe(0)->and(IdentityRecord::count())->toBe(0);
    assertKycConflictUnclaimed($data);
    Http::assertNothingSent();
});

it('returns a safe upload conflict when originals were backed up but never completed', function (): void {
    kycConflictLogin($this, $this->user);
    $data = kycConflictUploads($this, false) + ['reverify' => false];
    $this->postJson($this->base.'/client/kyc/applications', $data)->assertConflict()
        ->assertJsonPath('error.code', 'IMAGE_UPLOAD_NOT_VERIFIED');
    foreach (['front', 'back'] as $side) {
        $upload = DirectImageUpload::findOrFail($data[$side.'_upload_id']);
        expect($upload->verified_at)->toBeNull()->and($upload->claimed_at)->toBeNull();
    }
    expect(KycApplication::count())->toBe(0)->and(IdentityRecord::count())->toBe(0);
    Http::assertNothingSent();
});

it('returns a safe final submission conflict when a completed original is no longer ready', function (): void {
    kycConflictLogin($this, $this->user);
    $data = kycConflictUploads($this) + ['reverify' => false];
    $front = DirectImageUpload::findOrFail($data['front_upload_id']);
    DB::table('stored_images')->where('id', $front->image_id)->update(['state' => 'uploading']);
    $response = $this->postJson($this->base.'/client/kyc/applications', $data)->assertConflict()
        ->assertJsonPath('error.code', 'IMAGE_UPLOAD_NOT_READY');
    expect(Str::isUuid($response->json('error.request_id')))->toBeTrue()
        ->and($response->json('error.message'))->toBeString()->not->toBe('')
        ->and($response->getContent())->not->toContain($data['front_upload_id'], $data['back_upload_id'])
        ->and(KycApplication::count())->toBe(0)->and(IdentityRecord::count())->toBe(0);
    assertKycConflictUnclaimed($data);
    Http::assertNothingSent();
});

it('rejects an already claimed back original on a fresh attempt without claiming its new front', function (): void {
    kycConflictLogin($this, $this->user);
    $oldData = kycConflictUploads($this) + ['reverify' => false];
    $oldId = $this->postJson($this->base.'/client/kyc/applications', $oldData)->assertStatus(202)->json('applicationId');
    KycApplication::findOrFail($oldId)->forceFill(['processing_status' => 'FAILED', 'processing_error' => 'KYC_OCR_MISMATCH', 'next_processing_at' => null])->save();
    $this->travel(1)->seconds();
    $newData = kycConflictUploads($this) + ['reverify' => false];
    $this->postJson($this->base.'/client/kyc/applications', [...$newData, 'back_upload_id' => $oldData['back_upload_id']])
        ->assertConflict()->assertJsonPath('error.code', 'IMAGE_UPLOAD_ALREADY_CLAIMED');
    expect(KycApplication::sole()->id)->toBe($oldId)->and(IdentityRecord::count())->toBe(0);
    assertKycConflictUnclaimed($newData);
    Http::assertNothingSent();
});
