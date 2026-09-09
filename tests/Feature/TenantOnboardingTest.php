<?php

use App\Application\Tenant\ActivateTenantAction;
use App\Application\Tenant\ActivateTenantDomainAction;
use App\Application\Tenant\AddCustomDomainAction;
use App\Application\Tenant\ChangePrimaryDomainAction;
use App\Application\Tenant\CheckDomainVerificationAction;
use App\Application\Tenant\DeleteTenantDomainAction;
use App\Application\Tenant\ReactivateTenantAction;
use App\Application\Tenant\SuspendTenantAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Tenant\UpdateTenantLocalesAction;
use App\Domain\Admin\Models\AdminInvitation;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Domain\Tenant\Services\TenantOnboardingStatusService;
use App\Mail\AdminInvitationMail;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => $this->seed());

it('creates a complete draft foundation and sends the owner invitation', function (): void {
    Mail::fake();
    $owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($owner, 'platform_admin')->post('http://admin.localhost/platform/tenants', [
        'name' => 'Acme Cards',
        'slug' => 'acme-cards',
        'owner_name' => 'Acme Owner',
        'owner_email' => 'owner@acme.test',
        'default_locale' => 'en',
        'timezone' => 'Asia/Kuala_Lumpur',
        'default_asset' => 'USD',
    ])->assertRedirect();

    $tenant = Tenant::query()->where('slug', 'acme-cards')->firstOrFail();
    expect($tenant->status)->toBe(TenantStatus::Draft)
        ->and($tenant->domains()->where('hostname', 'acme-cards.localhost')->where('is_primary', true)->exists())->toBeTrue()
        ->and($tenant->branding()->exists())->toBeTrue()
        ->and($tenant->businessSettings()->value('required_security_deposit_amount'))->toBe('0.00000000')
        ->and(AdminInvitation::query()->where('tenant_id', $tenant->id)->where('email', 'owner@acme.test')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'TENANT_CREATED')->where('resource_id', $tenant->id)->exists())->toBeTrue();
    Mail::assertSent(AdminInvitationMail::class, 1);
});

it('rejects reserved or duplicate tenant slugs', function (): void {
    $owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $payload = ['name' => 'Invalid', 'owner_email' => 'owner@example.test', 'default_locale' => 'en', 'timezone' => 'UTC', 'default_asset' => 'USD'];
    $this->actingAs($owner, 'platform_admin')->post('http://admin.localhost/platform/tenants', [...$payload, 'slug' => 'admin'])->assertSessionHasErrors('slug');
    $this->actingAs($owner, 'platform_admin')->post('http://admin.localhost/platform/tenants', [...$payload, 'slug' => 'tenant-a'])->assertSessionHasErrors('slug');
});

it('computes foundation eligibility and prevents incomplete activation', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $actor = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $tenant->update(['status' => TenantStatus::Draft, 'activated_at' => null]);
    $tenant->locales()->update(['enabled' => false, 'is_default' => false]);

    expect(app(TenantOnboardingStatusService::class)->for($tenant)['foundation_ready'])->toBeFalse();
    expect(fn () => app(ActivateTenantAction::class)->execute($tenant, $actor))->toThrow(DomainException::class);
    expect($tenant->fresh()->status)->toBe(TenantStatus::Draft);
});

it('activates an eligible foundation without claiming business readiness', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $actor = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $tenant->update(['status' => TenantStatus::Draft, 'activated_at' => null]);
    $status = app(TenantOnboardingStatusService::class)->for($tenant);

    expect($status['foundation_ready'])->toBeTrue()->and($status['business_ready'])->toBeFalse();
    app(ActivateTenantAction::class)->execute($tenant, $actor);
    expect($tenant->fresh()->status)->toBe(TenantStatus::Active)
        ->and(AuditLog::query()->where('action', 'TENANT_ACTIVATED')->exists())->toBeTrue();
});

it('suspends and reactivates an active tenant without deleting history', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $actor = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $domainCount = $tenant->domains()->count();

    app(SuspendTenantAction::class)->execute($tenant->id, $actor);
    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended)
        ->and($tenant->domains()->count())->toBe($domainCount)
        ->and(AuditLog::query()->where('action', 'TENANT_SUSPENDED')->exists())->toBeTrue();

    app(ReactivateTenantAction::class)->execute($tenant->id, $actor);
    expect($tenant->fresh()->status)->toBe(TenantStatus::Active)
        ->and(AuditLog::query()->where('action', 'TENANT_REACTIVATED')->exists())->toBeTrue();
});

