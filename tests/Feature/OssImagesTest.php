<?php

use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Media\ImagePresentation;
use App\Application\Media\ImageReferences;
use App\Application\Media\ImageReplicas;
use App\Application\Media\ImageStorage;
use App\Application\Media\MigrateImages;
use App\Application\Media\OssSettings;
use App\Application\Media\PublicAssets;
use App\Application\Promotion\PromotionQuery;
use App\Application\Support\SendSupportMessageAction;
use App\Application\Support\SupportChatQuery;
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
use OSS\Core\OssException;
use OSS\OssClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['media.storage' => 'oss']);
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

        public array $reads = [];

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
            $this->reads[] = $c->id;

            return $this->corrupt ? 'bad-checksum' : $this->objects[$c->id.'/'.$key];
        }

        public function getBounded(OssConfiguration $c, string $key, int $maxBytes): string
        {
            return substr($this->get($c, $key), 0, $maxBytes + 1);
        }

        public array $processes = [];

        public function display(OssConfiguration $c, string $key, string $process): string
        {
            $this->processes[] = $process;

            return $this->objects[$c->id.'/'.$key];
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
it('protects settings permissions and secrets while allowing activation without a connection test', function () {
    $base = 'http://admin.localhost/platform/settings/oss';
    $this->get($base)->assertRedirect();
    $tenantAdmin = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($tenantAdmin, 'platform_admin')->post($base, $this->data)->assertForbidden();
    $this->actingAs($this->owner, 'platform_admin')->post($base, $this->data)->assertSessionHasNoErrors();
    $c = OssConfiguration::sole();
    expect(DB::table('oss_configurations')->value('credentials'))->not->toContain('synthetic-secret', 'synthetic-key');
    $this->get($base)->assertOk()->assertDontSee('synthetic-secret')->assertDontSee('synthetic-key');
    $this->post($base.'/'.$c->id.'/activate')->assertSessionHasNoErrors();
    expect(app(ImageStorage::class)->active()->id)->toBe($c->id)->and($c->fresh()->verified_at)->toBeNull();
    Http::assertNothingSent();
});
it('optionally checks upload read public access and delete', function () {
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
it('keeps stored objects bound to immutable configuration versions and retains a replica after upload failure', function () {
    $c = enableOssFixture($this);
    $images = app(ImageStorage::class);
    $key = 'kyc/'.$this->company->id.'/'.Str::uuid();
    $bytes = kycTestImage()->getContent();
    $images->put($this->company->id, 'private', $key, $bytes, 'kyc');
    $url = $images->url('private', $key);
    $this->data['public_url'] = 'https://new.example.com';
    enableOssFixture($this);
    expect($images->url('private', $key))->toBe('https://new.example.com/'.$images->record('private', $key)->object_key)->and($images->read('private', $key))->toBe($bytes)->and($images->record('private', $key)->configuration_id)->toBe($c->id);
    $this->oss->failPut = true;
    $failed = 'kyc/'.$this->company->id.'/'.Str::uuid();
    $images->put($this->company->id, 'private', $failed, $bytes, 'kyc');
    expect($images->record('private', $failed)->oss_pending)->toBeTrue()->and($images->read('private', $failed))->toBe($bytes);
    Storage::disk('private')->assertMissing($failed);
});
it('sends OCR only direct OSS original URLs and no image bytes', function () {
    enableOssFixture($this);
    config(['kyc.ocr_driver' => 'aliyun', 'kyc.aliyun.access_key_id' => 'ocr-key', 'kyc.aliyun.access_key_secret' => 'ocr-secret']);
    Http::fake(['ocr-api.cn-hangzhou.aliyuncs.com/*' => Http::response(['Data' => json_encode(['data' => ['passportNumber' => 'E12345678']])])]);
    $app = app(SubmitKycApplicationAction::class)->execute($this->company, $this->user, 'CN', 'E12345678', kycTestImage(), null, documentType: KycDocumentType::Passport);
    Http::assertSent(function ($request) use ($app) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->body() === '' && ($query['Url'] ?? '') === app(ImageStorage::class)->ocrUrl('private', $app->front_object_key) && $request->hasHeader('x-acs-content-sha256', hash('sha256', ''));
    });
    expect(count($this->oss->objects))->toBe(1)->and($app->front_object_key)->not->toContain('E12345678');
    Storage::disk('private')->assertMissing($app->front_object_key);
});
it('persists cleanup work when OCR fails and deletes only unreferenced uploads on recovery', function () {
    enableOssFixture($this);
    fakeMatchingKycOcr('');
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
    $this->data['expected_id'] = $legacy->id;
    $this->post($base, $this->data)->assertSessionHasNoErrors();
});

it('returns a safe form error when a connection check fails instead of reporting success', function () {
    $c = app(OssSettings::class)->save($this->data, $this->owner);
    $this->oss->failPut = true;
    $this->actingAs($this->owner, 'platform_admin')->post('http://admin.localhost/platform/settings/oss/'.$c->id.'/check')
        ->assertSessionHasErrors(['form' => 'OSS test failed: uploading the test image failed. Check endpoint connectivity and write permissions.']);
    expect($c->fresh()->verified_at)->toBeNull();
});

it('retains the short business-image timeout even for large originals', function () {
    $config = enableOssFixture($this);
    $sdk = Mockery::mock(OssClient::class);
    $bytes = str_repeat('synthetic-image-bytes', 110000);
    $sdk->shouldNotReceive('setTimeout');
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

it('generates bounded display URLs while preserving original bytes and OCR URLs', function () {
    enableOssFixture($this);
    $images = app(ImageStorage::class);
    $key = 'kyc/'.$this->company->id.'/'.Str::uuid();
    $bytes = kycTestImage()->getContent();
    $images->put($this->company->id, 'private', $key, $bytes, 'kyc');
    $original = $images->url('private', $key);
    $url = $images->displayUrl('private', $key, 'document');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect($url)->toStartWith('https://images.example.com/')
        ->and($query['x-oss-process'])->toBe(ImagePresentation::process('document', 'image/png'))
        ->and($query)->not->toHaveKey('signature')
        ->and($images->ocrUrl('private', $key))->toBe($this->oss->url($images->active(), $images->record('private', $key)->object_key))
        ->and($images->read('private', $key))->toBe($bytes)
        ->and($images->record('private', $key)->sha256)->toBe(hash('sha256', $bytes));
    expect(ImagePresentation::process('brand', 'image/png'))->toContain('w_512,h_512')
        ->and(ImagePresentation::process('preview', 'image/png'))->toContain('w_1600,h_1600')
        ->and(ImagePresentation::process('brand', 'image/x-icon'))->toBeNull();
});

it('retrieves processed bytes through OSS without altering the original object', function () {
    $config = enableOssFixture($this);
    $sdk = Mockery::mock(OssClient::class);
    $process = ImagePresentation::process('preview', 'image/png');
    $sdk->shouldReceive('getObject')->once()->with($config->bucket, 'images/example', [OssClient::OSS_PROCESS => $process])->andReturn('processed-bytes');
    $adapter = new class($sdk) extends OssImages
    {
        public function __construct(private OssClient $sdk) {}

        protected function client(OssConfiguration $config): OssClient
        {
            return $this->sdk;
        }
    };
    expect($adapter->display($config, 'images/example', $process))->toBe('processed-bytes');
});

it('requires OSS for uploads outside isolated tests without creating a local file', function () {
    $key = 'kyc/'.$this->company->id.'/'.Str::uuid();
    app()->instance('env', 'production');
    try {
        expect(fn () => app(ImageStorage::class)->put($this->company->id, 'private', $key, kycTestImage()->getContent(), 'kyc'))->toThrow(DomainException::class);
        Storage::disk('private')->assertMissing($key);
        expect(StoredImage::count())->toBe(0);
    } finally {
        app()->instance('env', 'testing');
    }
});

it('publishes all public assets idempotently with versioned mappings and untouched originals', function () {
    $c = enableOssFixture($this);
    $assets = app(PublicAssets::class);
    $files = $assets->sources();
    $hashes = array_map(fn ($file) => hash_file('sha256', $file), $files);
    $first = $assets->publish();
    expect($first['uploaded'])->toBe(count($files));
    $puts = $this->oss->puts;
    $second = $assets->publish();
    expect($second['uploaded'])->toBe(0)->and($this->oss->puts)->toBe($puts)
        ->and(array_map(fn ($file) => hash_file('sha256', $file), $files))->toBe($hashes);
    $manifest = $assets->manifest();
    expect($manifest['/images/cards/gold-chip.svg'])->not->toContain('x-oss-process')
        ->and($manifest['/images/marketing/spec-pay-gold-world.png'])->toContain('x-oss-process');
    $this->data['public_url'] = 'https://new.example.com';
    enableOssFixture($this);
    expect($assets->manifest())->toBe(array_map(fn ($url) => str_replace('https://images.example.com', 'https://new.example.com', $url), $manifest));
});

it('does not publish a mapping when uploaded public artwork fails checksum validation', function () {
    enableOssFixture($this);
    $this->oss->corrupt = true;
    expect(fn () => app(PublicAssets::class)->publish())->toThrow(RuntimeException::class);
    expect(app(PublicAssets::class)->manifest())->toBe([]);
});

it('uses processed OSS reads for scoped image responses and never rewrites originals', function () {
    enableOssFixture($this);
    $images = app(ImageStorage::class);
    $key = 'support/'.$this->company->id.'/'.Str::uuid().'.enc';
    $bytes = kycTestImage()->getContent();
    $images->put($this->company->id, 'private', $key, $bytes, 'support');
    $response = $images->displayResponse('private', $key, 'preview', 'support');
    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($this->oss->processes)->toHaveCount(1)
        ->and($this->oss->processes[0])->toContain('w_1600,h_1600')
        ->and($images->read('private', $key))->toBe($bytes);
});

it('keeps staged compiled resources invisible to consumers and Vite until publication', function () {
    $config = enableOssFixture($this);
    DB::table('media_storage_settings')->where('id', 1)->update(['public_assets' => json_encode([
        '@staging/example' => ['configuration_id' => $config->id, 'object_key' => 'assets/web/build/assets/pending-12345678.js', 'sha256' => str_repeat('a', 64), 'mime' => 'application/javascript'],
        '/images/logo.png' => ['configuration_id' => $config->id, 'object_key' => 'assets/hash/logo.png', 'sha256' => str_repeat('b', 64), 'mime' => 'image/png'],
    ])]);
    $assets = app(PublicAssets::class);
    expect(array_keys($assets->manifest(true)))->toBe(['/images/logo.png']);
    $this->getJson('http://a.localhost/api/mobile/v1/bootstrap')->assertOk()->assertJsonMissingPath('publicAssets.@staging/example');
});

it('uses immutable caching and bounded long transfers only for public assets', function () {
    $config = enableOssFixture($this);
    $sdk = Mockery::mock(OssClient::class);
    $sdk->shouldReceive('setTimeout')->once()->with(600);
    $sdk->shouldReceive('putObject')->once()->with($config->bucket, 'assets/hash/icon.svg', '<svg/>', Mockery::on(fn ($options) => $options[OssClient::OSS_HEADERS]['Cache-Control'] === 'public, max-age=31536000, immutable'));
    $adapter = new class($sdk) extends OssImages
    {
        public function __construct(private OssClient $sdk) {}

        protected function client(OssConfiguration $config): OssClient
        {
            return $this->sdk;
        }
    };
    $adapter->put($config, 'assets/hash/icon.svg', '<svg/>', 'image/svg+xml');
});

it('backfills old originals only after checksum verification and never repeats their uploads', function () {
    enableOssFixture($this);
    $key = 'kyc/'.$this->company->id.'/'.Str::uuid();
    $images = app(ImageStorage::class);
    $bytes = kycTestImage()->getContent();
    $images->put($this->company->id, 'private', $key, $bytes, 'kyc');
    $record = $images->record('private', $key);
    $record->update(['backup_key' => null, 'backup_sha256' => null]);
    $puts = $this->oss->puts;
    $this->oss->corrupt = true;
    $this->artisan('images:replicate', ['--backfill' => true])->assertFailed();
    expect($record->fresh()->backup_key)->toBeNull()->and($record->fresh()->sha256)->toBe(hash('sha256', $bytes));
    $this->oss->corrupt = false;
    $this->artisan('images:replicate', ['--backfill' => true])->assertSuccessful();
    $this->artisan('images:replicate', ['--backfill' => true])->assertSuccessful();
    expect($record->fresh()->backup_key)->not->toBeNull()->and($this->oss->puts)->toBe($puts)
        ->and(app(ImageReplicas::class)->read($record->fresh()))->toBe($bytes);
});

it('rejects expired unsigned and cross-host image capabilities without exposing private paths', function () {
    enableOssFixture($this);
    $key = 'kyc/'.$this->company->id.'/'.Str::uuid();
    $images = app(ImageStorage::class);
    $images->put($this->company->id, 'private', $key, kycTestImage()->getContent(), 'kyc');
    $url = $images->gatewayUrl($images->record('private', $key));
    $this->get($url)->assertOk();
    $this->get(strtok($url, '?'))->assertForbidden();
    $changed = preg_replace('~^https?://[^/]+~', 'http://other.example.test', $url);
    $this->get($changed)->assertForbidden();
    expect($url)->not->toContain($key, 'image-replicas');
    $this->travel(13)->hours();
    $this->get($url)->assertForbidden();
});

it('edits one current configuration and preserves blank credentials and historical mappings', function () {
    $old = enableOssFixture($this);
    $url = 'http://admin.localhost/platform/settings/oss';
    $this->actingAs($this->owner, 'platform_admin')->get($url)->assertOk()
        ->assertInertia(fn ($page) => $page->where('configuration.id', $old->id)->missing('configurations')->missing('configuration.credentials'));
    $payload = array_replace($this->data, ['expected_id' => $old->id, 'public_url' => 'https://new-images.example.com', 'access_key_id' => '', 'access_key_secret' => '']);
    $this->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
    $current = app(ImageStorage::class)->active();
    expect($current->id)->not->toBe($old->id)->and($current->credentials)->toBe($old->credentials);
    expect($old->fresh()->public_url)->toBe($this->data['public_url']);
    $this->post($url, $payload)->assertSessionHasErrors('form');
    $count = OssConfiguration::count();
    $payload['expected_id'] = $current->id;
    $this->post($url, $payload)->assertSessionHasNoErrors();
    expect(OssConfiguration::count())->toBe($count);
});

it('tests the unsaved form without persisting configuration or selecting it', function () {
    $current = enableOssFixture($this);
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    Http::fake(['https://draft-images.example.com/*' => Http::response($bytes)]);
    $count = OssConfiguration::count();
    $this->actingAs($this->owner, 'platform_admin')->post('http://admin.localhost/platform/settings/oss/test', array_replace($this->data, ['expected_id' => $current->id, 'public_url' => 'https://draft-images.example.com']))->assertRedirect()->assertSessionHasNoErrors();
    expect(OssConfiguration::count())->toBe($count)->and(app(ImageStorage::class)->active()->id)->toBe($current->id);
    $this->oss->failPut = true;
    $this->post('http://admin.localhost/platform/settings/oss/test', $this->data + ['expected_id' => $current->id])->assertSessionHasErrors('form');
    expect(OssConfiguration::count())->toBe($count);
});

it('resolves existing image references with the current OSS configuration without rewriting history', function () {
    $old = enableOssFixture($this);
    $images = app(ImageStorage::class);
    $key = 'kyc/'.$this->company->id.'/'.Str::uuid();
    $bytes = kycTestImage()->getContent();
    $images->put($this->company->id, 'private', $key, $bytes, 'kyc');
    $image = $images->record('private', $key);
    $this->data['public_url'] = 'https://current.example.com';
    $current = enableOssFixture($this);
    $this->oss->objects[$current->id.'/'.$image->object_key] = $bytes;
    $this->oss->reads = [];
    expect($images->read('private', $key))->toBe($bytes);
    expect($this->oss->reads)->toBe([$current->id]);
    expect($image->fresh()->configuration_id)->toBe($old->id);
    expect($images->url('private', $key))->not->toContain('images.example.com');
    $this->oss->objects[$current->id.'/'.$image->object_key] = 'incorrect object';
    expect($images->read('private', $key))->toBe($bytes);
    expect($this->oss->reads)->not->toContain($old->id);
    Storage::disk('private')->delete($image->backup_key);
    expect(fn () => $images->read('private', $key))->toThrow(RuntimeException::class);
});

it('reports the first failed connection stage without leaking upstream secrets', function () {
    $config = enableOssFixture($this);
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    $stage = '';
    Http::fake(function () use (&$stage, $bytes) {
        return Http::response($stage === 'public_read' ? 'wrong-image' : $bytes, 200);
    });
    foreach (['upload', 'read', 'public_read', 'delete'] as $stage) {
        $this->oss->failPut = $stage === 'upload';
        $this->oss->corrupt = $stage === 'read';
        $this->oss->failDelete = in_array($stage, ['upload', 'delete']);
        try {
            app(OssSettings::class)->check($config, $this->owner);
            $this->fail('Expected a staged failure');
        } catch (DomainException $error) {
            expect($error->details)->toBe(['stage' => $stage]);
            expect($error->getMessage())->not->toContain('SECRET-UPSTREAM')->not->toContain('synthetic-secret');
        }
    }
});

it('classifies upload failures without returning SDK secrets', function () {
    $config = enableOssFixture($this);
    foreach ([
        ['timeout', new RuntimeException('cURL error: Connection timed out SECRET-UPSTREAM')],
        ['permission', new OssException(['code' => 'AccessDenied', 'message' => 'SECRET-UPSTREAM', 'request-id' => 'test', 'status' => 403])],
        ['signature', new OssException(['code' => 'SignatureDoesNotMatch', 'message' => 'SECRET-UPSTREAM', 'request-id' => 'test', 'status' => 403])],
        ['method', new OssException(['code' => 'MethodNotAllowed', 'message' => 'SECRET-UPSTREAM', 'request-id' => 'test', 'status' => 405])],
    ] as [$reason, $failure]) {
        $sdk = Mockery::mock(OssClient::class);
        $sdk->shouldReceive('putObject')->once()->andThrow($failure);
        $adapter = new class($sdk) extends OssImages
        {
            public function __construct(private OssClient $sdk) {}

            protected function client(OssConfiguration $config): OssClient
            {
                return $this->sdk;
            }
        };
        app()->instance(OssImages::class, $adapter);
        $sdk->shouldReceive('deleteObject')->once();
        try {
            app(OssSettings::class)->check($config, $this->owner);
            $this->fail('Expected upload failure');
        } catch (DomainException $error) {
            expect($error->details)->toBe(['stage' => 'upload']);
            expect($error->getMessage())->toStartWith('OSS upload failed:')->not->toContain('SECRET-UPSTREAM');
            expect($error->getMessage())->toContain(match ($reason) {
                'timeout' => 'timed out', 'permission' => 'denied write access',
                'signature' => 'signature validation', 'method' => 'upload method',
            });
        }
    }
});

it('uses the current OSS original for OCR despite replicas and refuses unfinished objects', function () {
    $config = enableOssFixture($this);
    $images = app(ImageStorage::class);
    $key = 'kyc/'.$this->company->id.'/'.Str::uuid();
    $images->put($this->company->id, 'private', $key, kycTestImage()->getContent(), 'kyc');
    $image = $images->record('private', $key);
    expect($image->backup_key)->not->toBeEmpty();
    $this->data['public_url'] = 'https://current-images.example.com';
    enableOssFixture($this);
    $reads = count($this->oss->reads);
    expect($images->ocrUrl('private', $key))->toBe('https://current-images.example.com/'.$image->object_key)
        ->and(count($this->oss->reads))->toBe($reads);
    $image->update(['oss_pending' => true]);
    expect(fn () => $images->ocrUrl('private', $key))->toThrow(DomainException::class);
    $image->update(['oss_pending' => false, 'state' => 'cleanup_pending']);
    expect(fn () => $images->ocrUrl('private', $key))->toThrow(HttpException::class);
    expect(fn () => $images->ocrUrl('private', 'missing'))->toThrow(DomainException::class);
});

it('returns direct current OSS URLs for support and poster DTOs without reading remote bytes', function () {
    enableOssFixture($this);
    $id = app(SendSupportMessageAction::class)->user($this->company->id, $this->user->id, (string) Str::uuid(), 'image', kycTestImage());
    $images = app(ImageStorage::class);
    $key = 'invitation-posters/'.$this->company->id.'/'.Str::uuid();
    $images->put($this->company->id, 'private', $key, kycTestImage()->getContent(), 'poster');
    $this->company->businessSettings()->update(['invitation_poster_background' => $key]);
    $reads = count($this->oss->reads);
    $chat = app(SupportChatQuery::class)->user($this->company->id, $this->user->id);
    expect($chat['messages'][0]['imageUrl'])->toStartWith('https://images.example.com/images/')->toContain('x-oss-process=');
    $platform = app(SupportChatQuery::class)->platform($this->company->id, $this->owner->id, $this->user->id);
    expect($platform['messages'][0]['imageUrl'])->toBe($chat['messages'][0]['imageUrl']);
    $home = app(PromotionQuery::class)->home($this->company->id, $this->user->id);
    expect($home['posterBackground'])->toStartWith('https://images.example.com/images/')->toContain('x-oss-process=');
    expect(count($this->oss->reads))->toBe($reads);
});
