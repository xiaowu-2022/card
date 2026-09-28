<?php

use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Media\ImageReferences;
use App\Application\Media\ImageStorage;
use App\Application\Media\MigrateImages;
use App\Application\Media\OssSettings;
use App\Application\Support\SendSupportMessageAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Card\Services\CardholderMaterials;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Media\OssConfiguration;
use App\Domain\Media\StoredImage;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Storage\OssImages;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OSS\OssClient;

beforeEach(function () {
    $this->seed();
    config(['inertia.ssr.enabled' => false]);
    Storage::fake('private');
    Storage::fake('public');
    Http::preventStrayRequests();
    $this->company = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->company->id)->firstOrFail();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->oss = new class extends OssImages
    {
        public array $objects = [];

        public int $puts = 0;

        public bool $failPut = false;

        public bool $failDelete = false;

        public bool $corrupt = false;

        public function put(OssConfiguration $c, string $key, string $contents, string $mime): void
        {
            $this->puts++;
            if ($this->failPut) {
                throw new RuntimeException('SECRET-UPSTREAM');
            } $this->objects[$c->id.'/'.$key] = $contents;
        }

        public function get(OssConfiguration $c, string $key): string
        {
            return $this->corrupt ? 'bad-checksum' : $this->objects[$c->id.'/'.$key];
        }

        public function delete(OssConfiguration $c, string $key): void
        {
            if ($this->failDelete) {
                throw new RuntimeException('SECRET-UPSTREAM');
            } unset($this->objects[$c->id.'/'.$key]);
        }
    };
    app()->instance(OssImages::class, $this->oss);
    $this->data = ['region' => 'ap-southeast-1', 'bucket' => 'test-images', 'endpoint' => 'https://oss-ap-southeast-1.aliyuncs.com', 'public_url' => 'https://images.example.com', 'access_key_id' => 'synthetic-key', 'access_key_secret' => 'synthetic-secret'];
});
function enableOssFixture($test): OssConfiguration
{
    $c = app(OssSettings::class)->save($test->data, $test->owner);
    $c->update(['verified_at' => now()]);
    app(OssSettings::class)->activate($c, $test->owner);

    return $c;
}
it('protects settings permissions and secrets and requires verified activation', function () {
    $base = 'http://admin.localhost/platform/settings/oss';
    $this->get($base)->assertRedirect();
    $tenantAdmin = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($tenantAdmin, 'platform_admin')->post($base, $this->data)->assertForbidden();
    $this->actingAs($this->owner, 'platform_admin')->post($base, $this->data)->assertSessionHasNoErrors();
    $c = OssConfiguration::sole();
    expect(DB::table('oss_configurations')->value('credentials'))->not->toContain('synthetic-secret', 'synthetic-key');
    $this->get($base)->assertOk()->assertDontSee('synthetic-secret')->assertDontSee('synthetic-key');
    $this->post($base.'/'.$c->id.'/activate')->assertSessionHasErrors();
    expect(app(ImageStorage::class)->active())->toBeNull();
});
it('checks upload read public access delete before enabling a configuration', function () {
    $c = app(OssSettings::class)->save($this->data, $this->owner);
    $bytes = kycTestImage()->getContent();
    Http::fake(['images.example.com/*' => Http::response($bytes, 200)]);
    app(OssSettings::class)->check($c, $this->owner);
    app(OssSettings::class)->activate($c, $this->owner);
    expect($c->fresh()->verified_at)->not->toBeNull()->and($this->oss->objects)->toBe([]);
    Http::assertSent(fn ($r) => ! $r->hasHeader('Authorization'));
});
it('does not verify a domain serving the wrong image', function () {
    $c = app(OssSettings::class)->save($this->data, $this->owner);
    Http::fake(['*' => Http::response('wrong', 200)]);
    expect(fn () => app(OssSettings::class)->check($c, $this->owner))->toThrow(DomainException::class);
    expect($c->fresh()->verified_at)->toBeNull()->and($this->oss->objects)->toBe([]);
});
it('keeps stored objects bound to immutable configuration versions and never falls back after upload failure', function () {
    $c = enableOssFixture($this);
    $images = app(ImageStorage::class);
    $key = 'kyc/'.$this->company->id.'/'.Str::uuid();
    $bytes = kycTestImage()->getContent();
    $images->put($this->company->id, 'private', $key, $bytes, 'kyc');
    $url = $images->url('private', $key);
    $this->data['public_url'] = 'https://new.example.com';
    enableOssFixture($this);
    expect($images->url('private', $key))->toBe($url)->and($images->read('private', $key))->toBe($bytes)->and($images->record('private', $key)->configuration_id)->toBe($c->id);
    $this->oss->failPut = true;
    $failed = 'kyc/'.$this->company->id.'/'.Str::uuid();
    expect(fn () => $images->put($this->company->id, 'private', $failed, $bytes, 'kyc'))->toThrow(DomainException::class);
    Storage::disk('private')->assertMissing($failed);
});
it('sends OCR only generated OSS URLs and no image bytes', function () {
    enableOssFixture($this);
    config(['kyc.ocr_driver' => 'aliyun', 'kyc.aliyun.access_key_id' => 'ocr-key', 'kyc.aliyun.access_key_secret' => 'ocr-secret']);
    Http::fake(['ocr-api.cn-hangzhou.aliyuncs.com/*' => Http::response(['Data' => json_encode(['data' => ['passportNumber' => 'E12345678']])])]);
    $app = app(SubmitKycApplicationAction::class)->execute($this->company, $this->user, 'CN', 'E12345678', kycTestImage(), null, documentType: KycDocumentType::Passport);
    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->body() === '' && str_starts_with($query['Url'] ?? '', 'https://images.example.com/images/') && $request->hasHeader('x-acs-content-sha256', hash('sha256', ''));
    });
    expect(count($this->oss->objects))->toBe(1)->and($app->front_object_key)->not->toContain('E12345678');
    Storage::disk('private')->assertMissing($app->front_object_key);
});
it('persists cleanup work when OCR fails and deletes only unreferenced uploads on recovery', function () {
    enableOssFixture($this);
    fakeMatchingKycOcr('DIFFERENT');
    $this->oss->failDelete = true;
    expect(fn () => app(SubmitKycApplicationAction::class)->execute($this->company, $this->user, 'CN', 'E12345678', kycTestImage(), null, documentType: KycDocumentType::Passport))->toThrow(DomainException::class);
    expect(KycApplication::count())->toBe(0)->and(StoredImage::sole()->state)->toBe('cleanup_pending')->and(StoredImage::sole()->last_error)->toBe('DELETE_FAILED');
    $this->oss->failDelete = false;
    $this->travel(2)->minutes();
    $this->artisan('images:recover')->assertSuccessful();
    expect($this->oss->objects)->toBe([])->and(StoredImage::sole()->state)->toBe('deleted');
});
it('migrates encrypted support images with checksums while retaining the exact local backup', function () {
    $id = app(SendSupportMessageAction::class)->user($this->company->id, $this->user->id, (string) Str::uuid(), 'image', kycTestImage());
    $ref = collect(iterator_to_array(app(ImageReferences::class)->all()))->firstWhere('purpose', 'support');
    $backup = Storage::disk('private')->get($ref['key']);
    expect($backup)->not->toBe(kycTestImage()->getContent());
    enableOssFixture($this);
    $this->oss->corrupt = true;
    expect(fn () => app(MigrateImages::class)->migrate($ref))->toThrow(RuntimeException::class);
    expect(app(ImageStorage::class)->record('private', $ref['key'])->configuration_id)->toBeNull();
    $this->oss->corrupt = false;
    $row = app(MigrateImages::class)->migrate($ref);
    $puts = $this->oss->puts;
    app(MigrateImages::class)->migrate($ref);
    expect($this->oss->puts)->toBe($puts)->and($row->codec)->toBe('plain')->and(app(ImageStorage::class)->read('private', $ref['key']))->toBe(kycTestImage()->getContent());
    expect(Storage::disk('private')->get($ref['key']))->toBe($backup);
});
it('previews without writes and resumes migrations without replaying business actions', function () {
    $key = 'tenant-branding/'.$this->company->id.'/logo';
    Storage::disk('public')->put($key, kycTestImage()->getContent());
    $this->company->branding()->update(['logo_object_key' => $key]);
    enableOssFixture($this);
    $this->artisan('images:migrate-oss')->assertSuccessful();
    expect($this->oss->puts)->toBe(0);
    $this->artisan('images:migrate-oss --execute --limit=1')->assertSuccessful();
    $this->artisan('images:migrate-oss --execute --limit=1')->assertSuccessful();
    expect($this->oss->puts)->toBe(1)->and($this->company->branding()->value('logo_object_key'))->toBe($key);
    Storage::disk('public')->assertExists($key);
});
it('rejects cross-company storage references and credential destinations', function () {
    enableOssFixture($this);
    $other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    expect(fn () => app(ImageStorage::class)->put($this->company->id, 'private', 'kyc/'.$other->id.'/image', kycTestImage()->getContent(), 'kyc'))->toThrow(LogicException::class);
    $this->data['endpoint'] = 'https://attacker.example.com';
    expect(fn () => app(OssSettings::class)->save($this->data, $this->owner))->toThrow(ValidationException::class);
});

