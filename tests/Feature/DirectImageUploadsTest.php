<?php

use App\Application\Media\DirectImageUploads;
use App\Application\Media\DirectKycUploads;
use App\Application\Media\ImageStorage;
use App\Application\Media\OssSettings;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Media\DirectImageUpload;
use App\Domain\Media\OssConfiguration;
use App\Domain\Media\StoredImage;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Storage\OssImages;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['media.storage' => 'oss']);
    $this->seed();
    Http::preventStrayRequests();
    Storage::fake('private');
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->where('email', 'user@a.localhost')->firstOrFail();
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->config = app(OssSettings::class)->save(['region' => 'cn-beijing', 'bucket' => 'test-images',
        'endpoint' => 'https://oss-cn-beijing.aliyuncs.com', 'public_url' => 'https://test-images.oss-cn-beijing.aliyuncs.com',
        'access_key_id' => 'synthetic-key', 'access_key_secret' => 'synthetic-secret'], $actor);
    app(OssSettings::class)->activate($this->config, $actor);
    $this->oss = new class extends OssImages
    {
        public array $objects = [];

        public bool $denyNetwork = false;

        public int $networkCalls = 0;

        public int $copies = 0;

        public array $published = [];

        public function metadata(OssConfiguration $c, string $key): array
        {
            $this->networkCalls++;
            if ($this->denyNetwork) {
                throw new LogicException('OSS network access forbidden in KYC URL submission');
            }

            return ['size' => strlen($this->objects[$key]), 'etag' => hash('sha256', $this->objects[$key])];
        }

        public function copyDirectImage(OssConfiguration $c, string $source, string $destination, string $etag, string $mime): void
        {
            $this->networkCalls++;
            if ($this->denyNetwork) {
                throw new LogicException('OSS network access forbidden in KYC URL submission');
            }
            expect(hash('sha256', $this->objects[$source]))->toBe($etag);
            $this->copies++;
            $this->objects[$destination] = $this->objects[$source];
        }

        public function getBounded(OssConfiguration $c, string $key, int $maxBytes): string
        {
            $this->networkCalls++;
            if ($this->denyNetwork) {
                throw new LogicException('OSS network access forbidden in KYC URL submission');
            }

            return substr($this->objects[$key], 0, $maxBytes + 1);
        }

        public function get(OssConfiguration $c, string $key): string
        {
            $this->networkCalls++;
            if ($this->denyNetwork) {
                throw new LogicException('OSS network access forbidden in KYC URL submission');
            }

            return $this->objects[$key];
        }

        public function publishDirectImage(OssConfiguration $c, string $key): void
        {
            $this->networkCalls++;
            if ($this->denyNetwork) {
                throw new LogicException('OSS network access forbidden in KYC URL submission');
            }
            $this->published[] = $key;
        }

        public function put(OssConfiguration $c, string $key, string $contents, string $mime): void
        {
            if ($this->denyNetwork) {
                throw new LogicException('Synthetic outage');
            }
            $this->objects[$key] = $contents;
        }

        public function display(OssConfiguration $c, string $key, string $process): string
        {
            return $this->get($c, $key);
        }

        public function delete(OssConfiguration $c, string $key): void
        {
            $this->networkCalls++;
            if ($this->denyNetwork) {
                throw new LogicException('OSS network access forbidden in KYC URL submission');
            }
            unset($this->objects[$key]);
        }
    };
    app()->instance(OssImages::class, $this->oss);
    $this->service = app(DirectImageUploads::class);
    $this->base = 'http://a.localhost/api/mobile/v1';
    $token = $this->postJson($this->base.'/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password', 'device_name' => 'Offline test'])->assertCreated()->json('token');
    $this->withToken($token);
});

function directFixture($test, string $purpose = 'support', string $field = 'support_image'): array
{
    if ($purpose === 'kyc') {
        return app(DirectKycUploads::class)->authorize($test->tenant->id, $test->user->id, $field, 'image/png');
    }
    $ticket = $test->postJson($test->base.'/images/direct', ['purpose' => $purpose, 'field' => $field, 'mime' => 'image/png'])->assertOk()->json();
    $test->oss->objects[$ticket['fields']['key']] = kycTestImage()->getContent();
    $test->service->backup($test->tenant->id, $test->user->id, $ticket['id'], kycTestImage()->getContent());

    return $ticket;
}

