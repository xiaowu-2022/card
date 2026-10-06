<?php

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed();
    config(['images.storage_driver' => 'server']);
    Storage::fake('local');
    Storage::fake('public');
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->url = "http://admin.localhost/platform/tenants/{$this->tenant->id}/configuration/settings/branding";
    $this->fields = ['brand_name' => 'Test brand', 'primary_color' => '#123456', 'support_email' => null, 'support_url' => null, 'copyright_text' => null];
});

it('saves a separate APK logo and retains it when no new upload is provided', function (): void {
    $before = $this->tenant->branding->logo_object_key;
    $this->actingAs($this->owner, 'platform_admin')->post($this->url, $this->fields + ['apk_logo' => UploadedFile::fake()->createWithContent('apk.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAQAAAAEACAIAAADTED8xAAADGklEQVR4nO3OQQ0AMBAEofVvupUxjyNBAHsbnNUPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPIPQBWUwO8hhpVuIAAAAASUVORK5CYII='))])
        ->assertRedirect()->assertSessionHasNoErrors();
    $key = $this->tenant->branding->fresh()->apk_logo_object_key;
    expect($key)->not->toBeNull()->and($this->tenant->branding->fresh()->logo_object_key)->toBe($before);
    $this->post($this->url, $this->fields)->assertRedirect()->assertSessionHasNoErrors();
    expect($this->tenant->branding->fresh()->apk_logo_object_key)->toBe($key);
    $this->getJson('http://a.localhost/api/mobile/v1/bootstrap')->assertOk()->assertJsonPath('tenant.slug', 'tenant-a')->assertJsonPath('tenant.apkLogoUrl', fn ($url) => is_string($url) && $url !== '');
    $this->getJson('http://b.localhost/api/mobile/v1/bootstrap')->assertOk()->assertJsonPath('tenant.apkLogoUrl', null);
});

it('rejects non-square APK logos and unauthorized writes', function (): void {
    $this->actingAs($this->owner, 'platform_admin')->post($this->url, $this->fields + ['apk_logo' => UploadedFile::fake()->createWithContent('apk.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAQAAAACACAIAAABr1yBdAAABmklEQVR4nO3OQQ0AMBAEofVvupUxjyNBAHsbnNUPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPINQPIPQBmlOHclnTyu0AAAAASUVORK5CYII='))])
        ->assertSessionHasErrors('apk_logo');
    expect($this->tenant->branding->fresh()->apk_logo_object_key)->toBeNull();
    $this->owner->update(['status' => 'SUSPENDED']);
    $this->actingAs($this->owner->fresh(), 'platform_admin')->post($this->url, $this->fields)->assertForbidden();
});

it('saves and audits a company APK name independently of website branding', function (): void {
    $this->actingAs($this->owner, 'platform_admin')->post($this->url, $this->fields + ['apk_name' => '测试 App'])
        ->assertSessionHasNoErrors();
    expect($this->tenant->branding->fresh()->apk_name)->toBe('测试 App');
    $this->getJson('http://a.localhost/api/mobile/v1/bootstrap')->assertOk()->assertJsonPath('tenant.apkName', '测试 App');
    $this->getJson('http://b.localhost/api/mobile/v1/bootstrap')->assertOk()->assertJsonPath('tenant.apkName', null);
    $audit = \App\Domain\Audit\Models\AuditLog::where('action', 'TENANT_BRANDING_UPDATED')->latest('created_at')->firstOrFail();
    expect($audit->after_data['apk_name'])->toBe('测试 App');
    $this->post($this->url, $this->fields)->assertSessionHasNoErrors();
    expect($this->tenant->branding->fresh()->apk_name)->toBe('测试 App');
});

it('rejects invalid APK names without changing stored branding', function (string $name): void {
    $this->actingAs($this->owner, 'platform_admin')->post($this->url, $this->fields + ['apk_name' => $name])
        ->assertSessionHasErrors('apk_name');
    expect($this->tenant->branding->fresh()->apk_name)->toBeNull();
})->with(['too long' => str_repeat('名', 61), 'markup' => '<app>', 'control' => "Bad\nApp"]);
