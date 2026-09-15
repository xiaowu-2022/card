<?php

use App\Application\Tenant\CreateTenantAction;
use App\Application\Tenant\TenantSettingsQuery;
use App\Application\Tenant\UpdatePlatformKycSettingsAction;
use App\Application\Tenant\UpdateTenantKycSettingsAction;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenant\Enums\KycReviewMode;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->seed();
    $this->owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->companyOwner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->company = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->payload = ['enabled' => true, 'max_accounts_per_identity' => 1, 'review_mode' => 'AUTOMATIC', 'automatic_approval_confirmed' => true];
});

it('uses one policy for every existing and future company and records the actor and time', function (): void {
    Mail::fake();
    $legacy = DB::table('tenant_kyc_settings')->orderBy('tenant_id')->get()->toJson();
    app(UpdatePlatformKycSettingsAction::class)->execute(true, 1, KycReviewMode::Automatic, true, $this->owner, '6381ebea-5912-4d2f-a50f-2b97cf5678a2');
    foreach (Tenant::query()->get() as $company) {
        expect(app(TenantSettingsQuery::class)->execute($company)['kyc'])->toBe(PlatformKycSetting::current()->policy());
    }
    expect(DB::table('tenant_kyc_settings')->orderBy('tenant_id')->get()->toJson())->toBe($legacy);
    $created = app(CreateTenantAction::class)->execute(['name' => 'New Company', 'slug' => 'global-kyc-company', 'default_locale' => 'en', 'timezone' => 'UTC', 'default_asset' => 'USDT', 'owner_email' => 'owner@global-kyc.test'], $this->owner);
    expect(app(TenantSettingsQuery::class)->execute($created->tenant)['kyc'])->toBe(PlatformKycSetting::current()->policy());
    $audit = AuditLog::query()->where('action', 'PLATFORM_KYC_SETTINGS_UPDATED')->sole();
    expect($audit->tenant_id)->toBeNull()->and($audit->actor_id)->toBe($this->owner->id)
        ->and($audit->created_at)->not->toBeNull()->and($audit->request_id)->toBe('6381ebea-5912-4d2f-a50f-2b97cf5678a2')
        ->and($audit->before_data['reviewMode'])->toBe('MANUAL')->and($audit->after_data['reviewMode'])->toBe('AUTOMATIC');
    $this->actingAs($this->owner, 'platform_admin')->get('http://admin.localhost/platform/settings/kyc')->assertOk()->assertInertia(fn ($page) => $page->component('platform/KycSettings')->where('policy.reviewMode', 'AUTOMATIC'));
});

it('rejects old company mutations and direct action calls even from the platform owner', function (): void {
    $this->actingAs($this->owner, 'platform_admin')->post('http://admin.localhost/platform/tenants/'.$this->company->id.'/configuration/settings/kyc', $this->payload)->assertForbidden();
    expect(fn () => app(UpdateTenantKycSettingsAction::class)->execute($this->company, true, 2, $this->owner))->toThrow(HttpException::class);
    $this->actingAs($this->companyOwner, 'tenant_admin')->post('http://a.localhost/admin/settings/kyc', $this->payload)->assertForbidden();
    expect(fn () => app(UpdatePlatformKycSettingsAction::class)->execute(true, 1, KycReviewMode::Automatic, true, $this->companyOwner))->toThrow(HttpException::class);
    $this->actingAs($this->companyOwner, 'platform_admin')->post('http://admin.localhost/platform/settings/kyc', $this->payload)->assertForbidden();
    expect(PlatformKycSetting::current()->review_mode)->toBe(KycReviewMode::Manual);
});

it('requires an active platform administrator and an active membership', function (): void {
    $this->actingAs($this->owner, 'platform_admin');
    $this->owner->update(['status' => 'SUSPENDED']);
    $this->post('http://admin.localhost/platform/settings/kyc', $this->payload)->assertForbidden();
    expect(fn () => app(UpdatePlatformKycSettingsAction::class)->execute(true, 1, KycReviewMode::Automatic, true, $this->owner))->toThrow(HttpException::class);
    $this->owner->update(['status' => 'ACTIVE']);
    AdminMembership::query()->where('admin_user_id', $this->owner->id)->update(['status' => 'SUSPENDED']);
    $this->actingAs($this->owner, 'platform_admin')->get('http://admin.localhost/platform/settings/kyc')->assertForbidden();
    expect(fn () => app(UpdatePlatformKycSettingsAction::class)->execute(true, 1, KycReviewMode::Automatic, true, $this->owner))->toThrow(HttpException::class);
});

it('rejects client tenant scope invalid limits and unconfirmed automatic policies', function (): void {
    $this->actingAs($this->owner, 'platform_admin')->post('http://admin.localhost/platform/settings/kyc', $this->payload + ['tenant_id' => $this->company->id])->assertSessionHasErrors('tenant_id');
    foreach ([0, 101] as $limit) {
        $this->post('http://admin.localhost/platform/settings/kyc', [...$this->payload, 'max_accounts_per_identity' => $limit])->assertSessionHasErrors('max_accounts_per_identity');
        expect(fn () => DB::transaction(fn () => PlatformKycSetting::current()->update(['max_accounts_per_identity' => $limit])))->toThrow(QueryException::class);
    }
    expect(fn () => app(UpdatePlatformKycSettingsAction::class)->execute(true, 1, KycReviewMode::Automatic, false, $this->owner))->toThrow(DomainException::class);
    expect(AuditLog::query()->where('action', 'PLATFORM_KYC_SETTINGS_UPDATED')->count())->toBe(0);
});
