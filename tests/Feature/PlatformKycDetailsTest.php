<?php

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\Media\OssConfiguration;
use App\Domain\Media\StoredImage;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Storage\OssImages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Storage::fake('private');
    $this->admin = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->admin->forceFill(['password' => Hash::make('synthetic-password')])->save();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->kyc = new KycApplication;
    $this->kyc->forceFill(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'document_type' => 'NATIONAL_ID', 'document_country' => 'CN',
        'identity_number_encrypted' => app(KycDataCipher::class)->encrypt('11010519491231002X'), 'identity_hash' => hash('sha256', 'synthetic'),
        'front_object_key' => 'kyc/test-front', 'back_object_key' => 'kyc/test-back', 'review_status' => 'APPROVED', 'reviewed_at' => now(), 'automatically_approved' => true, 'ocr_status' => 'SUCCEEDED', 'submitted_at' => now()])->save();
    Storage::disk('private')->put('kyc/test-front', kycTestImage()->getContent());
    Storage::disk('private')->put('kyc/test-back', kycTestImage()->getContent());
    $this->base = 'https://admin.localhost/platform/tenants/'.$this->tenant->id.'/kyc/'.$this->kyc->id;
    $this->actingAs($this->admin, 'platform_admin');
});
it('shows masked identity metadata and audited password-gated scoped photos', function () {
    $before = DB::table('audit_logs')->count();
    $this->get($this->base)->assertOk()->assertInertia(fn ($p) => $p->component('platform/KycDetail')->where('application.documentType', 'NATIONAL_ID')->where('canViewDocuments', true));
    expect(DB::table('audit_logs')->count())->toBe($before);
    $this->postJson($this->base.'/documents', ['password' => 'wrong'])->assertUnprocessable();
    $result = $this->postJson($this->base.'/documents', ['password' => 'synthetic-password'])->assertOk();
    expect(DB::table('audit_logs')->where('action', 'KYC_DOCUMENT_VIEWED')->count())->toBe(2);
    $url = $result->json('documents.front');
    $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->get(str_replace('/front?', '/back?', $url))->assertForbidden();
    $this->travel(20)->minutes();
    $this->get($url)->assertForbidden();
});
it('rejects cross-company paths and permission revocation', function () {
    $other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $this->get(str_replace($this->tenant->id, $other->id, $this->base))->assertNotFound();
    $this->postJson(str_replace($this->tenant->id, $other->id, $this->base).'/documents', ['password' => 'synthetic-password'])->assertNotFound();
    $url = $this->postJson($this->base.'/documents', ['password' => 'synthetic-password'])->assertOk()->json('documents.front');
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'kyc.document.view')->value('id'))->delete();
    $this->actingAs($this->admin->fresh(), 'platform_admin')->get($url)->assertForbidden();
    $this->postJson($this->base.'/documents', ['password' => 'synthetic-password'])->assertForbidden();
});

it('returns the current OSS domain without server reads even when a local backup exists', function () {
    $config = OssConfiguration::create(['region' => 'cn-beijing', 'bucket' => 'test-images',
        'endpoint' => 'https://oss-cn-beijing.aliyuncs.com', 'public_url' => 'https://images.example.com',
        'credentials' => ['access_key_id' => 'synthetic', 'access_key_secret' => 'synthetic'], 'created_by' => $this->admin->id]);
    DB::table('media_storage_settings')->where('id', 1)->update(['storage_driver' => 'oss', 'active_configuration_id' => $config->id]);
    foreach (['front', 'back'] as $side) {
        StoredImage::create(['tenant_id' => $this->tenant->id, 'source_disk' => 'private', 'source_key' => 'kyc/test-'.$side,
            'purpose' => 'kyc', 'configuration_id' => $config->id, 'object_key' => 'images/test-'.$side.'.png', 'mime' => 'image/png',
            'size' => 68, 'sha256' => hash('sha256', kycTestImage()->getContent()), 'state' => 'ready', 'oss_pending' => true, 'backup_key' => 'unused-backup']);
    }
    $oss = Mockery::mock(OssImages::class)->makePartial();
    $oss->shouldNotReceive('get');
    $oss->shouldNotReceive('getBounded');
    $oss->shouldNotReceive('display');
    app()->instance(OssImages::class, $oss);
    $r = $this->postJson($this->base.'/documents', ['password' => 'synthetic-password'])->assertOk();
    expect($r->json('documentSources.front'))->toHaveCount(3)
        ->and($r->json('documentSources.front.1'))->toBe('https://images.example.com/images/test-front.png')
        ->and($r->json('documentSources.front.2'))->toContain('delivery=replica')
        ->and($r->json('documents.front'))->toStartWith('https://images.example.com/images/test-front.png?x-oss-process=')
        ->and($r->json('documents.back'))->toStartWith('https://images.example.com/images/test-back.png?x-oss-process=');
});

it('loads only the selected users masked KYC history without document access or business writes', function () {
    $url = 'https://admin.localhost/platform/tenants/'.$this->tenant->id.'/users/'.$this->kyc->user_id.'/kyc';
    $before = collect(['audit_logs', 'ledger_entries', 'wallets', 'kyc_applications'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
    $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('application.id', $this->kyc->id)->assertJsonPath('user.id', $this->kyc->user_id)
        ->assertJsonPath('applications.total', 1)->assertJsonPath('canViewDocuments', true)
        ->assertJsonMissingPath('documents')->assertJsonMissingPath('application.identity_number_encrypted')
        ->assertJsonMissingPath('application.front_object_key')->assertDontSee('11010519491231002X');
    foreach ($before as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
    $other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $this->getJson(str_replace($this->tenant->id, $other->id, $url))->assertNotFound();
    $another = User::where('tenant_id', $this->tenant->id)->firstOrFail()->replicate(['account_id']);
    $another->forceFill(['email' => 'kyc-empty@example.test'])->save();
    $emptyUrl = str_replace($this->kyc->user_id, $another->id, $url);
    $this->getJson($emptyUrl)->assertOk()->assertJsonPath('application', null)->assertJsonPath('applications.total', 0);
    $this->getJson($emptyUrl.'?application='.$this->kyc->id)->assertNotFound();
    $this->getJson($url.'?page=0')->assertUnprocessable();
    $this->getJson($url.'?application=invalid')->assertUnprocessable();
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'kyc.document.view')->value('id'))->delete();
    $this->actingAs($this->admin->fresh(), 'platform_admin')->getJson($url)->assertOk()->assertJsonPath('canViewDocuments', false);
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'users.read')->value('id'))->delete();
    $this->actingAs($this->admin->fresh(), 'platform_admin')->getJson($url)->assertForbidden();
});

it('paginates user certification history and requires KYC read permission', function () {
    $ids = [];
    for ($i = 1; $i <= 21; $i++) {
        $application = $this->kyc->replicate();
        $application->forceFill(['submitted_at' => now()->addSeconds($i)])->save();
        $ids[] = $application->id;
    }
    $url = 'https://admin.localhost/platform/tenants/'.$this->tenant->id.'/users/'.$this->kyc->user_id.'/kyc';
    $this->getJson($url)->assertOk()->assertJsonCount(20, 'applications.items')
        ->assertJsonPath('applications.total', 22)->assertJsonPath('application.id', $ids[20]);
    $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(2, 'applications.items');
    $this->getJson($url.'?application='.$this->kyc->id)->assertOk()->assertJsonPath('application.id', $this->kyc->id);
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'kyc.read')->value('id'))->delete();
    $this->actingAs($this->admin->fresh(), 'platform_admin')->getJson($url)->assertForbidden();
});
