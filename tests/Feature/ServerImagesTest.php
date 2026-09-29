<?php

use App\Application\Media\DirectImageUploads;
use App\Application\Media\ImageStorage;
use App\Application\Media\OssSettings;
use App\Application\Media\PublicAssets;
use App\Application\Media\ServerImages;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Media\DirectImageUpload;
use App\Domain\Media\StoredImage;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Storage\OssImages;
use App\Support\Errors\DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed();
    config(['media.storage' => 'server']);
    Storage::fake('private');
    Storage::fake('public');
    $oss = Mockery::mock(OssImages::class);
    foreach (['put', 'get', 'getBounded', 'display', 'delete', 'metadata', 'directUploadPolicy', 'copyDirectImage', 'publishDirectImage'] as $method) {
        $oss->shouldNotReceive($method);
    }
    app()->instance(OssImages::class, $oss);
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->bytes = kycTestImage()->getContent();
});

it('stores originals privately and serves signed original OCR URLs without OSS configuration', function () {
    $images = app(ImageStorage::class);
    $key = 'kyc/'.$this->tenant->id.'/'.Str::uuid();
    $images->put($this->tenant->id, 'private', $key, $this->bytes, 'kyc');
    $image = $images->record('private', $key);
    expect($image->configuration_id)->toBeNull()->and($image->oss_pending)->toBeFalse();
    expect(Storage::disk('private')->get($image->backup_key))->not->toBe($this->bytes);
    expect($images->read('private', $key))->toBe($this->bytes);
    $url = $images->ocrUrl('private', $key);
    expect($url)->toContain('/media/images/')->toContain('signature=');
    $this->get($url)->assertOk()->assertContent($this->bytes);
    $this->get(strtok($url, '?'))->assertForbidden();
    expect(app(PublicAssets::class)->manifest(true))->toBe([]);
    $this->artisan('images:replicate')->assertSuccessful();
});

it('validates and binds server uploads exactly once with no cloud policy', function () {
    $uploads = app(DirectImageUploads::class);
    $ticket = $uploads->authorize($this->tenant->id, $this->user->id, 'kyc', 'front', 'image/png');
    expect($ticket['mode'])->toBe('server')->and($ticket)->not->toHaveKey('url')->not->toHaveKey('fields');
    expect(fn () => $uploads->complete($this->tenant->id, $this->user->id, $ticket['id']))->toThrow(HttpException::class);
    $uploads->backup($this->tenant->id, $this->user->id, $ticket['id'], $this->bytes);
    $uploads->complete($this->tenant->id, $this->user->id, $ticket['id']);
    $uploads->complete($this->tenant->id, $this->user->id, $ticket['id']);
    $file = $uploads->resolve($this->tenant->id, $this->user->id, $ticket['id'], 'kyc', 'front');
    $key = 'kyc/'.$this->tenant->id.'/'.Str::uuid();
    $uploads->claim($file, $this->tenant->id, 'private', $key, 'kyc', null);
    expect(app(ImageStorage::class)->read('private', $key))->toBe($this->bytes);
    expect(fn () => $uploads->claim($file, $this->tenant->id, 'private', $key, 'kyc', null))->toThrow(HttpException::class);
    expect(DirectImageUpload::find($ticket['id'])->claimed_at)->not->toBeNull();
});

it('rejects malformed uploads and cross-user access without creating a readable image', function () {
    $uploads = app(DirectImageUploads::class);
    $ticket = $uploads->authorize($this->tenant->id, $this->user->id, 'support', 'support_image', 'image/png');
    expect(fn () => $uploads->backup($this->tenant->id, $this->user->id, $ticket['id'], 'not an image'))->toThrow(DomainException::class);
    $other = User::where('tenant_id', '<>', $this->tenant->id)->firstOrFail();
    expect(fn () => $uploads->backup($other->tenant_id, $other->id, $ticket['id'], $this->bytes))->toThrow(ModelNotFoundException::class);
    expect(StoredImage::where('state', 'ready')->count())->toBe(0);
});

