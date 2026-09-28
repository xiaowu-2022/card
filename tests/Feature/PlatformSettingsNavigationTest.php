<?php

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
});

it('opens the settings entry and each independent authorized tab without external calls', function () {
    $this->actingAs($this->owner, 'platform_admin')->get('http://admin.localhost/platform/settings')->assertRedirect('/platform/settings/assets');
    foreach (['assets' => 'AssetSettings', 'domains' => 'Domains', 'sms' => 'NotificationProfiles', 'email' => 'NotificationProfiles', 'oss' => 'OssSettings', 'kyc' => 'KycSettings'] as $tab => $component) {
        $this->get('http://admin.localhost/platform/settings/'.$tab)->assertOk()->assertInertia(fn ($page) => $page->component('platform/'.$component));
    }
    Http::assertNothingSent();
});

it('routes storage-only administrators to OSS without granting other configuration access', function () {
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'tenant.manage')->value('id'))->delete();
    $this->actingAs($this->owner->fresh(), 'platform_admin')->get('http://admin.localhost/platform/settings')->assertRedirect('/platform/settings/oss');
    $this->get('http://admin.localhost/platform/settings/oss')->assertOk();
    foreach (['assets', 'domains', 'sms', 'email', 'kyc'] as $tab) {
        $this->get('http://admin.localhost/platform/settings/'.$tab)->assertForbidden();
    }
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'storage.manage')->value('id'))->delete();
    $this->get('http://admin.localhost/platform/settings')->assertForbidden();
});

it('does not grant storage configuration to ordinary company configuration permission', function () {
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'storage.manage')->value('id'))->delete();
    $this->actingAs($this->owner->fresh(), 'platform_admin')->get('http://admin.localhost/platform/settings')->assertRedirect('/platform/settings/assets');
    $this->get('http://admin.localhost/platform/settings/oss')->assertForbidden();
});

it('rejects company administrators and inactive accounts at the shared entry', function () {
    $this->get('http://admin.localhost/platform/settings')->assertRedirect('/platform/login');
    $this->actingAs(AdminUser::where('email', 'owner@a.localhost')->firstOrFail(), 'platform_admin')->get('http://admin.localhost/platform/settings')->assertForbidden();
    $this->owner->update(['status' => AdminUserStatus::Suspended]);
    $this->actingAs($this->owner->fresh(), 'platform_admin')->get('http://admin.localhost/platform/settings')->assertForbidden();
});
