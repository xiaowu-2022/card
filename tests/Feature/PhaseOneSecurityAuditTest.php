<?php

use App\Application\Admin\AcceptAdminInvitationAction;
use App\Application\Admin\CreateAdminInvitationAction;
use App\Application\Admin\IssueAdminInvitationAction;
use App\Application\Admin\ResendAdminInvitationAction;
use App\Application\Tenant\CreateTenantAction;
use App\Application\Tenant\ReactivateTenantAction;
use App\Domain\Admin\Enums\InvitationStatus;
use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminInvitation;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenant\Contracts\DomainVerificationService;
use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Enums\TenantDomainType;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Domain\Tenant\Services\HostnameNormalizer;
use App\Infrastructure\Providers\Domain\LocalDomainVerificationService;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => $this->seed());

it('keeps domain lifecycle status and primary designation independent', function (): void {
    expect(array_column(TenantDomainStatus::cases(), 'value'))->toBe([
        'PENDING_VERIFICATION', 'VERIFIED', 'ACTIVE', 'FAILED', 'DISABLED',
    ])->not->toContain('PRIMARY');

    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $secondary = TenantDomain::query()->create([
        'tenant_id' => $tenant->id,
        'hostname' => 'secondary.example.test',
        'domain_type' => TenantDomainType::CustomDomain,
        'status' => TenantDomainStatus::Active,
        'is_primary' => false,
        'verified_at' => now(),
    ]);

    expect($secondary->status)->toBe(TenantDomainStatus::Active)
        ->and($secondary->is_primary)->toBeFalse()
        ->and($tenant->domains()->where('status', TenantDomainStatus::Active)->where('is_primary', true)->count())->toBe(1);
});

it('preserves exact roles and password when an existing tenant owner accepts support access', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $existing = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $passwordHash = $existing->getRawOriginal('password');
    $issued = app(IssueAdminInvitationAction::class)->execute(
        $tenantB,
        $existing->email,
        Role::query()->where('name', 'SUPPORT')->firstOrFail(),
        AdminUser::query()->where('email', 'owner@b.localhost')->firstOrFail(),
    );

    app(AcceptAdminInvitationAction::class)->execute($issued->rawToken, $tenantB->id, 'Ignored Name', 'local-password');
    $memberships = AdminMembership::query()->with('role')->where('admin_user_id', $existing->id)->get()->keyBy('scope_id');

    expect(AdminUser::query()->where('email', $existing->email)->count())->toBe(1)
        ->and($existing->fresh()->getRawOriginal('password'))->toBe($passwordHash)
        ->and($memberships[$tenantA->id]->role->name)->toBe('TENANT_OWNER')
        ->and($memberships[$tenantB->id]->role->name)->toBe('SUPPORT');
});

it('rejects platform roles at the tenant invitation contract boundary', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $actor = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();

    expect(fn () => app(IssueAdminInvitationAction::class)->execute(
        $tenant,
        'scope-confusion@example.test',
        Role::query()->where('name', 'PLATFORM_ADMIN')->firstOrFail(),
        $actor,
    ))->toThrow(DomainException::class);
});

it('allows only one consumption of an invitation token', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $issued = app(IssueAdminInvitationAction::class)->execute($tenant, 'atomic@example.test', Role::query()->where('name', 'SUPPORT')->firstOrFail(), AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail());

    app(AcceptAdminInvitationAction::class)->execute($issued->rawToken, $tenant->id, 'Atomic Admin', 'StrongPass1234');
    expect(fn () => app(AcceptAdminInvitationAction::class)->execute($issued->rawToken, $tenant->id, 'Atomic Admin', 'StrongPass1234'))->toThrow(DomainException::class);

    $admin = AdminUser::query()->where('email', 'atomic@example.test')->firstOrFail();
    expect(AdminMembership::query()->where('admin_user_id', $admin->id)->where('scope_id', $tenant->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'ADMIN_INVITATION_ACCEPTED')->where('resource_id', $issued->invitation->id)->count())->toBe(1)
        ->and($issued->invitation->fresh()->status)->toBe(InvitationStatus::Accepted);
});

it('rejects the old token after resend and accepts only its replacement', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $actor = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $old = app(IssueAdminInvitationAction::class)->execute($tenant, 'replacement@example.test', Role::query()->where('name', 'SUPPORT')->firstOrFail(), $actor);
    $replacement = app(ResendAdminInvitationAction::class)->execute($tenant, $old->invitation->id, $actor);

    expect(fn () => app(AcceptAdminInvitationAction::class)->execute($old->rawToken, $tenant->id, 'Replacement', 'StrongPass1234'))->toThrow(DomainException::class);
    app(AcceptAdminInvitationAction::class)->execute($replacement->rawToken, $tenant->id, 'Replacement', 'StrongPass1234');
    expect($replacement->invitation->fresh()->status)->toBe(InvitationStatus::Accepted);
});

it('accepts an invitation on any active domain resolving to the invited tenant', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    TenantDomain::query()->create([
        'tenant_id' => $tenant->id,
        'hostname' => 'alternate.example.test',
        'domain_type' => TenantDomainType::CustomDomain,
        'status' => TenantDomainStatus::Active,
        'is_primary' => false,
        'verified_at' => now(),
    ]);
    $issued = app(IssueAdminInvitationAction::class)->execute($tenant, 'alternate@example.test', Role::query()->where('name', 'SUPPORT')->firstOrFail(), AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail());

    $this->get("http://alternate.example.test/admin/invitations/{$issued->rawToken}")->assertOk();
});

