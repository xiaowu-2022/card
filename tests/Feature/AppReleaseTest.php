<?php

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->seed();
    $this->releaseTenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->apkFixture = tempnam(sys_get_temp_dir(), 'app-release-test-');
    file_put_contents($this->apkFixture, 'PK-synthetic-offline-apk');
});
afterEach(function () {
    File::deleteDirectory(storage_path('app/app-releases/'.$this->releaseTenant->id));
    File::deleteDirectory(public_path('app-releases/'.$this->releaseTenant->id));
    @unlink($this->apkFixture);
});
function releaseArguments($test, int $code = 2): array
{
    return ['tenant' => 'tenant-a', 'apk' => $test->apkFixture, '--code' => $code, '--release-version' => '1.0.1', '--appid' => '__UNI__TEST'];
}
it('publishes a scoped immutable download before making it discoverable', function () {
    $this->getJson('https://a.localhost/api/mobile/v1/app-release')->assertStatus(503);
    $this->artisan('app:publish-android', releaseArguments($this))->assertExitCode(0);
    $response = $this->getJson('https://a.localhost/api/mobile/v1/app-release?tenant_id=other')
        ->assertOk()->assertJsonPath('tenantSlug', 'tenant-a')->assertJsonPath('versionCode', 2)->assertJsonPath('appId', '__UNI__TEST');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect(file_get_contents(public_path($response->json('path'))))->toBe(file_get_contents($this->apkFixture));
    $this->getJson('https://b.localhost/api/mobile/v1/app-release')->assertStatus(503);
    $this->getJson('https://admin.localhost/api/mobile/v1/app-release')->assertNotFound();
    $this->artisan('app:publish-android', releaseArguments($this))->assertExitCode(0);
    $this->artisan('app:publish-android', releaseArguments($this, 1))->assertExitCode(1);
    file_put_contents($this->apkFixture, 'PK-different-build');
    $this->artisan('app:publish-android', releaseArguments($this))->assertExitCode(1);
    $this->artisan('app:publish-android', releaseArguments($this, 3))->assertExitCode(0);
    $this->getJson('https://a.localhost/api/mobile/v1/app-release')->assertJsonPath('versionCode', 3);
});
it('does not advertise a missing APK and rejects invalid metadata', function () {
    $args = releaseArguments($this);
    $args['--code'] = 'invalid';
    $this->artisan('app:publish-android', $args)->assertExitCode(1);
    $this->artisan('app:publish-android', releaseArguments($this))->assertExitCode(0);
    File::deleteDirectory(public_path('app-releases/'.$this->releaseTenant->id));
    $this->getJson('https://a.localhost/api/mobile/v1/app-release')->assertStatus(503);
});