it('migrates KYC images without changing immutable applications or rerunning OCR', function () {
    fakeMatchingKycOcr('E12345678');
    $application = app(SubmitKycApplicationAction::class)->execute($this->company, $this->user, 'CN', 'E12345678', kycTestImage(), null, documentType: KycDocumentType::Passport);
    $before = $application->fresh()->getAttributes();
    $ref = collect(iterator_to_array(app(ImageReferences::class)->all()))->firstWhere('purpose', 'kyc');
    $bytes = Storage::disk('private')->get($ref['key']);
    enableOssFixture($this);
    app(MigrateImages::class)->migrate($ref);
    expect($application->fresh()->getAttributes())->toBe($before)
        ->and(Storage::disk('private')->get($ref['key']))->toBe($bytes)
        ->and(app(ImageStorage::class)->read('private', $ref['key']))->toBe($bytes);
    Http::assertNothingSent();
});
it('decrypts archived card images for OSS without changing their ciphertext backup', function () {
    $key = 'card-materials/'.$this->company->id.'/'.Str::uuid().'/front';
    $bytes = kycTestImage()->getContent();
    $cipher = app(CardholderMaterials::class)->encrypt($bytes);
    Storage::disk('private')->put($key, $cipher);
    enableOssFixture($this);
    $ref = ['tenant' => $this->company->id, 'disk' => 'private', 'key' => $key, 'purpose' => 'card', 'reference' => (string) Str::uuid(), 'codec' => 'card'];
    $row = app(MigrateImages::class)->migrate($ref);
    expect($this->oss->objects[$row->configuration_id.'/'.$row->object_key])->toBe($bytes)
        ->and(Storage::disk('private')->get($key))->toBe($cipher);
});
it('retains referenced objects during expired staging cleanup', function () {
    enableOssFixture($this);
    app(SendSupportMessageAction::class)->user($this->company->id, $this->user->id, (string) Str::uuid(), 'keep', kycTestImage());
    $this->travel(2)->days();
    $this->artisan('images:recover')->assertSuccessful();
    expect(count($this->oss->objects))->toBe(1)->and(StoredImage::sole()->cleanup_after)->toBeNull()->and(StoredImage::sole()->state)->toBe('ready');
});
it('resumes past missing sources and explicitly retries failures after source recovery', function () {
    $key = 'tenant-branding/'.$this->company->id.'/missing';
    $this->company->branding()->update(['logo_object_key' => $key]);
    enableOssFixture($this);
    $this->artisan('images:migrate-oss --execute --limit=1')->assertFailed();
    expect(StoredImage::sole()->last_error)->toBe('MIGRATION_FAILED');
    $this->artisan('images:migrate-oss --execute --limit=1')->assertSuccessful();
    Storage::disk('public')->put($key, kycTestImage()->getContent());
    $this->artisan('images:migrate-oss --execute --retry-failed --limit=1')->assertSuccessful();
    expect(StoredImage::sole()->configuration_id)->not->toBeNull()->and(StoredImage::sole()->last_error)->toBeNull();
});
it('does not allow in-place configuration edits or deletion', function () {
    $config = enableOssFixture($this);
    expect(fn () => $config->update(['bucket' => 'different-bucket']))->toThrow(LogicException::class);
    expect(fn () => $config->fresh()->delete())->toThrow(LogicException::class);
});

