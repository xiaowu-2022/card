<?php

use App\Application\Kyc\PreviewKycNumber;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Media\DirectImageUploads;
use App\Application\Media\VerifiedDirectImage;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Media\DirectImageUpload;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed();
    config(['media.storage' => 'server', 'kyc.ocr_driver' => 'image_url']);
    Storage::fake('private');
    Http::preventStrayRequests();
    $this->ocrTexts = ['测试姓名', '公民身份号码11010519491231002X'];
    Http::fake(['202.95.12.185:9601/*' => fn () => Http::response(['texts' => $this->ocrTexts])]);
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->where('email', 'user@a.localhost')->firstOrFail();
    PlatformKycSetting::current()->update(['review_mode' => 'AUTOMATIC']);
});

function previewUpload($test, string $field = 'front'): VerifiedDirectImage
{
    $uploads = app(DirectImageUploads::class);
    $ticket = $uploads->authorize($test->tenant->id, $test->user->id, 'kyc', $field, 'image/png');
    $uploads->backup($test->tenant->id, $test->user->id, $ticket['id'], kycTestImage()->getContent());
    $uploads->complete($test->tenant->id, $test->user->id, $ticket['id']);

    return $uploads->resolve($test->tenant->id, $test->user->id, $ticket['id'], 'kyc', $field);
}

function recognizePreview($test, string $id): array
{
    return app(PreviewKycNumber::class)->execute($test->tenant, $test->user, $id, KycDocumentType::NationalId, 'CN', false);
}

it('returns the front number once and reuses encrypted bound evidence for submission without recognizing the back', function () {
    $front = previewUpload($this);
    $back = previewUpload($this, 'back');
    $preview = recognizePreview($this, $front->id);
    expect($preview['identityNumber'])->toBe('11010519491231002X');
    expect(recognizePreview($this, $front->id))->toBe($preview);
    $upload = DirectImageUpload::findOrFail($front->id);
    expect($upload->kyc_ocr_evidence_encrypted)->not->toContain('11010519491231002X');
    expect($upload->toArray())->not->toHaveKey('kyc_ocr_evidence_encrypted');
    expect(KycApplication::count())->toBe(0)->and(IdentityRecord::count())->toBe(0);
    $application = app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', 'CLIENT-CANNOT-OVERRIDE', $front, $back);
    expect($application->automatically_approved)->toBeTrue()->and($application->ocr_provider)->toBe('image_url');
    expect(DirectImageUpload::findOrFail($front->id)->claimed_at)->not->toBeNull();
    expect(fn () => recognizePreview($this, $front->id))->toThrow(Exception::class);
    Http::assertSentCount(1);
});

it('serves a no-store authenticated preview and excludes client numbers and arbitrary URLs', function () {
    $front = previewUpload($this);
    $url = 'http://a.localhost/api/v1/client/kyc/recognize-front';
    $data = ['front_upload_id' => $front->id, 'document_type' => 'NATIONAL_ID', 'document_country' => 'CN'];
    $this->postJson($url, $data)->assertUnauthorized();
    $this->actingAs($this->user, 'tenant_user')->postJson($url, $data + ['image' => 'http://example.com/private'])->assertUnprocessable();
    $response = $this->postJson($url, $data + ['identity_number' => 'CLIENT-IGNORED'])->assertOk()
        ->assertJsonPath('identityNumber', '11010519491231002X')->assertJsonPath('frontUploadId', $front->id);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect(KycApplication::count())->toBe(0);
    Http::assertSentCount(1);
});

it('rejects other users or tenants before OCR', function ($scope) {
    $front = previewUpload($this);
    $other = $scope === 'tenant'
        ? User::where('tenant_id', '<>', $this->tenant->id)->firstOrFail()
        : User::create(['tenant_id' => $this->tenant->id, 'email' => 'preview-other@example.test', 'password_hash' => bcrypt('test-password'), 'status' => 'ACTIVE']);
    expect(fn () => app(PreviewKycNumber::class)->execute(Tenant::findOrFail($other->tenant_id), $other, $front->id, KycDocumentType::NationalId, 'CN', false))->toThrow(Exception::class);
    Http::assertNothingSent();
})->with(['tenant', 'user']);

it('rejects back references expired and consumed uploads before OCR', function ($mode) {
    $front = previewUpload($this, $mode === 'back' ? 'back' : 'front');
    if ($mode === 'expired') {
        DirectImageUpload::findOrFail($front->id)->update(['expires_at' => now()->subSecond()]);
    }
    if ($mode === 'claimed') {
        DirectImageUpload::findOrFail($front->id)->update(['claimed_at' => now()]);
    }
    expect(fn () => recognizePreview($this, $front->id))->toThrow(Exception::class);
    Http::assertNothingSent();
})->with(['back', 'expired', 'claimed']);