it('refuses corrupted replicas rather than reaching OSS and removes staged local copies safely', function () {
    $images = app(ImageStorage::class);
    $key = 'support/'.$this->tenant->id.'/'.Str::uuid();
    $images->put($this->tenant->id, 'private', $key, $this->bytes, 'support', null, 'support');
    $image = $images->record('private', $key);
    Storage::disk('private')->put($image->backup_key, 'corrupted');
    expect(fn () => $images->read('private', $key))->toThrow(RuntimeException::class);
    $images->discard('private', $key);
    expect($image->fresh()->state)->toBe('deleted')->and(Storage::disk('private')->exists($image->backup_key))->toBeFalse();
});

it('shows server storage mode to administrators without activating OSS', function () {
    config(['inertia.ssr.enabled' => false]);
    $owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($owner, 'platform_admin')->get('http://admin.localhost/platform/settings/oss')->assertOk()
        ->assertInertia(fn ($page) => $page->component('platform/OssSettings')->where('serverStorage', true));
    $this->post('http://admin.localhost/platform/settings/oss', [])->assertSessionHasErrors('region');
});

it('persists a permission scoped storage choice without contacting OSS and rejects stale changes', function () {
    $owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $url = 'http://admin.localhost/platform/settings/oss/driver';
    $this->actingAs($owner, 'platform_admin')->post($url, [
        'storage_driver' => 'oss', 'expected_revision' => 0,
    ])->assertSessionHasErrors('storage_driver');
    $images = app(ImageStorage::class);
    $key = 'kyc/'.$this->tenant->id.'/'.Str::uuid();
    $images->put($this->tenant->id, 'private', $key, $this->bytes, 'kyc');
    $originalUrl = $images->ocrUrl('private', $key);
    $settings = app(OssSettings::class);
    $config = $settings->save([
        'region' => 'cn-beijing', 'bucket' => 'test-bucket',
        'endpoint' => 'https://oss-cn-beijing.aliyuncs.com',
        'public_url' => 'https://images.example.com',
        'access_key_id' => 'test-key', 'access_key_secret' => 'test-secret',
    ], $owner);
    $settings->activate($config, $owner);
    $this->post($url, ['storage_driver' => 'oss', 'expected_revision' => 0])->assertRedirect()->assertSessionHasNoErrors();
    expect(ServerImages::enabled())->toBeFalse();
    $activeOss = Mockery::mock(OssImages::class);
    $activeOss->shouldReceive('getBounded')->once()->andThrow(new RuntimeException('current OSS missing object'));
    app()->instance(OssImages::class, $activeOss);

    expect($images->ocrUrl('private', $key))->toContain('/media/images/')->toContain('signature=');
    $this->get($originalUrl)->assertOk()->assertContent($this->bytes);

    $this->post($url, ['storage_driver' => 'server', 'expected_revision' => 0])->assertSessionHasErrors('storage_driver');
    expect(ServerImages::enabled())->toBeFalse();
    $this->post($url, ['storage_driver' => 'server', 'expected_revision' => 1])->assertRedirect()->assertSessionHasNoErrors();
    config(['media.storage' => 'oss']);
    expect(ServerImages::enabled())->toBeTrue();
    expect(DB::table('media_storage_settings')->value('active_configuration_id'))->toBe($config->id);
    $this->post($url, ['storage_driver' => 'invalid', 'expected_revision' => 2])->assertSessionHasErrors('storage_driver');
    $owner->memberships()->delete();
    $this->post($url, ['storage_driver' => 'oss', 'expected_revision' => 2])->assertForbidden();
});

it('can select local storage without any OSS credentials', function () {
    $owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    config(['media.storage' => 'oss']);
    $this->actingAs($owner, 'platform_admin')->post('http://admin.localhost/platform/settings/oss/driver', [
        'storage_driver' => 'server', 'expected_revision' => 0,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(ServerImages::enabled())->toBeTrue();
});
