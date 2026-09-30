<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Kyc\UserKycQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Storage;

it('retains the old identity until new documents are approved then rebinds with history and audit', function () {
    $this->seed();
    config(['media.storage' => 'server', 'inertia.ssr.enabled' => false]);
    Storage::fake('private');
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $user = User::where('tenant_id', $tenant->id)->firstOrFail();
    $admin = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    $submit = app(SubmitKycApplicationAction::class);
    $approve = app(ApproveKycAction::class);
    fakeMatchingKycOcr('11010519491231002X');
    $old = $submit->execute($tenant, $user, 'CN', '', kycTestImage(), kycTestImage());
    $identity = $approve->execute($tenant->id, $old->id, $admin);
    $oldState = $old->fresh()->getAttributes();
    $hash = $identity->identity_hash;
    $this->actingAs($user, 'tenant_user')->get('http://a.localhost/kyc')->assertOk()
        ->assertInertia(fn ($page) => $page->where('canReverify', true));
    fakeMatchingKycOcr('');
    expect(fn () => $submit->execute($tenant, $user, 'CN', '', kycTestImage(), kycTestImage(), reverify: true))->toThrow(DomainException::class);
    expect($identity->fresh()->identity_hash)->toBe($hash);
    fakeMatchingKycOcr('110105194912310011');
    $this->travel(1)->seconds();
    $new = $submit->execute($tenant, $user, 'CN', '', kycTestImage(), kycTestImage(), reverify: true);
    expect($identity->fresh()->source_kyc_application_id)->toBe($old->id);
    expect(app(UserKycQuery::class)->get($tenant->id, $user->id)['reverificationPending'])->toBeTrue();
    expect(fn () => $submit->execute($tenant, $user, 'CN', '', kycTestImage(), kycTestImage(), reverify: true))->toThrow(DomainException::class);
    $approve->execute($tenant->id, $new->id, $admin);
    expect($identity->fresh()->identity_hash)->not->toBe($hash);
    expect($identity->fresh()->source_kyc_application_id)->toBe($new->id);
    expect(IdentityRecord::where('user_id', $user->id)->count())->toBe(1);
    expect($old->fresh()->getAttributes())->toBe($oldState);
    $this->assertDatabaseHas('audit_logs', ['action' => 'KYC_IDENTITY_REBOUND', 'resource_id' => $identity->id]);
    PlatformKycSetting::current()->update(['review_mode' => 'AUTOMATIC', 'max_accounts_per_identity' => 1]);
    $this->travel(1)->seconds();
    $automatic = $submit->execute($tenant, $user, 'CN', '', kycTestImage(), kycTestImage(), reverify: true);
    expect($automatic->automatically_approved)->toBeTrue();
    expect($identity->fresh()->source_kyc_application_id)->toBe($automatic->id);
});