it('passes public-read encryption and image headers through the official SDK boundary', function () {
    $config = enableOssFixture($this);
    $sdk = Mockery::mock(OssClient::class);
    $bytes = kycTestImage()->getContent();
    $sdk->shouldReceive('putObject')->once()->with($config->bucket, 'images/test.png', $bytes, Mockery::on(fn ($options) => $options[OssClient::OSS_HEADERS]['Content-Type'] === 'image/png'
        && $options[OssClient::OSS_HEADERS]['x-oss-object-acl'] === 'public-read'
        && $options[OssClient::OSS_HEADERS]['x-oss-server-side-encryption'] === 'AES256'));
    $adapter = new class($sdk) extends OssImages
    {
        public function __construct(private OssClient $sdk) {}

        protected function client(OssConfiguration $config): OssClient
        {
            return $this->sdk;
        }
    };
    $adapter->put($config, 'images/test.png', $bytes, 'image/png');
});

it('accepts an explicit OSS CNAME only when it equals the configured image domain', function () {
    $this->data['endpoint'] = $this->data['public_url'];
    $config = app(OssSettings::class)->save($this->data, $this->owner);
    expect($config->endpoint)->toBe('https://images.example.com')->and($config->verified_at)->toBeNull();
});

it('rejects a region mismatching a bucket endpoint and reports saved-version test errors', function () {
    $this->data['region'] = 'beijing';
    $this->data['endpoint'] = $this->data['public_url'] = 'https://test-images.oss-cn-beijing.aliyuncs.com';
    $base = 'http://admin.localhost/platform/settings/oss';
    $this->actingAs($this->owner, 'platform_admin')->post($base, $this->data)->assertSessionHasErrors('region');
    expect(OssConfiguration::count())->toBe(0)->and($this->oss->puts)->toBe(0);
    $legacy = OssConfiguration::create(collect($this->data)->except(['access_key_id', 'access_key_secret'])->all() + [
        'credentials' => ['access_key_id' => 'synthetic', 'access_key_secret' => 'synthetic'], 'created_by' => $this->owner->id,
    ]);
    $this->post($base.'/'.$legacy->id.'/check')->assertSessionHasErrors('region');
    expect($legacy->fresh()->verified_at)->toBeNull()->and($this->oss->puts)->toBe(0);
    $this->data['region'] = 'cn-beijing';
    $this->post($base, $this->data)->assertSessionHasNoErrors();
});

