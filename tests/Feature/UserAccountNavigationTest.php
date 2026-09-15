<?php

use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed();
    $this->user = User::query()->where('email', 'user@a.localhost')->firstOrFail();
});

it('gates account settings by login and the host company', function (): void {
    $this->get('http://a.localhost/account/settings')->assertRedirect('/login');
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/account/settings')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('user/AccountSettings')
        ->where('auth.user.id', $this->user->id)->missing('information')->missing('kyc'));
    $this->get('http://b.localhost/account/settings')->assertRedirect('/login');
});

it('keeps restricted security and logout access without opening settings', function (): void {
    $this->user->update(['status' => UserStatus::Suspended]);
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/account/settings')->assertRedirect('/account/restricted');
    $this->get('http://a.localhost/account/security')->assertOk()->assertInertia(fn (Assert $page) => $page->where('information.canEdit', false));
    $this->post('http://a.localhost/logout')->assertRedirect();
    $this->assertGuest('tenant_user');
});

it('keeps settings unavailable when its company is suspended', function (): void {
    Tenant::query()->whereKey($this->user->tenant_id)->firstOrFail()->update(['status' => TenantStatus::Suspended]);
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/account/settings')->assertRedirect('/account/restricted');
    $this->get('http://a.localhost/account/security')->assertOk();
});

it('shares only scoped verification status on account pages', function (): void {
    $status = app(KycStatusService::class)->forUser($this->user->tenant_id, $this->user->id)->value;
    foreach (['/account', '/account/security'] as $path) {
        $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost'.$path)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('kycStatus', $status)
                ->missing('kyc')->missing('identity_number')->missing('documents'));
    }
});

it('uses only the fixed account security verification return source', function (): void {
    foreach (['account-security' => '/account/security', 'https://example.com' => '/account', '/account/settings' => '/account', '' => '/account'] as $source => $expected) {
        $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/kyc?'.http_build_query(['from' => $source]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('backHref', $expected));
    }
});
