<?php

use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Kyc\UserKycQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Jobs\ProcessKycOcrJob;
use App\Support\Errors\DomainException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
});

function submitPhaseThreeKyc($test, string $identity = 'MY 1234-5678'): KycApplication
{
    return app(SubmitKycApplicationAction::class)->execute(
        $test->tenant,
        $test->user,
        'MY',
        $identity,
        kycTestImage('personal-front.jpg'),
        kycTestImage('personal-back.png'),
        (string) Str::uuid(),
    );
}

it('submits a national id to private storage and dispatches tenant-scoped OCR after commit', function (): void {
    $application = submitPhaseThreeKyc($this);

    expect($application->review_status)->toBe(KycReviewStatus::Pending)
        ->and($application->document_type->value)->toBe('NATIONAL_ID')
        ->and($application->document_country)->toBe('MY')
        ->and($application->front_object_key)->toStartWith("kyc/{$this->tenant->id}/{$this->user->id}/{$application->id}/front/")
        ->and($application->front_object_key)->not->toContain('user@', '1234', 'personal-front')
        ->and($application->back_object_key)->not->toContain('user@', '5678', 'personal-back');
    Storage::disk('private')->assertExists([$application->front_object_key, $application->back_object_key]);
    Storage::disk('public')->assertMissing([$application->front_object_key, $application->back_object_key]);
    Queue::assertPushed(ProcessKycOcrJob::class, fn (ProcessKycOcrJob $job) => $job->tenantId === $this->tenant->id && $job->kycApplicationId === $application->id);
});

it('requires both real supported images and enforces the configured size', function (): void {
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/kyc/applications', [
        'document_country' => 'MY', 'identity_number' => 'MY1234',
        'front' => UploadedFile::fake()->create('front.svg', 10, 'image/svg+xml'),
    ])->assertSessionHasErrors(['front', 'back']);

    config(['kyc.document_max_mb' => 1]);
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/kyc/applications', [
        'document_country' => 'Malaysia', 'identity_number' => 'MY1234',
        'front' => UploadedFile::fake()->create('front.jpg', 1100, 'image/jpeg'),
        'back' => kycTestImage('back.jpg'),
    ])->assertSessionHasErrors(['document_country', 'front']);
    expect(KycApplication::query()->count())->toBe(0);
});

it('rejects non-image payloads regardless of a trusted-looking filename', function (string $filename, string $contents): void {
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/kyc/applications', [
        'document_country' => 'MY', 'identity_number' => 'MY1234',
        'front' => UploadedFile::fake()->createWithContent($filename, $contents),
        'back' => kycTestImage('back.png'),
    ])->assertSessionHasErrors('front');
    expect(KycApplication::query()->count())->toBe(0);
})->with([
    ['renamed-executable.jpg', "MZ\x90\x00not-an-image"],
    ['renamed-html.png', '<html><script>alert(1)</script></html>'],
    ['vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>'],
    ['empty.png', ''],
]);

it('blocks submission for suspended users suspended tenants and disabled KYC', function (string $restriction): void {
    if ($restriction === 'user') {
        $this->user->update(['status' => UserStatus::Suspended]);
    } elseif ($restriction === 'tenant') {
        $this->tenant->update(['status' => TenantStatus::Suspended]);
    } else {
        PlatformKycSetting::current()->update(['enabled' => false]);
    }

    $response = $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/kyc/applications', [
        'document_country' => 'MY', 'identity_number' => 'MY1234',
        'front' => kycTestImage('front.jpg'), 'back' => kycTestImage('back.jpg'),
    ]);
    $restriction === 'tenant' ? $response->assertRedirect('/account/restricted') : $response->assertRedirect();
    expect(KycApplication::query()->count())->toBe(0);
})->with(['user', 'tenant', 'settings']);

it('allows suspended users and tenants to view KYC status read-only', function (): void {
    $this->user->update(['status' => UserStatus::Suspended]);
    $this->tenant->update(['status' => TenantStatus::Suspended]);

    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/kyc')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('user/Kyc')->where('canSubmit', false));
});

