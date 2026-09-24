<?php

use App\Application\Kyc\SubmitKycApplicationAction;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    config(['kyc.ocr_driver' => 'aliyun', 'kyc.aliyun.access_key_id' => 'synthetic-key', 'kyc.aliyun.access_key_secret' => 'synthetic-secret']);
    Http::preventStrayRequests();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    PlatformKycSetting::current()->update(['review_mode' => 'AUTOMATIC']);
});

function aliyunKycFake(string $number = 'E12345678'): void
{
    Http::fake(['ocr-api.cn-hangzhou.aliyuncs.com/*' => Http::response(['RequestId' => 'synthetic-request', 'Data' => json_encode(['data' => ['passportNumber' => $number, 'nameEn' => 'TEST USER']])])]);
}

it('recognizes passport number before approving and stores only one private image', function (): void {
    aliyunKycFake();
    $application = app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', 'E12345678', kycTestImage('passport.jpg'), null, documentType: KycDocumentType::Passport);
    expect($application->automatically_approved)->toBeTrue()->and($application->back_object_key)->toBeNull()
        ->and(IdentityRecord::count())->toBe(1)->and(Storage::disk('private')->allFiles())->toHaveCount(1);
    Http::assertSent(fn ($request) => $request->hasHeader('x-acs-action', 'RecognizeChinesePassport') && $request->hasHeader('Authorization'));
});

it('rejects missing or mismatched recognized numbers without creating an approved identity', function (string $number): void {
    aliyunKycFake($number);
    expect(fn () => app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', 'E12345678', kycTestImage('passport.jpg'), null, documentType: KycDocumentType::Passport))->toThrow(DomainException::class);
    expect(IdentityRecord::count())->toBe(0)->and(KycApplication::count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
})->with(['', 'E99999999']);

it('fails closed on upstream failure without exposing raw errors', function (): void {
    Http::fake(['*' => Http::response(['Code' => 'noPermission', 'Message' => 'sensitive-marker'], 403)]);
    expect(fn () => app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', 'E12345678', kycTestImage('passport.jpg'), null, documentType: KycDocumentType::Passport))
        ->toThrow(DomainException::class, 'Document recognition is temporarily unavailable.');
    expect(IdentityRecord::count())->toBe(0);
});

it('recognizes both sides of mainland identity cards and checks the number checksum', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push(['Data' => json_encode(['data' => ['face' => ['data' => ['idNumber' => '11010519491231002X', 'name' => '测试']]]])])
        ->push(['Data' => json_encode(['data' => ['back' => ['data' => ['issueAuthority' => '测试签发机关', 'validPeriod' => '2020.01.01-2040.01.01']]]])])]);
    $application = app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'CN', '11010519491231002X', kycTestImage('front.jpg'), kycTestImage('back.jpg'));
    expect($application->automatically_approved)->toBeTrue();
    Http::assertSentCount(2);
});

it('requires the correct image count and mainland country at the HTTP boundary', function (): void {
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/kyc/applications', [
        'document_type' => 'NATIONAL_ID', 'document_country' => 'MY', 'identity_number' => '11010519491231002X', 'front' => kycTestImage('front.jpg'),
    ])->assertSessionHasErrors(['document_country', 'back']);
    Http::assertNothingSent();
});