it('returns a safe form error when a connection check fails instead of reporting success', function () {
    $c = app(OssSettings::class)->save($this->data, $this->owner);
    $this->oss->failPut = true;
    $this->actingAs($this->owner, 'platform_admin')->post('http://admin.localhost/platform/settings/oss/'.$c->id.'/check')
        ->assertSessionHasErrors(['form' => 'OSS test failed. Check credentials, endpoint, public access and image domain.']);
    expect($c->fresh()->verified_at)->toBeNull();
});

it('allows bounded extra upload time for large images without changing OSS headers', function () {
    $config = enableOssFixture($this);
    $sdk = Mockery::mock(OssClient::class);
    $bytes = str_repeat('synthetic-image-bytes', 110000);
    $sdk->shouldReceive('setTimeout')->once()->with(180);
    $sdk->shouldReceive('putObject')->once()->with($config->bucket, 'images/large.png', $bytes, Mockery::on(fn ($options) => $options[OssClient::OSS_HEADERS]['Content-Type'] === 'image/png'
        && $options[OssClient::OSS_HEADERS]['x-oss-object-acl'] === 'public-read'
        && $options[OssClient::OSS_HEADERS]['x-oss-server-side-encryption'] === 'AES256'));
    $adapter = new class($sdk) extends OssImages
    {
        public function __construct(private OssClient $sdk) {}

        protected function client(OssConfiguration $config): OssClient
        {
            return $this->sdk;
        }
    };
    $adapter->put($config, 'images/large.png', $bytes, 'image/png');
});
