<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\RejectKycAction;
use App\Application\Kyc\RequireKycResubmissionAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Kyc\Enums\KycReviewReason;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserPreference;
use App\Domain\User\Models\UserProfile;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->reviewer = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
});

function makePhaseThreeUser(Tenant $tenant, string $email): User
{
    $user = User::query()->create(['tenant_id' => $tenant->id, 'email' => $email, 'password_hash' => Hash::make('local-password'), 'status' => UserStatus::Active, 'email_verified_at' => now()]);
    UserProfile::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'display_name' => 'KYC Test User']);
    UserPreference::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'locale' => 'en']);

    return $user;
}

function submitReviewableKyc($test, User $user, string $identity): KycApplication
{
    return app(SubmitKycApplicationAction::class)->execute($test->tenant, $user, 'MY', $identity, kycTestImage('front.jpg'), kycTestImage('back.jpg'));
}

it('approves a pending application exactly once and creates immutable identity history', function (): void {
    $application = submitReviewableKyc($this, $this->user, 'ID-APPROVE-1');
    $identity = app(ApproveKycAction::class)->execute($this->tenant->id, $application->id, $this->reviewer, (string) Str::uuid());

    expect($application->fresh()->review_status)->toBe(KycReviewStatus::Approved)
        ->and($identity->source_kyc_application_id)->toBe($application->id)
        ->and(IdentityRecord::query()->count())->toBe(1)
        ->and(app(KycStatusService::class)->forUser($this->tenant->id, $this->user->id))->toBe(KycUserStatus::Approved)
        ->and(fn () => app(ApproveKycAction::class)->execute($this->tenant->id, $application->id, $this->reviewer))->toThrow(DomainException::class)
        ->and(AuditLog::query()->where('action', 'KYC_APPLICATION_APPROVED')->count())->toBe(1);
});

it('supports only terminal reject or resubmission-required transitions from pending', function (string $transition): void {
    $application = submitReviewableKyc($this, $this->user, 'ID-'.$transition);
    if ($transition === 'REJECTED') {
        app(RejectKycAction::class)->execute($this->tenant->id, $application->id, $this->reviewer, KycReviewReason::DocumentMismatch, 'The provided details do not match.');
    } else {
        app(RequireKycResubmissionAction::class)->execute($this->tenant->id, $application->id, $this->reviewer, KycReviewReason::DocumentUnreadable, 'Please upload clearer images.');
    }

    expect($application->fresh()->review_status->value)->toBe($transition)
        ->and(fn () => app(RejectKycAction::class)->execute($this->tenant->id, $application->id, $this->reviewer, KycReviewReason::Other, 'Another decision.'))->toThrow(DomainException::class);
})->with(['REJECTED', 'RESUBMISSION_REQUIRED']);

it('allows only resubmission-required applications to create a linked replacement', function (): void {
    $application = submitReviewableKyc($this, $this->user, 'ID-OLD');
    app(RequireKycResubmissionAction::class)->execute($this->tenant->id, $application->id, $this->reviewer, KycReviewReason::DocumentUnreadable, 'Please upload clearer images.');
    $new = submitReviewableKyc($this, $this->user, 'ID-NEW');

    expect($new->resubmission_of_id)->toBe($application->id)
        ->and(fn () => submitReviewableKyc($this, $this->user, 'ID-THIRD'))->toThrow(DomainException::class);
});

it('blocks self-resubmission after rejection or approval', function (string $decision): void {
    $application = submitReviewableKyc($this, $this->user, 'ID-TERMINAL-'.$decision);
    $decision === 'approve'
        ? app(ApproveKycAction::class)->execute($this->tenant->id, $application->id, $this->reviewer)
        : app(RejectKycAction::class)->execute($this->tenant->id, $application->id, $this->reviewer, KycReviewReason::Other, 'Please contact support.');

    expect(fn () => submitReviewableKyc($this, $this->user, 'ID-SECOND'))->toThrow(DomainException::class);
})->with(['approve', 'reject']);

it('enforces the identity account limit during approval without auto-rejecting', function (): void {
    $other = makePhaseThreeUser($this->tenant, 'other@a.localhost');
    $first = submitReviewableKyc($this, $this->user, 'SHARED-ID');
    app(ApproveKycAction::class)->execute($this->tenant->id, $first->id, $this->reviewer);
    $second = submitReviewableKyc($this, $other, 'shared-id');

    try {
        app(ApproveKycAction::class)->execute($this->tenant->id, $second->id, $this->reviewer);
        $this->fail('Expected duplicate identity approval to be blocked.');
    } catch (DomainException $exception) {
        expect($exception->errorCode)->toBe('IDENTITY_ACCOUNT_LIMIT_REACHED');
    }
    expect($second->fresh()->review_status)->toBe(KycReviewStatus::Pending)
        ->and(IdentityRecord::query()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

it('keeps duplicate identity limits independent between tenants', function (): void {
    $applicationA = submitReviewableKyc($this, $this->user, 'CROSS-TENANT-ID');
    app(ApproveKycAction::class)->execute($this->tenant->id, $applicationA->id, $this->reviewer);

    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $userB = User::query()->where('tenant_id', $tenantB->id)->firstOrFail();
    $reviewerB = AdminUser::query()->where('email', 'owner@b.localhost')->firstOrFail();
    $this->tenant = $tenantB;
    $applicationB = submitReviewableKyc($this, $userB, 'CROSS-TENANT-ID');
    app(ApproveKycAction::class)->execute($tenantB->id, $applicationB->id, $reviewerB);

    expect(IdentityRecord::query()->where('identity_hash', $applicationA->identity_hash)->count())->toBe(1)
        ->and(IdentityRecord::query()->count())->toBe(2);
});

it('does not invalidate existing identities when the tenant lowers its limit', function (): void {
    $this->tenant->kycSettings()->update(['max_accounts_per_identity' => 2]);
    $other = makePhaseThreeUser($this->tenant, 'second@a.localhost');
    foreach ([[$this->user, 'LIMIT-ID'], [$other, 'LIMIT-ID']] as [$user, $identity]) {
        $application = submitReviewableKyc($this, $user, $identity);
        app(ApproveKycAction::class)->execute($this->tenant->id, $application->id, $this->reviewer);
    }
    $this->tenant->kycSettings()->update(['max_accounts_per_identity' => 1]);
    expect(IdentityRecord::query()->where('tenant_id', $this->tenant->id)->count())->toBe(2);
});

it('never exposes full identity data in review audits and rejects it in reviewer messages', function (): void {
    $identity = 'SENSITIVE-ID-7777';
    $application = submitReviewableKyc($this, $this->user, $identity);
    expect(fn () => app(RejectKycAction::class)->execute($this->tenant->id, $application->id, $this->reviewer, KycReviewReason::Other, "The number {$identity} is invalid."))->toThrow(DomainException::class);
    app(RejectKycAction::class)->execute($this->tenant->id, $application->id, $this->reviewer, KycReviewReason::Other, 'Please contact support.');
    expect(AuditLog::query()->get()->toJson())->not->toContain($identity, $application->identity_hash, $application->front_object_key);
});
