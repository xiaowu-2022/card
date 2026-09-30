<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Kyc\UserKycQuery;
use App\Application\Media\ImageStorage;
use App\Application\Media\OssSettings;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Storage\OssImages;
use Illuminate\Support\Facades\Storage;

it('shows only the current users approved identity information and photos without changing it', function () {
    $this->seed();
    config(['media.storage' => 'server', 'inertia.ssr.enabled' => false]);
    Storage::fake('private');
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $user = User::where('tenant_id', $tenant->id)->firstOrFail();
    fakeMatchingKycOcr('11010519491231002X');
    $application = app(SubmitKycApplicationAction::class)->execute($tenant, $user, 'CN', '11010519491231002X', kycTestImage(), kycTestImage('back.png'));
    app(ApproveKycAction::class)->execute($tenant->id, $application->id, AdminUser::where('email', 'owner@a.localhost')->firstOrFail());
    $before = $application->fresh()->getAttributes();
    $this->actingAs($user, 'tenant_user')->get('http://a.localhost/kyc')->assertOk()
        ->assertInertia(fn ($page) => $page->component('user/Kyc')->where('canSubmit', false)
            ->where('kyc.status', 'APPROVED')->where('kyc.documentType', 'NATIONAL_ID')
            ->where('kyc.frontUrl', fn ($url) => str_contains($url, '/media/images/') && str_contains($url, 'signature='))
            ->where('kyc.backUrl', fn ($url) => str_contains($url, '/media/images/') && str_contains($url, 'signature=')));
    $query = app(UserKycQuery::class);
    expect($query->get($tenant->id, $user->id)['maskedIdentityNumber'])->not->toBe('11010519491231002X');
    $other = User::where('tenant_id', '<>', $tenant->id)->firstOrFail();
    expect($query->get($other->tenant_id, $other->id)['frontUrl'])->toBeNull();
    expect($query->get($other->tenant_id, $user->id)['backUrl'])->toBeNull();
    expect($application->fresh()->getAttributes())->toBe($before);

    $settings = app(OssSettings::class);
    $owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $config = $settings->save(['region' => 'cn-beijing', 'bucket' => 'test-images',
        'endpoint' => 'https://oss-cn-beijing.aliyuncs.com', 'public_url' => 'https://images.example.com',
        'access_key_id' => 'synthetic-key', 'access_key_secret' => 'synthetic-secret'], $owner);
    $settings->activate($config, $owner);
    config(['media.storage' => 'oss']);
    $oss = Mockery::mock(OssImages::class)->makePartial();
    foreach (['get', 'getBounded', 'display', 'put'] as $method) {
        $oss->shouldNotReceive($method);
    }
    app()->instance(OssImages::class, $oss);
    $photos = $query->get($tenant->id, $user->id);
    foreach (['front', 'back'] as $side) {
        $image = app(ImageStorage::class)->record('private', $application->{$side.'_object_key'});
        expect($image->backup_key)->not->toBeEmpty();
        expect($photos[$side.'Url'])->toStartWith('https://images.example.com/'.$image->object_key.'?x-oss-process=')
            ->not->toContain('/media/images/', 'signature=');
    }
    expect($application->fresh()->getAttributes())->toBe($before);
});
