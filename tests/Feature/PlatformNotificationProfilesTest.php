<?php

use App\Application\Notification\AssignCompanyNotificationProfileAction;
use App\Application\Notification\TenantEmailSettingsQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notification\Models\PlatformEmailProfile;
use App\Domain\Notification\Models\PlatformSmsProfile;
use App\Domain\Notification\Models\TenantEmailSetting;
use App\Domain\Notification\Models\TenantSmsSetting;
use App\Domain\Notification\Services\TenantEmailPolicy;
use App\Domain\Notification\Services\TenantSmsPolicy;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    $this->owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->company = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->other = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
});

it('creates multiple profiles and assigns them independently without exposing credentials or sending', function (): void {
    $this->actingAs($this->owner, 'platform_admin');
    foreach (['One', 'Two'] as $name) {
        $this->post('http://admin.localhost/platform/settings/email', ['name' => $name, 'enabled' => true, 'from_address' => strtolower($name).'@example.test', 'from_name' => $name, 'smtp_token' => 'test-token-'.$name, 'daily_recipient_limit' => 5, 'current_password' => 'local-password'])->assertRedirect('/platform/settings/email')->assertSessionHasNoErrors();
        $this->post('http://admin.localhost/platform/settings/sms', ['name' => $name, 'enabled' => true, 'access_key_id' => 'TestKey'.$name, 'access_key_secret' => 'test-secret-'.$name, 'sign_name' => $name, 'verification_template_code' => 'SMS_12345', 'resend_interval_seconds' => 60, 'code_ttl_seconds' => 600, 'current_password' => 'local-password'])->assertRedirect('/platform/settings/sms')->assertSessionHasNoErrors();
    }
    expect(PlatformEmailProfile::query()->count())->toBe(2)->and(PlatformSmsProfile::query()->count())->toBe(2);
    $one = PlatformEmailProfile::query()->where('name', 'One')->sole();
    $two = PlatformEmailProfile::query()->where('name', 'Two')->sole();
    $sms = PlatformSmsProfile::query()->where('name', 'One')->sole();
    $assign = app(AssignCompanyNotificationProfileAction::class);
    foreach ([$this->company, $this->other] as $company) {
        $assign->execute($company->id, 'email', $one->id, $this->owner);
    }
    $assign->execute($this->company->id, 'sms', $sms->id, $this->owner);
    $this->post('http://admin.localhost/platform/tenants/'.$this->company->id.'/configuration/settings/email', ['profile_id' => $two->id, 'current_password' => 'local-password'])->assertRedirect()->assertSessionHasNoErrors();
    expect(app(TenantEmailPolicy::class)->settings($this->company->id)->id)->toBe($two->id)
        ->and(app(TenantEmailPolicy::class)->settings($this->other->id)->id)->toBe($one->id)
        ->and(app(TenantSmsPolicy::class)->settings($this->company->id)->id)->toBe($sms->id);
    $companyProps = app(TenantEmailSettingsQuery::class)->execute($this->company->id);
    expect($companyProps)->not->toHaveKey('profiles')->and($companyProps)->not->toHaveKey('smtp_token');
    $props = $this->get('http://admin.localhost/platform/settings/email')->assertOk()->getContent();
    expect($props)->not->toContain('test-token-One', 'test-token-Two', $one->getRawOriginal('smtp_token'));
    expect(AuditLog::query()->where('action', 'COMPANY_NOTIFICATION_PROFILE_ASSIGNED')->count())->toBe(4);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

it('fails closed for shared disabled profiles missing selection and invalid company assignments', function (): void {
    $profile = PlatformEmailProfile::query()->create(['name' => 'Shared', 'enabled' => true, 'from_address' => 'sender@example.test', 'from_name' => 'Shared', 'smtp_token' => 'test-token', 'daily_recipient_limit' => 5, 'configuration_version' => (string) Str::uuid()]);
    $assign = app(AssignCompanyNotificationProfileAction::class);
    foreach ([$this->company, $this->other] as $company) {
        $assign->execute($company->id, 'email', $profile->id, $this->owner);
    }
    $profile->update(['enabled' => false]);
    foreach ([$this->company, $this->other] as $company) {
        expect(app(TenantEmailPolicy::class)->settings($company->id)->available())->toBeFalse();
    }
    $this->actingAs($this->owner, 'platform_admin');
    $url = 'http://admin.localhost/platform/tenants/'.$this->company->id.'/configuration/settings/email';
    $this->post($url, ['profile_id' => $profile->id, 'current_password' => 'local-password'])->assertSessionHasErrors('form');
    $this->post($url, ['profile_id' => (string) Str::uuid(), 'current_password' => 'local-password'])->assertNotFound();
    $this->post($url, ['profile_id' => null, 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    $this->post($url, ['profile_id' => null, 'current_password' => 'local-password'])->assertSessionHasNoErrors();
    expect(app(TenantEmailPolicy::class)->settings($this->company->id))->toBeNull();
    $companyOwner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($companyOwner, 'platform_admin')->get('http://admin.localhost/platform/settings/email')->assertForbidden();
    $this->post($url, ['profile_id' => null, 'current_password' => 'local-password'])->assertForbidden();
    $this->actingAs($companyOwner, 'tenant_admin')->get('http://a.localhost/admin/settings/email')->assertInertia(fn ($page) => $page->missing('settings.email.profiles'));
});

it('preserves encrypted legacy credentials exact bindings and email test versions during migration', function (): void {
    $migration = require database_path('migrations/2026_09_14_001200_create_platform_notification_profiles.php');
    $migration->down();
    $email = TenantEmailSetting::query()->create(['tenant_id' => $this->company->id, 'enabled' => true, 'from_address' => 'legacy@example.test', 'from_name' => 'Legacy', 'smtp_token' => 'legacy-test-token', 'daily_recipient_limit' => 4, 'configuration_version' => (string) Str::uuid()]);
    $sms = TenantSmsSetting::query()->create(['tenant_id' => $this->company->id, 'enabled' => true, 'access_key_id' => 'LegacyKey', 'access_key_secret' => 'legacy-secret', 'sign_name' => 'Legacy', 'verification_template_code' => 'SMS_123', 'resend_interval_seconds' => 60, 'code_ttl_seconds' => 600]);
    $migration->up();
    $resolved = app(TenantEmailPolicy::class)->settings($this->company->id);
    expect($resolved->getRawOriginal('smtp_token'))->toBe($email->getRawOriginal('smtp_token'))
        ->and($resolved->smtp_token)->toBe('legacy-test-token')->and($resolved->configuration_version)->toBe($email->configuration_version)
        ->and(app(TenantSmsPolicy::class)->settings($this->company->id)->getRawOriginal('access_key_secret'))->toBe($sms->getRawOriginal('access_key_secret'))
        ->and(app(TenantEmailPolicy::class)->settings($this->other->id))->toBeNull();
});