it('signs an exact private five-minute staging policy without exposing its secret', function () {
    $this->freezeTime();
    $ticket = directFixture($this);
    expect(json_encode($ticket))->not->toContain('synthetic-secret');
    expect($ticket['url'])->toBe('https://test-images.oss-cn-beijing.aliyuncs.com');
    $policy = json_decode(base64_decode($ticket['fields']['policy']), true);
    expect($policy['expiration'])->toBe(now()->utc()->addMinutes(5)->format('Y-m-d\TH:i:s.000\Z'));
    expect($policy['conditions'])->toContain(['content-length-range', 1, 5 * 1024 * 1024], ['x-oss-object-acl' => 'private'], ['key' => $ticket['fields']['key']]);
    $key = hash_hmac('sha256', now()->utc()->format('Ymd'), 'aliyun_v4synthetic-secret', true);
    foreach (['cn-beijing', 'oss', 'aliyun_v4_request'] as $part) {
        $key = hash_hmac('sha256', $part, $key, true);
    }
    expect($ticket['fields']['x-oss-signature'])->toBe(hash_hmac('sha256', $ticket['fields']['policy'], $key));
    expect($this->config->fresh()->verified_at)->toBeNull();
    Http::assertNothingSent();
});

it('binds verified originals and makes completion immune to staging overwrites', function () {
    $ticket = directFixture($this);
    $this->postJson($this->base.'/images/direct/'.$ticket['id'].'/complete')->assertNoContent();
    $this->oss->objects[$ticket['fields']['key']] = 'later malicious bytes';
    $this->postJson($this->base.'/images/direct/'.$ticket['id'].'/complete')->assertNoContent();
    $file = $this->service->resolve($this->tenant->id, $this->user->id, $ticket['id'], 'support', 'support_image');
    $key = 'support/'.$this->tenant->id.'/'.Str::uuid().'.enc';
    app(ImageStorage::class)->putUpload($this->tenant->id, 'private', $key, $file, 'support');
    expect(app(ImageStorage::class)->read('private', $key))->toBe(kycTestImage()->getContent());
    expect($this->oss->copies)->toBe(1)->and(DirectImageUpload::find($ticket['id'])->claimed_at)->not->toBeNull();
    Storage::disk('private')->assertMissing($key);
    expect(fn () => app(ImageStorage::class)->putUpload($this->tenant->id, 'private', 'support/'.$this->tenant->id.'/'.Str::uuid(), $file, 'support'))->toThrow(HttpException::class);
});

it('rejects wrong users purposes expired tickets and non-images before publishing', function () {
    $ticket = directFixture($this);
    $this->oss->objects[$ticket['fields']['key']] = '<script>bad</script>';
    $this->postJson($this->base.'/images/direct/'.$ticket['id'].'/complete')->assertNoContent();
    expect(StoredImage::find(DirectImageUpload::find($ticket['id'])->image_id)->oss_pending)->toBeTrue();
    expect($this->oss->published)->toBe([]);
    expect(fn () => $this->service->complete($this->tenant->id, (string) Str::uuid(), $ticket['id']))->toThrow(HttpException::class);
    $this->oss->objects[$ticket['fields']['key']] = kycTestImage()->getContent();
    $this->postJson($this->base.'/images/direct/'.$ticket['id'].'/complete')->assertNoContent();
    expect(fn () => $this->service->resolve($this->tenant->id, $this->user->id, $ticket['id'], 'kyc', 'front'))->toThrow(HttpException::class);
    $this->travel(16)->minutes();
    expect(fn () => $this->service->resolve($this->tenant->id, $this->user->id, $ticket['id'], 'support', 'support_image'))->toThrow(HttpException::class);
});

it('sends a support image without multipart bytes and preserves request replay', function () {
    $ticket = directFixture($this);
    $this->postJson($this->base.'/images/direct/'.$ticket['id'].'/complete')->assertNoContent();
    $payload = ['request_id' => (string) Str::uuid(), 'support_image_upload_id' => $ticket['id']];
    $this->postJson($this->base.'/support/messages', $payload)->assertSuccessful();
    $this->travel(16)->minutes();
    $this->postJson($this->base.'/support/messages', $payload)->assertSuccessful();
    expect(DirectImageUpload::find($ticket['id'])->claimed_at)->not->toBeNull();
    $this->postJson($this->base.'/support/messages', ['request_id' => (string) Str::uuid(), 'support_image_upload_id' => $ticket['id']])->assertStatus(409);
});