it('rate limits existing identity confirmation without persisting or logging raw tokens', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $issued = app(CreateAdminInvitationAction::class)->execute($tenant, 'owner@a.localhost', Role::query()->where('name', 'SUPPORT')->firstOrFail(), AdminUser::query()->where('email', 'owner@b.localhost')->firstOrFail());

    foreach (range(1, 5) as $_) {
        $this->post("http://b.localhost/admin/invitations/{$issued->rawToken}", [
            'name' => 'Tenant Owner A', 'password' => 'wrong-password', 'password_confirmation' => 'wrong-password',
        ])->assertSessionHasErrors('form');
    }
    $this->post("http://b.localhost/admin/invitations/{$issued->rawToken}", [
        'name' => 'Tenant Owner A', 'password' => 'local-password', 'password_confirmation' => 'local-password',
    ])->assertSessionHasErrors('password', fn (string $message) => str_contains($message, 'Too many identity confirmation attempts'));

    expect(AdminInvitation::query()->where('token_hash', $issued->rawToken)->exists())->toBeFalse()
        ->and(AuditLog::query()->get()->toJson())->not->toContain($issued->rawToken);
});

it('keeps tenant login throttles isolated by tenant scope', function (): void {
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $admin = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    AdminMembership::query()->create([
        'admin_user_id' => $admin->id,
        'scope_type' => ScopeType::Tenant,
        'scope_id' => $tenantB->id,
        'role_id' => Role::query()->where('name', 'SUPPORT')->firstOrFail()->id,
        'status' => MembershipStatus::Active,
    ]);

    foreach (range(1, 5) as $_) {
        $this->post('http://a.localhost/admin/login', ['email' => $admin->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    }
    $this->post('http://b.localhost/admin/login', ['email' => $admin->email, 'password' => 'local-password'])->assertRedirect('/admin/demo');
});

it('commits a valid tenant foundation before attempting external mail delivery', function (): void {
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP unavailable'));
    $actor = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $data = [
        'name' => 'Mail Failure Tenant', 'slug' => 'mail-failure', 'owner_email' => 'mail-failure@example.test',
        'default_locale' => 'en', 'timezone' => 'UTC', 'default_asset' => 'USD',
    ];

    expect(fn () => app(CreateTenantAction::class)->execute($data, $actor))->toThrow(RuntimeException::class);
    $tenant = Tenant::query()->where('slug', 'mail-failure')->firstOrFail();
    expect($tenant->status)->toBe(TenantStatus::Draft)
        ->and($tenant->domains()->exists())->toBeTrue()
        ->and($tenant->branding()->exists())->toBeTrue()
        ->and($tenant->locales()->exists())->toBeTrue()
        ->and($tenant->businessSettings()->exists())->toBeTrue()
        ->and($tenant->kycSettings()->exists())->toBeTrue()
        ->and(AdminInvitation::query()->where('tenant_id', $tenant->id)->where('status', InvitationStatus::Pending)->exists())->toBeTrue();
});

it('rejects unsafe, unicode, wildcard, and platform-owned custom hostnames without network access', function (string $hostname): void {
    expect(fn () => app(HostnameNormalizer::class)->normalize($hostname))->toThrow(DomainException::class);
})->with([
    'scheme' => 'https://cards.example.com',
    'path' => 'cards.example.com/path',
    'port' => 'cards.example.com:8080',
    'wildcard' => '*.example.com',
    'userinfo' => 'user@example.com',
    'unicode' => 'cárds.example.com',
    'platform host' => 'admin.localhost',
    'reserved api host' => 'api.localhost',
    'reserved support host' => 'support.localhost',
]);

it('uses the non-network local domain verifier and does not accept internal addresses', function (): void {
    $verifier = app(DomainVerificationService::class);
    expect($verifier)->toBeInstanceOf(LocalDomainVerificationService::class)
        ->and($verifier->verify('127.0.0.1', 'vc-verify-test'))->toBeFalse()
        ->and($verifier->verify('localhost', 'vc-verify-test'))->toBeFalse();
});

it('does not reactivate a closed tenant through the ordinary action', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenant->update(['status' => TenantStatus::Closed, 'closed_at' => now()]);
    expect(fn () => app(ReactivateTenantAction::class)->execute($tenant->id, AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail()))
        ->toThrow(DomainException::class);
});

it('uses host-only root-path sessions and does not trust forwarded hosts by default', function (): void {
    expect(blank(config('session.domain')))->toBeTrue()
        ->and(config('session.path'))->toBe('/')
        ->and(config('session.http_only'))->toBeTrue();
});

it('ignores mass-assignment fields outside request allowlists', function (): void {
    Mail::fake();
    $platformOwner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($platformOwner, 'platform_admin')->post('http://admin.localhost/platform/tenants', [
        'name' => 'Allowlisted Tenant', 'slug' => 'allowlisted', 'owner_email' => 'allowlisted@example.test',
        'default_locale' => 'en', 'timezone' => 'UTC', 'default_asset' => 'USD',
        'status' => 'ACTIVE', 'tenant_id' => Tenant::query()->where('slug', 'tenant-b')->value('id'),
    ])->assertRedirect();

    expect(Tenant::query()->where('slug', 'allowlisted')->firstOrFail()->status)->toBe(TenantStatus::Draft);
});