it('updates locale defaults atomically and persists decimal business configuration', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $actor = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    app(UpdateTenantLocalesAction::class)->execute($tenant, ['en', 'zh-CN'], 'zh-CN', $actor);
    app(UpdateTenantBusinessSettingsAction::class)->execute($tenant, [
        'required_security_deposit_amount' => '125.25000000',
        'required_security_deposit_asset' => 'USD',
        'allow_wallet_topup' => false,
        'allow_withdrawal' => false,
    ], $actor);

    expect($tenant->fresh()->default_locale)->toBe('zh-CN')
        ->and($tenant->locales()->where('is_default', true)->count())->toBe(1)
        ->and($tenant->businessSettings()->value('required_security_deposit_amount'))->toBe('125.25000000');
});

it('rejects invalid locale configurations', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $actor = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();

    expect(fn () => app(UpdateTenantLocalesAction::class)->execute($tenant, [], 'en', $actor))->toThrow(DomainException::class)
        ->and(fn () => app(UpdateTenantLocalesAction::class)->execute($tenant, ['en'], 'zh-CN', $actor))->toThrow(DomainException::class);

    $this->actingAs($actor, 'tenant_admin')->post('http://a.localhost/admin/settings/locales', [
        'enabled_locales' => ['en'],
        'default_locale' => 'zh-CN',
    ])->assertSessionHasErrors('default_locale');
});

it('rejects imprecise and negative deposit configuration without creating money tables', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $actor = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $base = ['required_security_deposit_asset' => 'USD', 'allow_wallet_topup' => false, 'allow_withdrawal' => false];

    $this->actingAs($actor, 'tenant_admin')->post('http://a.localhost/admin/settings/business', [...$base, 'required_security_deposit_amount' => '1.123456789'])
        ->assertSessionHasErrors('required_security_deposit_amount');
    expect(fn () => app(UpdateTenantBusinessSettingsAction::class)->execute($tenant, [...$base, 'required_security_deposit_amount' => '-1.00'], $actor))->toThrow(DomainException::class)
        ->and(Schema::hasTable('wallets'))->toBeFalse()
        ->and(Schema::hasTable('ledger_entries'))->toBeFalse();
});

it('enforces the custom domain state machine and tenant ownership', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $actor = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $domain = app(AddCustomDomainAction::class)->execute($tenantA, 'CARDS.EXAMPLE.TEST.', $actor);

    expect($domain->hostname)->toBe('cards.example.test')->and($domain->status)->toBe(TenantDomainStatus::PendingVerification);
    expect(fn () => app(ActivateTenantDomainAction::class)->execute($tenantA->id, $domain->id, $actor))->toThrow(DomainException::class);
    expect(fn () => app(CheckDomainVerificationAction::class)->execute($tenantB->id, $domain->id, $actor))->toThrow(DomainException::class);
    expect(app(CheckDomainVerificationAction::class)->execute($tenantA->id, $domain->id, $actor))->toBeTrue();
    app(ActivateTenantDomainAction::class)->execute($tenantA->id, $domain->id, $actor);
    app(ChangePrimaryDomainAction::class)->execute($tenantA->id, $domain->id, $actor);

    expect($domain->fresh()->status)->toBe(TenantDomainStatus::Active)
        ->and($domain->fresh()->is_primary)->toBeTrue()
        ->and(TenantDomain::query()->where('tenant_id', $tenantA->id)->where('is_primary', true)->count())->toBe(1);
});

it('rejects duplicate hostnames and protects the immutable system domain', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $actor = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();

    expect(fn () => app(AddCustomDomainAction::class)->execute($tenantA, 'b.localhost', $actor))->toThrow(DomainException::class);
    $systemDomain = $tenantA->domains()->where('is_primary', true)->firstOrFail();
    expect(fn () => app(DeleteTenantDomainAction::class)->execute($tenantA->id, $systemDomain->id, $actor))->toThrow(DomainException::class);

    $unverified = app(AddCustomDomainAction::class)->execute($tenantA, 'pending.example.test', $actor);
    expect(fn () => app(ChangePrimaryDomainAction::class)->execute($tenantA->id, $unverified->id, $actor))->toThrow(DomainException::class)
        ->and($tenantB->id)->not->toBe($tenantA->id);
});

it('never treats client tenant ids as the settings mutation scope', function (): void {
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $adminA = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $beforeB = $tenantB->branding()->value('brand_name');

    $this->actingAs($adminA, 'tenant_admin')->post('http://a.localhost/admin/settings/branding', [
        'tenant_id' => $tenantB->id,
        'brand_name' => 'Tenant A Updated',
        'primary_color' => '#123456',
        'support_email' => 'help@a.localhost',
        'support_url' => null,
        'copyright_text' => null,
    ])->assertRedirect();

    expect($tenantB->branding()->value('brand_name'))->toBe($beforeB)
        ->and(Tenant::query()->where('slug', 'tenant-a')->firstOrFail()->branding()->value('brand_name'))->toBe('Tenant A Updated');
});
