<?php

use Illuminate\Support\Facades\DB;
use App\Domain\Tenant\Models\TenantDomain;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->seed();
});

it('keeps quote and confirmation budgets separate and retains the ten-per-minute confirmation limit', function () {
    $this->postJson('http://a.localhost/api/v1/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])->assertOk();
    for ($i = 0; $i < 20; $i++) {
        // Invalid payloads exercise routing and throttling without money writes.
        $this->postJson('http://a.localhost/api/v1/client/promotion/quotes', [])->assertUnprocessable();
    }
    $this->postJson('http://a.localhost/api/v1/client/promotion/quotes', [])->assertStatus(429)->assertHeader('Retry-After');
    $path = 'http://a.localhost/api/v1/client/promotion/quotes/11111111-1111-4111-8111-111111111111/confirm';
    for ($i = 0; $i < 10; $i++) {
        $this->postJson($path, ['current_password' => 'incorrect', 'confirmed' => true])->assertUnprocessable();
    }
    $this->postJson($path, [])->assertStatus(429)->assertHeader('Retry-After');
    $this->travel(61)->seconds();
    $this->postJson($path, [])->assertUnprocessable();
});

it('does not let another account or company on the same IP consume the allowance', function () {
    $this->postJson('http://a.localhost/api/v1/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])->assertOk();
    $path = '/api/v1/client/promotion/quotes/11111111-1111-4111-8111-111111111111/confirm';
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('http://a.localhost'.$path, [])->assertUnprocessable();
    }
    $owner = User::where('email', 'user@a.localhost')->firstOrFail();
    $other = $owner->replicate(['account_id']);
    $other->id = (string) \Illuminate\Support\Str::uuid();
    $other->email = 'rate-other@a.localhost';
    $other->save();
    Auth::guard('tenant_user')->login($other);
    $this->postJson('http://a.localhost'.$path, [])->assertUnprocessable();
    $this->postJson('http://b.localhost/api/v1/login', ['identifier' => 'user@b.localhost', 'password' => 'local-password'])->assertOk();
    $this->postJson('http://b.localhost'.$path, [])->assertUnprocessable();
});

it('shares the same account budget across domain aliases, order IDs, legacy web and native APIs', function () {
    $user = User::where('email', 'user@a.localhost')->firstOrFail();
    TenantDomain::create(['tenant_id' => $user->tenant_id, 'hostname' => 'rate-alias.localhost', 'domain_type' => 'CUSTOM_DOMAIN', 'status' => 'ACTIVE', 'is_primary' => false]);
    $token = $this->postJson('http://a.localhost/api/mobile/v1/login', ['identifier' => $user->email, 'password' => 'local-password'])->assertCreated()->json('token');
    $flow = $this->withToken($token)->getJson('http://a.localhost/api/mobile/v1/bootstrap')->assertOk()->headers->get('X-Consumer-Flow');
    $this->withHeader('X-Consumer-Flow', $flow);
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('http://a.localhost/api/mobile/v1/client/promotion/quotes/'.\Illuminate\Support\Str::uuid().'/confirm', [])->assertUnprocessable();
    }
    $this->postJson('http://rate-alias.localhost/api/mobile/v1/client/promotion/quotes/'.\Illuminate\Support\Str::uuid().'/confirm', [])->assertStatus(429);
    $this->withoutHeader('Authorization')->withoutHeader('X-Consumer-Flow');
    $this->postJson('http://a.localhost/api/v1/login', ['identifier' => $user->email, 'password' => 'local-password'])->assertOk();
    $this->postJson('http://a.localhost/api/v1/client/promotion/quotes/'.\Illuminate\Support\Str::uuid().'/confirm', [])->assertStatus(429);
    $this->postJson('http://a.localhost/promotion/quotes/'.\Illuminate\Support\Str::uuid().'/confirm', [])->assertStatus(429);
});

it('does not spend annual payment attempts on background unread requests', function () {
    $this->postJson('http://a.localhost/api/v1/login', [
        'identifier' => 'user@a.localhost', 'password' => 'local-password',
    ])->assertOk();
    for ($i = 0; $i < 12; $i++) {
        $this->getJson('http://a.localhost/api/v1/unread')->assertOk();
    }
    $before = DB::table('ledger_entries')->count();
    $this->postJson('http://a.localhost/api/v1/client/promotion/quotes/11111111-1111-4111-8111-111111111111/confirm', [
        'current_password' => 'incorrect', 'confirmed' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    expect(DB::table('ledger_entries')->count())->toBe($before);
});