it('does not accept a client-selected tenant or an unverified image', function () {
    $this->postJson($this->base.'/images/direct', ['purpose' => 'support', 'field' => 'support_image', 'mime' => 'image/png', 'tenant_id' => $this->tenant->id])->assertUnprocessable();
    $ticket = directFixture($this);
    $this->postJson($this->base.'/support/messages', ['request_id' => (string) Str::uuid(), 'support_image_upload_id' => $ticket['id']])->assertStatus(409);
    $this->postJson('http://b.localhost/api/mobile/v1/images/direct/'.$ticket['id'].'/complete')->assertUnauthorized();
});

function kycUrlFlow($test): void
{
    $flow = $test->getJson($test->base.'/bootstrap')->assertOk()->headers->get('X-Consumer-Flow');
    $test->withHeader('X-Consumer-Flow', $flow)->withHeader('X-Consumer-Page', '/kyc');
    $test->oss->denyNetwork = true;
}

it('passes scoped uploaded URLs to OCR without any server OSS calls or invented checksums', function () {
    kycUrlFlow($this);
    $ticket = $this->postJson($this->base.'/images/direct', ['purpose' => 'kyc', 'field' => 'front', 'mime' => 'image/png'])->assertOk()->json();
    expect($ticket['mode'])->toBe('kyc_url');
    expect($ticket['fields']['x-oss-object-acl'])->toBe('public-read');
    $provider = Mockery::mock(KycOcrProviderInterface::class);
    $provider->shouldReceive('name')->andReturn('TEST');
    $provider->shouldReceive('extractIdentityDocument')->once()
        ->with(Mockery::on(fn ($request) => $request->frontUrl === preg_replace('#^https://#', 'http://', $ticket['imageUrl']) && $request->backUrl === ''))
        ->andReturn(new KycOcrResultDTO(KycOcrOutcome::Success, 'E12345678'));
    app()->instance(KycOcrProviderInterface::class, $provider);
    $this->postJson($this->base.'/images/direct/'.$ticket['id'].'/complete')->assertUnprocessable();
    $this->postJson($this->base.'/client/kyc/applications', [
        'document_type' => 'PASSPORT', 'document_country' => 'CN', 'identity_number' => 'E12345678',
        'front_upload_id' => $ticket['id'], 'front_url' => $ticket['imageUrl'],
    ])->assertSuccessful();
    $application = KycApplication::where('user_id', $this->user->id)->sole();
    $record = app(ImageStorage::class)->record('private', $application->front_object_key);
    expect(app(ImageStorage::class)->ocrUrl('private', $application->front_object_key))->toBe(preg_replace('#^https://#', 'http://', $ticket['imageUrl']))
        ->and($record->sha256)->toBeNull()->and($record->size)->toBeNull()
        ->and(DirectImageUpload::find($ticket['id'])->verified_at)->toBeNull()
        ->and(DirectImageUpload::find($ticket['id'])->claimed_at)->not->toBeNull();
    Storage::disk('private')->assertMissing($application->front_object_key);
    Http::assertNothingSent();
    expect($this->oss->networkCalls)->toBe(0);
});

it('rejects arbitrary URLs field swaps missing URLs expired and foreign tickets before OCR', function () {
    kycUrlFlow($this);
    $provider = Mockery::mock(KycOcrProviderInterface::class);
    $provider->shouldNotReceive('extractIdentityDocument');
    app()->instance(KycOcrProviderInterface::class, $provider);
    $front = directFixture($this, 'kyc', 'front');
    $back = directFixture($this, 'kyc', 'back');
    $data = ['document_type' => 'PASSPORT', 'document_country' => 'CN', 'identity_number' => 'E12345678', 'front_upload_id' => $front['id']];
    foreach (['https://outside.example/image.png', $front['imageUrl'].'?x=1', $back['imageUrl'], null] as $url) {
        $this->postJson($this->base.'/client/kyc/applications', $data + ['front_url' => $url])->assertUnprocessable();
    }
    expect(fn () => app(DirectKycUploads::class)->resolve(
        $this->tenant->id, $this->user->id, $back['id'], 'front', $back['imageUrl']
    ))->toThrow(HttpException::class);
    $other = User::where('tenant_id', '!=', $this->tenant->id)->firstOrFail();
    expect(fn () => app(DirectKycUploads::class)->resolve(
        $other->tenant_id, $other->id, $front['id'], 'front', $front['imageUrl']
    ))->toThrow(ModelNotFoundException::class);
    $this->travel(16)->minutes();
    $this->postJson($this->base.'/client/kyc/applications', $data + ['front_url' => $front['imageUrl']])->assertStatus(410);
});

