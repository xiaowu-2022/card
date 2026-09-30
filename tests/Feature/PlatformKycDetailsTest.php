<?php

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
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
