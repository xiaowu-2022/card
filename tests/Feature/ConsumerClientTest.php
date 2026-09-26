<?php

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Auth\ConsumerDeviceToken;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->where('email', 'user@a.localhost')->firstOrFail();
});

function clientFlow($test): string
{
    return $test->getJson('http://a.localhost/api/mobile/v1/bootstrap')->assertOk()->headers->get('X-Consumer-Flow');
}

function clientToken($test): string
{
    return $test->postJson('http://a.localhost/api/mobile/v1/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])
        ->assertCreated()->json('token');
}

it('serves the same registration DTO as web without HTML or admin data', function () {
    $this->getJson('http://a.localhost/api/v1/client/register')->assertOk()
        ->assertJsonPath('component', 'user/Register')->assertJsonStructure(['props' => ['registration' => ['emailAvailable', 'invitationCode', 'invitationLocked']]])
        ->assertJsonMissingPath('props.auth.admin');
});

it('isolates native form proofs by opaque flow and company without browser authentication', function () {
    $flow = clientFlow($this);
    expect($flow)->toHaveLength(64);
    $this->getJson('http://a.localhost/api/mobile/v1/client/register')->assertStatus(419);
    $this->withHeader('X-Consumer-Flow', $flow)->getJson('http://a.localhost/api/mobile/v1/client/register')->assertOk()->assertJsonPath('props.auth.user', null);
    $this->withHeader('X-Consumer-Flow', $flow)->getJson('http://b.localhost/api/mobile/v1/client/register')->assertStatus(419);
    $this->withHeader('X-Consumer-Flow', $flow)->getJson('http://a.localhost/api/mobile/v1/client/account/security')->assertUnauthorized();
});

it('returns exact scoped screen DTOs without financial writes', function () {
    $flow = clientFlow($this);
    $token = clientToken($this);
    $before = [DB::table('ledger_entries')->count(), DB::table('wallets')->count()];
    $this->withToken($token)->withHeader('X-Consumer-Flow', $flow);
    foreach (['account' => 'Account', 'account/security' => 'Security', 'cards' => 'Cards', 'account/settings' => 'AccountSettings', 'promotion/registration' => 'AcademyRegistration'] as $url => $component) {
        $this->getJson('http://a.localhost/api/mobile/v1/client/'.$url)->assertOk()->assertJsonPath('component', 'user/'.$component)->assertJsonMissingPath('props.auth.admin');
    }
    expect([DB::table('ledger_entries')->count(), DB::table('wallets')->count()])->toBe($before);
    $this->getJson('http://a.localhost/api/mobile/v1/client/register')->assertStatus(409);
    $this->getJson('http://a.localhost/api/mobile/v1/client/__mock/payments/00000000-0000-0000-0000-000000000000')->assertNotFound();
});

it('keeps restricted security accessible while rejecting financial operations', function () {
    $flow = clientFlow($this);
    $token = clientToken($this);
    $this->user->forceFill(['status' => 'SUSPENDED'])->save();
    $this->withToken($token)->withHeader('X-Consumer-Flow', $flow);
    $this->getJson('http://a.localhost/api/mobile/v1/client/account/security')->assertOk();
    $this->postJson('http://a.localhost/api/mobile/v1/client/wallet/transfers', [])->assertForbidden();
});

it('retains the current native device and invalidates others after explicit session revocation', function () {
    $flow = clientFlow($this);
    $one = clientToken($this);
    $two = clientToken($this);
    $this->withToken($one)->withHeader('X-Consumer-Flow', $flow)->withHeader('X-Consumer-Page', '/account/security')
        ->postJson('http://a.localhost/api/mobile/v1/client/account/security/sessions/revoke', ['current_password' => 'local-password', 'confirmed' => true])
        ->assertOk()->assertJsonPath('redirect', '/account/security')->assertJsonPath('csrfToken', null);
    $this->withToken($one)->getJson('http://a.localhost/api/mobile/v1/account')->assertOk();
    $this->withToken($two)->getJson('http://a.localhost/api/mobile/v1/account')->assertUnauthorized();
    expect(ConsumerDeviceToken::count())->toBe(2);
});

it('does not allow client Inertia partial headers to suppress screen data', function () {
    $this->withHeaders(['X-Inertia-Partial-Data' => 'auth.admin', 'X-Inertia-Partial-Component' => 'user/Register'])
        ->getJson('http://a.localhost/api/v1/client/register')->assertOk()->assertJsonStructure(['props' => ['registration']]);
});

it('preserves actionable field validation and native multipart KYC validation without OCR calls', function () {
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    $flow = clientFlow($this);
    $token = clientToken($this);
    $before = DB::table('ledger_entries')->count();
    $this->withToken($token)->withHeader('X-Consumer-Flow', $flow)->withHeader('X-Consumer-Page', '/kyc');
    $this->postJson('http://a.localhost/api/mobile/v1/client/account/security/password', [
        'current_password' => 'incorrect', 'password' => 'new-password', 'password_confirmation' => 'different',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->post('http://a.localhost/api/mobile/v1/client/kyc/applications', [
        'document_type' => 'NATIONAL_ID', 'document_country' => 'CN', 'identity_number' => '',
        'front' => kycTestImage(), 'back' => kycTestImage(),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('identity_number');
    expect(DB::table('ledger_entries')->count())->toBe($before);
});

it('converts scoped native form redirects without accepting an external return URL', function () {
    $flow = clientFlow($this);
    $token = clientToken($this);
    $this->withToken($token)->withHeader('X-Consumer-Flow', $flow)->withHeader('X-Consumer-Page', '//outside.invalid')
        ->postJson('http://a.localhost/api/mobile/v1/client/account/information/name', ['display_name' => 'Offline display name'])
        ->assertOk()->assertJsonPath('redirect', '/account/security')->assertJsonPath('csrfToken', null);
    expect($this->user->fresh()->profile->display_name)->toBe('Offline display name');
    $this->postJson('http://b.localhost/api/mobile/v1/client/account/information/name', ['display_name' => 'Cross company'])
        ->assertUnauthorized();
    expect($this->user->fresh()->profile->display_name)->toBe('Offline display name');
});