it('keeps both national ID originals but sends only the front to OCR and fails closed without synchronous OSS cleanup', function () {
    kycUrlFlow($this);
    $front = directFixture($this, 'kyc', 'front');
    $back = directFixture($this, 'kyc', 'back');
    $provider = Mockery::mock(KycOcrProviderInterface::class);
    $provider->shouldReceive('extractIdentityDocument')->once()
        ->with(Mockery::on(fn ($request) => $request->frontUrl === preg_replace('#^https://#', 'http://', $front['imageUrl']) && $request->backUrl === ''))
        ->andReturn(new KycOcrResultDTO(KycOcrOutcome::Failed));
    app()->instance(KycOcrProviderInterface::class, $provider);
    $this->postJson($this->base.'/client/kyc/applications', [
        'document_type' => 'NATIONAL_ID', 'document_country' => 'CN', 'identity_number' => '11010519491231002X',
        'front_upload_id' => $front['id'], 'front_url' => $front['imageUrl'],
        'back_upload_id' => $back['id'], 'back_url' => $back['imageUrl'],
    ])->assertUnprocessable();
    expect(KycApplication::where('user_id', $this->user->id)->count())->toBe(0);
    expect(StoredImage::whereIn('id', DirectImageUpload::pluck('image_id'))->pluck('state')->unique()->all())->toBe(['cleanup_pending']);
    expect(StoredImage::min('cleanup_after'))->not->toBeNull();
    expect($this->oss->networkCalls)->toBe(0);
});

it('requires the server replica and rejects conflicting backup bytes', function () {
    $ticket = $this->postJson($this->base.'/images/direct', ['purpose' => 'support', 'field' => 'support_image', 'mime' => 'image/png'])->assertOk()->json();
    $this->postJson($this->base.'/images/direct/'.$ticket['id'].'/complete')->assertStatus(409);
    $this->post($this->base.'/images/direct/'.$ticket['id'].'/backup', ['file' => kycTestImage()])->assertNoContent();
    $this->service->backup($this->tenant->id, $this->user->id, $ticket['id'], kycTestImage()->getContent());
    expect(fn () => $this->service->backup($this->tenant->id, $this->user->id, $ticket['id'], kycTestImage()->getContent().'different'))->toThrow(HttpException::class);
    expect(fn () => $this->service->backup($this->tenant->id, (string) Str::uuid(), $ticket['id'], kycTestImage()->getContent()))->toThrow(HttpException::class);
});

it('serves encrypted replicas during OSS outages and repairs only the image later', function () {
    $ticket = directFixture($this);
    $this->oss->denyNetwork = true;
    $this->postJson($this->base.'/images/direct/'.$ticket['id'].'/complete')->assertNoContent();
    $upload = DirectImageUpload::findOrFail($ticket['id']);
    $image = StoredImage::findOrFail($upload->image_id);
    expect($image->oss_pending)->toBeTrue()->and($image->backup_key)->not->toBeNull();
    expect(Storage::disk('private')->get($image->backup_key))->not->toContain(kycTestImage()->getContent());
    $file = $this->service->resolve($this->tenant->id, $this->user->id, $ticket['id'], 'support', 'support_image');
    $key = 'support/'.$this->tenant->id.'/'.Str::uuid();
    app(ImageStorage::class)->putUpload($this->tenant->id, 'private', $key, $file, 'support');
    $url = app(ImageStorage::class)->gatewayUrl(app(ImageStorage::class)->record('private', $key), 'preview');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get($url.'&profile=original')->assertForbidden();
    expect(app(ImageStorage::class)->read('private', $key))->toBe(kycTestImage()->getContent());
    $this->oss->denyNetwork = false;
    $this->artisan('images:replicate')->assertSuccessful();
    expect($image->fresh()->oss_pending)->toBeFalse()
        ->and($this->oss->objects[$image->object_key])->toBe(kycTestImage()->getContent())
        ->and($upload->fresh()->claimed_at)->not->toBeNull();
    $this->oss->objects[$image->object_key] = 'corrupted remote';
    expect(app(ImageStorage::class)->read('private', $key))->toBe(kycTestImage()->getContent());
});

