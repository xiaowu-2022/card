<?php

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Mail\UserVerificationCodeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    Mail::fake();
    Http::preventStrayRequests();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->where('email', 'user@a.localhost')->firstOrFail();
});

it('completes web registration through the client API and restores cookie bootstrap', function () {
    $before = DB::table('ledger_entries')->count();
    $base = 'http://a.localhost/api/v1';
    $this->getJson($base.'/bootstrap')->assertOk()->assertJsonPath('user', null);
    $this->postJson($base.'/client/register/challenges', ['channel' => 'EMAIL', 'destination' => 'web-acceptance@example.test', 'invitation_code' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors('invitation_code');
    Mail::assertNothingSent();
    $path = $this->postJson($base.'/client/register/challenges', ['channel' => 'EMAIL', 'destination' => 'web-acceptance@example.test', 'invitation_code' => registrationTestInvitation()])
        ->assertOk()->json('redirect');
    $code = Mail::sent(UserVerificationCodeMail::class)->last()->code;
    $wrong = $code === '000000' ? '111111' : '000000';
    $this->postJson($base.'/client'.$path.'/verify', ['code' => $wrong])->assertUnprocessable();
    $this->getJson($base.'/client'.$path)->assertOk()->assertJsonPath('props.challenge.status', 'PENDING');
    $this->postJson($base.'/client'.$path.'/verify', ['code' => $code])->assertOk();
    $this->postJson($base.'/client'.$path.'/complete', ['display_name' => 'Acceptance user', 'password' => 'Offline123', 'password_confirmation' => 'different', 'locale' => 'en'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->postJson($base.'/client'.$path.'/complete', ['display_name' => 'Acceptance user', 'password' => 'Offline123', 'password_confirmation' => 'Offline123', 'locale' => 'en'])
        ->assertOk()->assertJsonPath('redirect', '/dashboard')->assertJsonMissingPath('token');
    $this->getJson($base.'/bootstrap')->assertOk()->assertJsonPath('user.email', 'web-acceptance@example.test');
    expect(DB::table('ledger_entries')->count())->toBe($before);
    expect(User::where('tenant_id', $this->tenant->id)->where('email', 'web-acceptance@example.test')->count())->toBe(1);
});

it('recovers a password through an isolated native flow and revokes the old device token', function () {
    $base = 'http://a.localhost/api/mobile/v1';
    $redirector = app('redirect');
    $old = $this->postJson($base.'/login', ['identifier' => $this->user->email, 'password' => 'local-password'])->assertCreated()->json('token');
    $flow = $this->getJson($base.'/bootstrap')->assertOk()->headers->get('X-Consumer-Flow');
    $this->withHeader('X-Consumer-Flow', $flow);
    $payload = ['channel' => 'EMAIL', 'reset_contact' => $this->user->email, 'request_id' => (string) Str::uuid()];
    $path = $this->postJson($base.'/client/forgot-password', $payload)->assertOk()->json('redirect');
    $this->postJson($base.'/client/forgot-password', $payload)->assertOk()->assertJsonPath('redirect', $path);
    Mail::assertSent(UserVerificationCodeMail::class, 1);
    $code = Mail::sent(UserVerificationCodeMail::class)->last()->code;
    $this->flushHeaders();
    $other = $this->getJson($base.'/bootstrap')->assertOk()->headers->get('X-Consumer-Flow');
    $this->withHeader('X-Consumer-Flow', $other)->getJson($base.'/client'.$path)->assertUnprocessable();
    $this->withHeader('X-Consumer-Flow', $flow)->getJson($base.'/client'.$path)->assertOk()->assertJsonPath('component', 'user/ResetPassword');
    $complete = ['code' => $code, 'password' => 'Reset123', 'password_confirmation' => 'Reset123', 'confirmed' => false];
    $this->postJson($base.'/client'.$path, $complete)->assertUnprocessable()->assertJsonValidationErrors('confirmed');
    $this->postJson($base.'/client'.$path, [...$complete, 'confirmed' => true])->assertOk()->assertJsonPath('redirect', '/login')
        ->assertJsonPath('success', 'Password reset. Sign in with your new password.');
    expect(Hash::check('Reset123', $this->user->fresh()->password_hash))->toBeTrue();
    expect(app('redirect'))->toBe($redirector);
    expect(app('session.store')->get('success'))->toBeNull();
    $this->withToken($old)->getJson($base.'/account')->assertUnauthorized();
    $this->flushHeaders();
    $this->postJson($base.'/login', ['identifier' => $this->user->email, 'password' => 'local-password'])->assertUnprocessable();
    $new = $this->postJson($base.'/login', ['identifier' => $this->user->email, 'password' => 'Reset123'])->assertCreated()->json('token');
    $this->withToken($new)->getJson($base.'/bootstrap')->assertOk()->assertJsonPath('user.id', $this->user->id);
});

it('rejects unconfigured locales and caller supplied company scope', function () {
    $base = 'http://a.localhost/api/v1/client/locale';
    $this->postJson($base, ['locale' => 'en'])->assertOk()->assertJsonPath('locale', 'en');
    $this->postJson($base, ['locale' => 'unsupported'])->assertUnprocessable();
    $this->postJson($base, ['locale' => 'en', 'tenant_id' => $this->tenant->id])->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
    Mail::assertNothingSent();
});

it('keeps recovery rate limits while bootstrap and locale reads do not consume them', function () {
    $base = 'http://a.localhost/api/v1';
    for ($i = 0; $i < 6; $i++) {
        $this->getJson($base.'/bootstrap')->assertOk();
        $this->postJson($base.'/client/locale', ['locale' => 'en'])->assertOk();
    }
    for ($i = 0; $i < 5; $i++) {
        $this->postJson($base.'/client/forgot-password', [])->assertUnprocessable();
    }
    $this->postJson($base.'/client/forgot-password', [])->assertTooManyRequests();
    $this->postJson($base.'/login', ['identifier' => $this->user->email, 'password' => 'local-password'])->assertOk();
    Mail::assertNothingSent();
});
