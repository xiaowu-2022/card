<?php

use App\Application\Media\ImageStorage;
use App\Application\Media\OssSettings;
use App\Application\Promotion\PromotionQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Storage\OssImages;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed();
    Storage::fake('private');
    $this->company = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $this->platform = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->base = 'http://admin.localhost/platform/tenants/'.$this->company->id.'/configuration/invitation-poster';
});

it('saves and serves only the current company poster with administrator provenance', function () {
    $this->actingAs($this->platform, 'platform_admin')->post($this->base, ['background' => posterTestImage()])->assertRedirect()->assertSessionHasNoErrors();
    $path = $this->company->businessSettings()->value('invitation_poster_background');
    expect(app(ImageStorage::class)->read('private', $path))->toBe(posterTestImage()->getContent());
    expect($this->other->businessSettings()->value('invitation_poster_background'))->toBeNull();
    $this->get($this->base.'/background')->assertOk()->assertHeader('Content-Type', 'image/png');
    $user = User::where('tenant_id', $this->company->id)->firstOrFail();
    $this->actingAs($user, 'tenant_user')->get('http://a.localhost/promotion/poster-background')->assertOk();
    $this->get('http://a.localhost/promotion')->assertInertia(fn ($page) => $page->where('home.posterBackground', '/promotion/poster-background'));
    $otherUser = User::where('tenant_id', $this->other->id)->firstOrFail();
    $this->actingAs($otherUser, 'tenant_user')->get('http://b.localhost/promotion/poster-background')->assertNotFound();
    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->company->id, 'action' => 'INVITATION_POSTER_CONFIGURED', 'actor_id' => $this->platform->id]);
});

it('rejects non-raster uploads and unauthorized configuration', function () {
    $this->actingAs($this->platform, 'platform_admin')->post($this->base, ['background' => UploadedFile::fake()->create('poster.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('background');
    expect($this->company->businessSettings()->value('invitation_poster_background'))->toBeNull();
    $this->actingAs(AdminUser::where('email', 'owner@a.localhost')->firstOrFail(), 'platform_admin')->post($this->base, ['background' => posterTestImage()])->assertForbidden();
});

function posterTestImage(): UploadedFile
{
    $chunk = fn ($type, $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 600, 900, 8, 2, 0, 0, 0))
        .$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\x20\x60\x50", 600), 900)))
        .$chunk('IEND', '');

    return UploadedFile::fake()->createWithContent('poster.png', $png);
}

it('serves uploaded OSS posters through scoped routes even when OSS upload or processing fails', function (bool $uploadFails) {
    config(['media.storage' => 'oss', 'inertia.ssr.enabled' => false]);
    $configuration = app(OssSettings::class)->save([
        'region' => 'ap-southeast-1', 'bucket' => 'test-images',
        'endpoint' => 'https://oss-ap-southeast-1.aliyuncs.com', 'public_url' => 'https://images.example.com',
        'access_key_id' => 'synthetic-key', 'access_key_secret' => 'synthetic-secret',
    ], $this->platform);
    app(OssSettings::class)->activate($configuration, $this->platform);
    $oss = Mockery::mock(OssImages::class);
    $put = $oss->shouldReceive('put')->once();
    if ($uploadFails) {
        $put->andThrow(new RuntimeException('OSS unavailable'));
        $oss->shouldNotReceive('display');
    } else {
        $put->andReturnNull();
        $oss->shouldReceive('display')->twice()->andThrow(new RuntimeException('OSS processing failed'));
    }
    $oss->shouldNotReceive('getBounded');
    app()->instance(OssImages::class, $oss);
    $bytes = posterTestImage()->getContent();
    $this->actingAs($this->platform, 'platform_admin')->post($this->base, ['background' => posterTestImage()])->assertSessionHasNoErrors();
    $key = $this->company->businessSettings()->value('invitation_poster_background');
    $image = app(ImageStorage::class)->record('private', $key);
    expect($image->oss_pending)->toBe($uploadFails);
    $snapshot = $image->getRawOriginal();
    $auditCount = DB::table('audit_logs')->count();
    $this->get('http://admin.localhost/platform/tenants/'.$this->company->id.'/configuration/promotion')
        ->assertInertia(fn ($page) => $page->where('posterBackground', '/platform/tenants/'.$this->company->id.'/configuration/invitation-poster/background?v='.hash('sha256', $key)));
    $this->get($this->base.'/background')->assertOk()->assertHeader('Content-Type', 'image/png')->assertContent($bytes);
    $user = User::where('tenant_id', $this->company->id)->firstOrFail();
    expect(app(PromotionQuery::class)->home($this->company->id, $user->id)['posterBackground'])->toBe('/promotion/poster-background');
    $this->actingAs($user, 'tenant_user')->get('http://a.localhost/promotion/poster-background')->assertOk()->assertContent($bytes);
    expect($image->fresh()->getRawOriginal())->toBe($snapshot);
    expect(DB::table('audit_logs')->count())->toBe($auditCount);
})->with([true, false]);