it('rejects pending OSS originals before OCR even when a local replica exists', function () {
    kycUrlFlow($this);
    $ticket = app(DirectImageUploads::class)->authorize($this->tenant->id, $this->user->id, 'kyc', 'front', 'image/png');
    $this->post($this->base.'/images/direct/'.$ticket['id'].'/backup', ['file' => kycTestImage()])->assertNoContent();
    $this->postJson($this->base.'/images/direct/'.$ticket['id'].'/complete')->assertNoContent();
    $provider = Mockery::mock(KycOcrProviderInterface::class);
    $provider->shouldReceive('name')->andReturn('TEST');
    $provider->shouldNotReceive('extractIdentityDocument');
    app()->instance(KycOcrProviderInterface::class, $provider);
    $this->postJson($this->base.'/client/kyc/applications', ['document_type' => 'PASSPORT', 'document_country' => 'CN',
        'identity_number' => 'E12345678', 'front_upload_id' => $ticket['id']])->assertStatus(503);
    $record = StoredImage::find(DirectImageUpload::find($ticket['id'])->image_id);
    expect($record->sha256)->toBe(hash('sha256', kycTestImage()->getContent()))->and($record->oss_pending)->toBeTrue();
});

it('backs up multipart originals and rejects damaged local replicas', function () {
    $this->oss->denyNetwork = true;
    $key = 'branding/'.$this->tenant->id.'/'.Str::uuid();
    $storage = app(ImageStorage::class);
    $storage->put($this->tenant->id, 'public', $key, kycTestImage()->getContent(), 'branding');
    $image = $storage->record('public', $key);
    expect($image->oss_pending)->toBeTrue()->and($storage->read('public', $key))->toBe(kycTestImage()->getContent());
    Storage::disk('private')->put($image->backup_key, 'corrupted');
    expect(fn () => $storage->read('public', $key))->toThrow(RuntimeException::class);
});

it('accepts boolean reverify through the consumer HTTP endpoint and replaces identity only after approval', function (bool $textFlag) {
    kycUrlFlow($this);
    PlatformKycSetting::current()->update(['review_mode' => 'AUTOMATIC']);
    foreach (['E12345678', 'E87654321'] as $index => $number) {
        fakeMatchingKycOcr($number);
        $ticket = directFixture($this, 'kyc', 'front');
        $this->postJson($this->base.'/client/kyc/applications', [
            'document_type' => 'PASSPORT', 'document_country' => 'CN', 'reverify' => $textFlag ? ($index > 0 ? 'true' : 'false') : $index > 0,
            'front_upload_id' => $ticket['id'], 'front_url' => $ticket['imageUrl'],
        ])->assertSuccessful();
        $this->travel(1)->seconds();
    }
    expect(KycApplication::where('user_id', $this->user->id)->count())->toBe(2);
    expect(IdentityRecord::where('user_id', $this->user->id)->sole()->source_kyc_application_id)
        ->toBe(KycApplication::where('user_id', $this->user->id)->latest('submitted_at')->first()->id);
    expect($this->oss->networkCalls)->toBe(0);
})->with([false, true]);

it('rejects invalid reverification flags before OCR', function () {
    kycUrlFlow($this);
    $ticket = directFixture($this, 'kyc', 'front');
    $provider = Mockery::mock(KycOcrProviderInterface::class);
    $provider->shouldNotReceive('extractIdentityDocument');
    app()->instance(KycOcrProviderInterface::class, $provider);
    $this->postJson($this->base.'/client/kyc/applications', [
        'document_type' => 'PASSPORT', 'document_country' => 'CN', 'reverify' => 'invalid',
        'front_upload_id' => $ticket['id'], 'front_url' => $ticket['imageUrl'],
    ])->assertUnprocessable()->assertJsonValidationErrors('reverify');
});