it('keeps one pending application and cleans files uploaded by a failed duplicate submission', function (): void {
    $first = submitPhaseThreeKyc($this);
    expect(fn () => submitPhaseThreeKyc($this, 'MY9999'))->toThrow(DomainException::class);

    expect(KycApplication::query()->count())->toBe(1)
        ->and(Storage::disk('private')->allFiles())->toHaveCount(2)
        ->and(Storage::disk('private')->allFiles())->toContain($first->front_object_key, $first->back_object_key);
});

it('cleans a successful front upload when the back upload fails', function (): void {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('putFileAs')->twice()->andReturn(true, false);
    $disk->shouldReceive('delete')->once()->andReturn(true);
    Storage::shouldReceive('disk')->atLeast()->once()->with('private')->andReturn($disk);

    expect(fn () => app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'MY', 'PARTIAL-1', kycTestImage('front.jpg'), kycTestImage('back.jpg')))
        ->toThrow(DomainException::class);
    expect(KycApplication::query()->count())->toBe(0);
});

it('preserves the primary failure and logs only safe metadata when orphan cleanup fails', function (): void {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('putFileAs')->twice()->andReturn(true, false);
    $disk->shouldReceive('delete')->once()->andThrow(new RuntimeException('unsafe provider path detail'));
    Storage::shouldReceive('disk')->atLeast()->once()->with('private')->andReturn($disk);
    Log::spy();

    expect(fn () => app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'MY', 'CLEANUP-SECRET', kycTestImage('front.jpg'), kycTestImage('back.jpg')))
        ->toThrow(DomainException::class, 'The documents could not be stored.');
    Log::shouldHaveReceived('warning')->once()->with('KYC document cleanup failed.', Mockery::on(function (array $context): bool {
        $json = json_encode($context);

        return ! str_contains($json, 'CLEANUP-SECRET') && ! str_contains($json, 'unsafe provider path detail') && ! str_contains($json, '/front/');
    }));
});

it('derives status from applications and gives an identity record highest priority', function (): void {
    $statuses = app(KycStatusService::class);
    expect($statuses->forUser($this->tenant->id, $this->user->id))->toBe(KycUserStatus::NotSubmitted);
    $application = submitPhaseThreeKyc($this);
    expect($statuses->forUser($this->tenant->id, $this->user->id))->toBe(KycUserStatus::Pending);
    $reviewer = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $application->forceFill(['review_status' => KycReviewStatus::ResubmissionRequired, 'review_reason_code' => 'DOCUMENT_UNREADABLE', 'review_message' => 'Please resubmit.', 'reviewed_by_admin_user_id' => $reviewer->id, 'reviewed_at' => now()])->save();
    expect($statuses->forUser($this->tenant->id, $this->user->id))->toBe(KycUserStatus::ResubmissionRequired);
});

it('encrypts identity numbers uses tenant-scoped HMAC and exposes only allowlisted masked output', function (): void {
    $plain = 'my 1234-5678';
    $application = submitPhaseThreeKyc($this, $plain);
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $protector = app(IdentityNumberProtector::class);

    expect($application->identity_number_encrypted)->not->toContain($plain, 'MY 1234-5678')
        ->and($application->identity_hash)->toHaveLength(64)
        ->and($protector->protect($tenantB->id, 'NATIONAL_ID', 'MY', $plain)['hash'])->not->toBe($application->identity_hash)
        ->and(json_encode(app(UserKycQuery::class)->get($this->tenant->id, $this->user->id)))->not->toContain($plain, 'identity_hash', 'object_key', 'encrypted');

    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/kyc')->assertOk()
        ->assertDontSee($plain)->assertDontSee($application->identity_hash)->assertDontSee($application->front_object_key);
});

it('preserves only the allowed verification return source after submission', function (string $source, string $expected): void {
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/kyc/applications?'.http_build_query(['from' => $source]), [
        'document_country' => 'MY', 'identity_number' => 'MY1234',
        'front' => kycTestImage('front.png'), 'back' => kycTestImage('back.png'),
    ])->assertSessionHasNoErrors()->assertRedirect($expected);
    expect(KycApplication::query()->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->count())->toBe(1);
})->with([
    ['account-security', '/kyc?from=account-security'],
    ['https://example.com/return', '/kyc'],
]);
