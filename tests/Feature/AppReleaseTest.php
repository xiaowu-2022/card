<?php

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->seed();
    $this->releaseTenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
});
afterEach(function () {
    File::deleteDirectory(storage_path('app/app-releases/'.$this->releaseTenant->id));
    File::deleteDirectory(public_path('app-releases/'.$this->releaseTenant->id));
});
function releaseArguments($test, int $code = 2): array
{
    return ['tenant' => 'tenant-a', '--code' => $code, '--release-version' => '1.0.1', '--appid' => '__UNI__TEST'];
}
it('publishes company metadata without an APK and preserves installed client compatibility', function () {
    $this->getJson('https://a.localhost/api/mobile/v1/app-release')->assertStatus(503);
    $this->artisan('app:publish-android', releaseArguments($this))->assertExitCode(0);
    $response = $this->getJson('https://a.localhost/api/mobile/v1/app-release?tenant_id=other')
        ->assertOk()->assertJsonPath('tenantSlug', 'tenant-a')->assertJsonPath('versionCode', 2)->assertJsonPath('appId', '__UNI__TEST')
        ->assertJsonPath('downloadUrl', 'http://zb33333.com/specpay.apk');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->json('path'))->toMatch('#^/app-releases/'.$this->releaseTenant->id.'/[a-f0-9]{64}\.apk$#');
    expect(is_dir(public_path('app-releases/'.$this->releaseTenant->id)))->toBeFalse();
    $this->getJson('https://b.localhost/api/mobile/v1/app-release')->assertStatus(503);
    $this->getJson('https://admin.localhost/api/mobile/v1/app-release')->assertNotFound();
    $this->artisan('app:publish-android', releaseArguments($this))->assertExitCode(0);
    $this->artisan('app:publish-android', releaseArguments($this, 1))->assertExitCode(1);
    $this->artisan('app:publish-android', array_replace(releaseArguments($this), ['--release-version' => '1.0.2']))->assertExitCode(1);
    $this->artisan('app:publish-android', releaseArguments($this, 3))->assertExitCode(0);
    $this->getJson('https://a.localhost/api/mobile/v1/app-release')->assertJsonPath('versionCode', 3);
});
it('rejects invalid metadata and accepts legacy CLI syntax without reading its APK path', function () {
    $args = releaseArguments($this);
    $args['--code'] = 'invalid';
    $this->artisan('app:publish-android', $args)->assertExitCode(1);
    $this->artisan('app:publish-android', releaseArguments($this) + ['apk' => '/nonexistent/legacy.apk'])->assertExitCode(0);
    $this->getJson('https://a.localhost/api/mobile/v1/app-release')->assertOk();
});
