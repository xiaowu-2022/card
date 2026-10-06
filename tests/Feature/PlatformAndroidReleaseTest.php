<?php

use App\Application\Tenant\AndroidAppRelease;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->withoutVite();
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->seed();
    $this->releaseTenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->releaseOther = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->releaseUrl = 'http://admin.localhost/platform/tenants/'.$this->releaseTenant->id.'/configuration/settings/android-release';
    $this->releaseFields = [
        'appId' => '__UNI__TEST', 'versionCode' => 2359, 'versionName' => '2.3.59',
        'revision' => app(AndroidAppRelease::class)->revision(null), 'confirmed' => true,
    ];
});

afterEach(function (): void {
    foreach ([$this->releaseTenant, $this->releaseOther] as $tenant) {
        File::deleteDirectory(storage_path('app/app-releases/'.$tenant->id));
        File::deleteDirectory(public_path('app-releases/'.$tenant->id));
    }
});

function platformApk(string $content = 'PK-synthetic-offline-apk'): UploadedFile
{
    return UploadedFile::fake()->createWithContent('specpay.apk', $content);
}

it('publishes from a scoped platform editor with atomic audit and read-only discovery', function (): void {
    $this->actingAs($this->owner, 'platform_admin')->post($this->releaseUrl, $this->releaseFields + [
        'tenant_id' => $this->releaseOther->id,
    ], ['X-Admin-Dialog' => '1', 'Accept' => 'application/json'])->assertOk()->assertJsonPath('saved', true);
    $this->getJson('http://a.localhost/api/mobile/v1/app-release')->assertOk()
        ->assertJsonPath('versionCode', 2359)->assertJsonPath('appId', '__UNI__TEST');
    $this->getJson('http://b.localhost/api/mobile/v1/app-release')->assertStatus(503);
    $audit = AuditLog::where('action', 'ANDROID_RELEASE_PUBLISHED')->sole();
    expect($audit->tenant_id)->toBe($this->releaseTenant->id)
        ->and($audit->actor_id)->toBe($this->owner->id)
        ->and($audit->after_data['versionCode'])->toBe(2359);
    $this->post($this->releaseUrl, $this->releaseFields)->assertSessionHasNoErrors();
    expect(AuditLog::where('action', 'ANDROID_RELEASE_PUBLISHED')->count())->toBe(1);
    $url = str_replace('android-release', 'branding', $this->releaseUrl);
    $this->get($url, ['X-Admin-Dialog' => '1'])->assertOk()
        ->assertInertia(fn ($page) => $page->where('settings.androidRelease.available', true)->where('settings.androidRelease.current.versionName', '2.3.59'));
    $this->get(str_replace('branding', 'locales', $url), ['X-Admin-Dialog' => '1'])->assertOk()
        ->assertInertia(fn ($page) => $page->missing('settings.androidRelease'));
    expect(DB::table('tenant_android_releases')->count())->toBe(1)
        ->and(AuditLog::where('action', 'ANDROID_RELEASE_PUBLISHED')->count())->toBe(1);
});

it('rejects stale edits, regressions and AppID changes while retaining the published version', function (): void {
    $this->actingAs($this->owner, 'platform_admin')->post($this->releaseUrl, $this->releaseFields)->assertSessionHasNoErrors();
    $next = array_replace($this->releaseFields, ['versionCode' => 2360, 'versionName' => '2.3.60']);
    $this->post($this->releaseUrl, $next)->assertSessionHasErrors('revision');
    $next['revision'] = app(AndroidAppRelease::class)->settings($this->releaseTenant)['revision'];
    foreach ([['versionCode' => 2358], ['versionCode' => 2359], ['appId' => '__UNI__OTHER']] as $invalid) {
        $this->post($this->releaseUrl, array_replace($next, $invalid))->assertSessionHasErrors('versionCode');
    }
    $this->getJson('http://a.localhost/api/mobile/v1/app-release')->assertJsonPath('versionCode', 2359);
    $this->post($this->releaseUrl, $next)->assertSessionHasNoErrors();
    $this->getJson('http://a.localhost/api/mobile/v1/app-release')->assertJsonPath('versionCode', 2360);
});

it('requires platform authority and valid explicit publication input', function (): void {
    $this->postJson($this->releaseUrl, $this->releaseFields)->assertRedirect('http://admin.localhost/platform/login');
    $this->actingAs($this->owner, 'platform_admin');
    foreach ([['confirmed' => false], ['versionCode' => 0], ['appId' => 'cc.specpay.cards'], ['versionName' => 'bad']] as $invalid) {
        $this->post($this->releaseUrl, array_replace($this->releaseFields, $invalid))->assertSessionHasErrors(array_key_first($invalid));
    }
    $this->post($this->releaseUrl, $this->releaseFields + ['apk' => platformApk('not an apk')])->assertSessionHasErrors('apk');
    $this->owner->memberships()->delete();
    $this->post($this->releaseUrl, $this->releaseFields)->assertForbidden();
    expect(DB::table('tenant_android_releases')->count())->toBe(0);
});

it('preserves legacy release metadata without local artifacts and publishes without storing APKs', function (): void {
    $legacy = ['appId' => '__UNI__TEST', 'versionCode' => 2358, 'versionName' => '2.3.58',
        'path' => '/app-releases/'.$this->releaseTenant->id.'/'.str_repeat('a', 64).'.apk'];
    $pointer = storage_path('app/app-releases/'.$this->releaseTenant->id.'/android.json');
    File::ensureDirectoryExists(dirname($pointer));
    File::put($pointer, json_encode($legacy));
    $this->getJson('http://a.localhost/api/mobile/v1/app-release')->assertOk()->assertJsonPath('versionCode', 2358);
    expect(DB::table('tenant_android_releases')->count())->toBe(0);
    $fields = array_replace($this->releaseFields, ['revision' => app(AndroidAppRelease::class)->revision($legacy)]);
    $this->actingAs($this->owner, 'platform_admin')->post($this->releaseUrl, $fields)->assertSessionHasNoErrors();
    $response = $this->getJson('http://a.localhost/api/mobile/v1/app-release')->assertOk()->assertJsonPath('versionCode', 2359);
    expect(is_dir(public_path('app-releases/'.$this->releaseTenant->id)))->toBeFalse();
    expect($response->json('path'))->toMatch('#^/app-releases/'.$this->releaseTenant->id.'/[a-f0-9]{64}\.apk$#');
    $response->assertJsonPath('downloadUrl', AndroidAppRelease::DOWNLOAD_URL);
    expect(File::get($pointer))->toBe(json_encode($legacy));
});

it('rolls back the release when audit persistence fails', function (): void {
    $audit = Mockery::mock(app(AuditLogger::class));
    $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit failure'));
    app()->instance(AuditLogger::class, $audit);
    expect(fn () => app(AndroidAppRelease::class)->publish($this->releaseTenant, $this->releaseFields, $this->owner))
        ->toThrow(RuntimeException::class, 'Synthetic audit failure');
    expect(DB::table('tenant_android_releases')->count())->toBe(0);
    $this->getJson('http://a.localhost/api/mobile/v1/app-release')->assertStatus(503);
});
