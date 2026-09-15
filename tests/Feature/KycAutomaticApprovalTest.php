<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Services\TenantOnboardingStatusService;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
});

function autoKycSubmit($test, ?User $user = null, string $number = 'AUTO-KYC-001'): KycApplication
{
    return app(SubmitKycApplicationAction::class)->execute($test->tenant, $user ?? $test->user, 'MY', $number, kycTestImage('front.jpg'), kycTestImage('back.jpg'));
}

it('approves new valid submissions with system provenance and no financial or OCR side effects', function (): void {
    PlatformKycSetting::current()->update(['review_mode' => 'AUTOMATIC']);
    $before = [];
    foreach (['wallets', 'ledger_entries', 'ledger_accounts', 'user_cards', 'commission_awards'] as $table) {
        $before[$table] = DB::table($table)->count();
    }
    $application = autoKycSubmit($this);
    expect($application->review_status)->toBe(KycReviewStatus::Approved)
        ->and($application->automatically_approved)->toBeTrue()
        ->and($application->reviewed_by_admin_user_id)->toBeNull()
        ->and($application->reviewed_at)->not->toBeNull()
        ->and(IdentityRecord::query()->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->count())->toBe(1);
    $audit = AuditLog::query()->where('action', 'KYC_APPLICATION_APPROVED')->where('resource_id', $application->id)->sole();
    expect($audit->actor_type)->toBe('SYSTEM')->and($audit->actor_id)->toBeNull()
        ->and($audit->after_data['review_mode'])->toBe('AUTOMATIC');
    Queue::assertNothingPushed();
    foreach ($before as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
    expect(fn () => autoKycSubmit($this))->toThrow(DomainException::class);
    expect(IdentityRecord::query()->count())->toBe(1);
    expect(fn () => DB::transaction(fn () => DB::table('kyc_applications')->where('id', $application->id)->update([
        'automatically_approved' => false, 'reviewed_by_admin_user_id' => $this->owner->id,
    ])))->toThrow(QueryException::class);
});

it('rolls back over-limit submissions and cleans newly uploaded private documents', function (): void {
    PlatformKycSetting::current()->update(['review_mode' => 'AUTOMATIC', 'max_accounts_per_identity' => 1]);
    autoKycSubmit($this);
    $files = Storage::disk('private')->allFiles();
    $second = User::query()->create(['tenant_id' => $this->tenant->id, 'email' => 'automatic-second@example.test', 'password_hash' => 'unused', 'status' => 'ACTIVE']);
    expect(fn () => autoKycSubmit($this, $second))->toThrow(DomainException::class, 'This identity has reached the Tenant account limit.');
    expect(KycApplication::query()->where('user_id', $second->id)->count())->toBe(0)
        ->and(IdentityRecord::query()->count())->toBe(1)
        ->and(Storage::disk('private')->allFiles())->toBe($files);
});

it('requires explicit platform confirmation and leaves existing pending applications for manual review', function (): void {
    $pending = autoKycSubmit($this);
    $owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $payload = ['enabled' => true, 'max_accounts_per_identity' => 1, 'review_mode' => 'AUTOMATIC'];
    $this->actingAs($owner, 'platform_admin')->post('http://admin.localhost/platform/settings/kyc', $payload)->assertSessionHasErrors('automatic_approval_confirmed');
    expect(PlatformKycSetting::current()->review_mode->value)->toBe('MANUAL');
    $this->post('http://admin.localhost/platform/settings/kyc', $payload + ['automatic_approval_confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect(PlatformKycSetting::current()->review_mode->value)->toBe('AUTOMATIC')
        ->and($pending->fresh()->review_status)->toBe(KycReviewStatus::Pending);
    $otherTenant = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $otherUser = User::query()->where('tenant_id', $otherTenant->id)->firstOrFail();
    $otherApplication = app(SubmitKycApplicationAction::class)->execute($otherTenant, $otherUser, 'MY', 'AUTO-KYC-001', kycTestImage(), kycTestImage());
    expect($otherApplication->automatically_approved)->toBeTrue();
    $onboarding = app(TenantOnboardingStatusService::class)->for($this->tenant->fresh());
    expect(collect($onboarding['items'])->firstWhere('key', 'kyc')['complete'])->toBeTrue();
    $this->post('http://admin.localhost/platform/settings/kyc', [...$payload, 'review_mode' => 'PROVIDER_AUTOMATIC'])->assertSessionHasErrors('review_mode');
    app(ApproveKycAction::class)->execute($this->tenant->id, $pending->id, $this->owner);
    expect($pending->fresh()->automatically_approved)->toBeFalse();
});

it('returns an approved user status immediately and rejects client-selected reviewer fields', function (): void {
    PlatformKycSetting::current()->update(['review_mode' => 'AUTOMATIC']);
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/kyc/applications', [
        'document_country' => 'MY', 'identity_number' => 'AUTO-HTTP-1',
        'front' => kycTestImage('front.jpg'), 'back' => kycTestImage('back.jpg'),
        'reviewed_by_admin_user_id' => $this->owner->id, 'automatically_approved' => false,
    ])->assertRedirect('/kyc')->assertSessionHas('success', 'Your identity verification is complete.');
    $this->get('http://a.localhost/kyc')->assertInertia(fn ($page) => $page->where('kyc.status', 'APPROVED')->where('canSubmit', false));
    expect(KycApplication::query()->sole()->automatically_approved)->toBeTrue()
        ->and(KycApplication::query()->sole()->reviewed_by_admin_user_id)->toBeNull();
});

it('does not allow automatic approval in manual or reserved-provider modes', function (): void {
    $application = autoKycSubmit($this);
    expect(fn () => app(ApproveKycAction::class)->executeAutomatic($this->tenant->id, $application->id))->toThrow(DomainException::class);
    expect($application->fresh()->review_status)->toBe(KycReviewStatus::Pending);
    expect(fn () => DB::transaction(fn () => PlatformKycSetting::current()->update(['review_mode' => 'PROVIDER_AUTOMATIC'])))->toThrow(QueryException::class);
});

it('continues to reject invalid documents and disabled or suspended submissions in automatic mode', function (): void {
    PlatformKycSetting::current()->update(['review_mode' => 'AUTOMATIC']);
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/kyc/applications', [
        'document_country' => 'MY', 'identity_number' => 'AUTO-INVALID',
        'front' => UploadedFile::fake()->create('front.txt', 1, 'text/plain'),
        'back' => kycTestImage('back.jpg'),
    ])->assertSessionHasErrors('front');
    expect(IdentityRecord::query()->count())->toBe(0);
    PlatformKycSetting::current()->update(['enabled' => false]);
    expect(fn () => autoKycSubmit($this))->toThrow(DomainException::class);
    PlatformKycSetting::current()->update(['enabled' => true]);
    $this->user->update(['status' => 'SUSPENDED']);
    expect(fn () => autoKycSubmit($this))->toThrow(DomainException::class);
    expect(IdentityRecord::query()->count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
});
