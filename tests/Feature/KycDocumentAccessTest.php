<?php

use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Kyc\TenantKycQueueQuery;
use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->application = app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'MY', 'DOCUMENT-1234', kycTestImage('front.jpg'), kycTestImage('back.jpg'));
});

function phaseThreeAdmin(Tenant $tenant, string $role, string $email): AdminUser
{
    $admin = AdminUser::query()->create(['name' => $role, 'email' => $email, 'password' => Hash::make('local-password'), 'status' => AdminUserStatus::Active]);
    AdminMembership::query()->create(['admin_user_id' => $admin->id, 'scope_type' => ScopeType::Tenant, 'scope_id' => $tenant->id, 'role_id' => Role::query()->where('name', $role)->firstOrFail()->id, 'status' => MembershipStatus::Active]);

    return $admin;
}

it('requires document permission and recent password confirmation', function (): void {
    $support = phaseThreeAdmin($this->tenant, 'SUPPORT', 'support@a.localhost');
    $this->actingAs($support, 'tenant_admin')->post("http://a.localhost/admin/kyc/{$this->application->id}/documents/front/access")->assertForbidden();

    $this->actingAs($this->owner, 'tenant_admin')->post("http://a.localhost/admin/kyc/{$this->application->id}/documents/front/access")->assertForbidden();
    $this->actingAs($this->owner, 'tenant_admin')->post('http://a.localhost/admin/recent-auth', ['password' => 'wrong'])->assertSessionHasErrors('form');
    $this->actingAs($this->owner, 'tenant_admin')->post('http://a.localhost/admin/recent-auth', ['password' => 'local-password'])->assertRedirect();
    $response = $this->actingAs($this->owner, 'tenant_admin')->post("http://a.localhost/admin/kyc/{$this->application->id}/documents/front/access")->assertRedirect();
    $signedUrl = $response->headers->get('Location');
    $this->actingAs($this->owner, 'tenant_admin')->get($signedUrl)->assertOk()->assertHeader('cache-control', 'no-store, private');

    $audit = AuditLog::query()->where('action', 'KYC_DOCUMENT_VIEWED')->sole();
    expect($audit->after_data)->toMatchArray(['document_side' => 'FRONT'])
        ->and($audit->toJson())->not->toContain($signedUrl, $this->application->front_object_key, 'DOCUMENT-1234');
});

it('expires temporary document access and rechecks recent authentication', function (): void {
    config(['kyc.document_access_ttl_seconds' => 1]);
    $sessionKey = "tenant_admin.recent_auth_at.{$this->owner->id}";
    $response = $this->withSession([$sessionKey => now()->getTimestamp()])->actingAs($this->owner, 'tenant_admin')
        ->post("http://a.localhost/admin/kyc/{$this->application->id}/documents/back/access");
    $url = $response->headers->get('Location');
    $this->travel(2)->seconds();
    $this->withSession([$sessionKey => now()->subHour()->getTimestamp()])->actingAs($this->owner, 'tenant_admin')->get($url)->assertForbidden();
});

it('returns a safe denial for another tenant application UUID', function (): void {
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $userB = User::query()->where('tenant_id', $tenantB->id)->firstOrFail();
    $applicationB = app(SubmitKycApplicationAction::class)->execute($tenantB, $userB, 'MY', 'OTHER-TENANT', kycTestImage('front.jpg'), kycTestImage('back.jpg'));
    $sessionKey = "tenant_admin.recent_auth_at.{$this->owner->id}";

    $this->withSession([$sessionKey => now()->getTimestamp()])->actingAs($this->owner, 'tenant_admin')
        ->post("http://a.localhost/admin/kyc/{$applicationB->id}/documents/front/access")->assertNotFound();
    $this->actingAs($this->owner, 'tenant_admin')->get("http://a.localhost/admin/kyc/{$applicationB->id}")->assertNotFound();
});

it('re-evaluates reviewer membership on every sensitive request', function (): void {
    $reviewer = phaseThreeAdmin($this->tenant, 'KYC_REVIEWER', 'reviewer@a.localhost');
    AdminMembership::query()->where('admin_user_id', $reviewer->id)->update(['status' => MembershipStatus::Suspended]);

    $this->withSession(["tenant_admin.recent_auth_at.{$reviewer->id}" => now()->getTimestamp()])->actingAs($reviewer, 'tenant_admin')
        ->post("http://a.localhost/admin/kyc/{$this->application->id}/documents/front/access")->assertForbidden();
});

it('keeps KYC role permissions separated by responsibility', function (): void {
    $reviewer = phaseThreeAdmin($this->tenant, 'KYC_REVIEWER', 'reviewer2@a.localhost');
    $support = phaseThreeAdmin($this->tenant, 'SUPPORT', 'support2@a.localhost');
    $finance = phaseThreeAdmin($this->tenant, 'FINANCE_VIEWER', 'finance@a.localhost');

    $this->actingAs($reviewer, 'tenant_admin')->post("http://a.localhost/admin/kyc/{$this->application->id}/approve")->assertRedirect();
    expect(KycApplication::query()->whereKey($this->application->id)->firstOrFail()->review_status->value)->toBe('APPROVED');
    $this->actingAs($support, 'tenant_admin')->post("http://a.localhost/admin/kyc/{$this->application->id}/approve")->assertForbidden();
    $this->actingAs($finance, 'tenant_admin')->get('http://a.localhost/admin/kyc')->assertForbidden();
});

it('searches queue contacts safely and never emits sensitive persistence fields', function (): void {
    $page = app(TenantKycQueueQuery::class)->paginate($this->tenant->id, 'user@a.localhost', 'PENDING', null);
    $detail = app(TenantKycQueueQuery::class)->detail($this->tenant->id, $this->application->id);
    $json = json_encode([$page->items(), $detail]);

    expect($page->total())->toBe(1)
        ->and($json)->not->toContain('identity_number_encrypted', 'identity_hash', 'front_object_key', 'back_object_key', 'ocr_result_encrypted', $this->application->front_object_key);
});