it('rejects altered copied or context-mismatched preview evidence without another OCR request', function ($mode) {
    $front = previewUpload($this);
    recognizePreview($this, $front->id);
    $upload = DirectImageUpload::findOrFail($front->id);
    $type = KycDocumentType::NationalId;
    $country = 'CN';
    if ($mode === 'ciphertext') {
        $upload->update(['kyc_ocr_evidence_encrypted' => 'corrupt']);
    }
    if ($mode === 'copied') {
        $front = previewUpload($this);
        DirectImageUpload::findOrFail($front->id)->update(['kyc_ocr_evidence_encrypted' => $upload->kyc_ocr_evidence_encrypted]);
    }
    if ($mode === 'bytes') {
        $front = new VerifiedDirectImage($front->id, $front->tenantId, $front->userId, 'kyc', 'front', 'different-bytes', 'image/png');
    }
    if ($mode === 'type') {
        $type = KycDocumentType::Passport;
    }
    if ($mode === 'country') {
        $country = 'US';
    }
    expect(fn () => app(PreviewKycNumber::class)->resultFor($front, $type, $country))->toThrow(Exception::class);
    Http::assertSentCount(1);
})->with(['ciphertext', 'copied', 'bytes', 'type', 'country']);

it('does not store preview evidence for an invalid checksum', function () {
    $this->ocrTexts = ['公民身份号码110105194912310021'];
    $front = previewUpload($this);
    expect(fn () => recognizePreview($this, $front->id))->toThrow(Exception::class);
    expect(DirectImageUpload::findOrFail($front->id)->kyc_ocr_evidence_encrypted)->toBeNull();
    expect(KycApplication::count())->toBe(0)->and(IdentityRecord::count())->toBe(0);
});

it('isolates repeated front uploads and OCR from background traffic on web and mobile', function (string $surface) {
    $base = 'http://a.localhost/api/'.$surface;
    if ($surface === 'v1') {
        $this->actingAs($this->user, 'tenant_user');
    } else {
        $token = $this->postJson($base.'/login', ['identifier' => $this->user->email, 'password' => 'local-password'])
            ->assertCreated()->json('token');
        $this->withToken($token);
        $flow = $this->getJson($base.'/bootstrap')->assertOk()->headers->get('X-Consumer-Flow');
        $this->withHeader('X-Consumer-Flow', $flow);
    }
    for ($i = 0; $i < 12; $i++) {
        $this->getJson($base.'/unread')->assertOk();
    }
    for ($i = 0; $i < 5; $i++) {
        $id = $this->postJson($base.'/images/direct', ['purpose' => 'kyc', 'field' => 'front', 'mime' => 'image/png', 'recognize_front' => true])
            ->assertOk()->json('id');
        $this->post($base.'/images/direct/'.$id.'/backup', ['file' => kycTestImage()])->assertNoContent();
        $this->postJson($base.'/images/direct/'.$id.'/complete')->assertNoContent();
        $data = ['front_upload_id' => $id, 'document_type' => 'NATIONAL_ID', 'document_country' => 'CN'];
        $this->postJson($base.'/client/kyc/recognize-front', $data)->assertOk();
    }
    // Repeated recognition reuses evidence but still retains the ten-request limit.
    for ($i = 0; $i < 5; $i++) {
        $this->postJson($base.'/client/kyc/recognize-front', $data)->assertOk();
    }
    $this->postJson($base.'/client/kyc/recognize-front', $data)->assertTooManyRequests()->assertHeader('Retry-After');
    $this->postJson($base.'/images/direct/'.$id.'/complete')->assertNoContent();
    $this->getJson($base.'/unread')->assertOk();
    Http::assertSentCount(5);
    $this->travel(61)->seconds();
    $this->postJson($base.'/client/kyc/recognize-front', $data)->assertOk();
    Http::assertSentCount(5);
})->with(['v1', 'mobile/v1']);

it('does not share upload or preview limits between users behind the same IP', function () {
    $base = 'http://a.localhost/api/v1';
    $this->actingAs($this->user, 'tenant_user');
    for ($i = 0; $i < 20; $i++) {
        $this->postJson($base.'/images/direct', [])->assertUnprocessable();
    }
    $this->postJson($base.'/images/direct', [])->assertTooManyRequests();
    for ($i = 0; $i < 10; $i++) {
        $this->postJson($base.'/client/kyc/recognize-front', [])->assertUnprocessable();
    }
    $this->postJson($base.'/client/kyc/recognize-front', [])->assertTooManyRequests();
    $other = User::create(['tenant_id' => $this->tenant->id, 'email' => 'upload-limit@example.test', 'password_hash' => bcrypt('test-password'), 'status' => 'ACTIVE']);
    $this->actingAs($other, 'tenant_user');
    $this->postJson($base.'/images/direct', [])->assertUnprocessable();
    $this->postJson($base.'/client/kyc/recognize-front', [])->assertUnprocessable();
    Http::assertNothingSent();
});
