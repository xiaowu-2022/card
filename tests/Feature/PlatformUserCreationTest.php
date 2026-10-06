<?php

use App\Application\Kyc\UserKycQuery;
use App\Application\User\AuthenticateUserAction;
use App\Application\User\CreatePlatformUserAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false]);
    $this->company = Tenant::where('slug', 'tenant-a')->sole();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->sole();
    $this->url = 'http://admin.localhost/platform/tenants/'.$this->company->id.'/users';
    $this->data = ['email' => 'new-account@example.test', 'display_name' => 'New user', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'request_id' => (string) Str::uuid()];
});

it('creates a verified consumer with atomic zero wallet and audited provenance without fabricated KYC', function () {
    $entries = DB::table('ledger_entries')->count();
    $applications = DB::table('kyc_applications')->count();
    $identities = DB::table('identity_records')->count();
    $this->actingAs($this->owner, 'platform_admin')->get('http://admin.localhost/platform/users')->assertOk()->assertInertia(fn ($p) => $p->where('canCreateUser', true));
    $this->post($this->url, [...$this->data, 'email' => ' New-Account@Example.test '])->assertRedirect()->assertSessionHasNoErrors();
    $user = User::where('tenant_id', $this->company->id)->where('email', $this->data['email'])->sole();
    expect(app(AuthenticateUserAction::class)->execute($this->company->id, $user->email, 'secret123', null, null, null, null)?->id)->toBe($user->id);
    $this->getJson($this->url.'/'.$user->id.'/kyc')->assertOk()->assertJsonPath('platformVerified', true);
    expect(Hash::check('secret123', $user->password_hash))->toBeTrue()
        ->and($user->status->value)->toBe('ACTIVE')->and($user->account_id)->not->toBeNull()
        ->and($user->profile->display_name)->toBe('New user')->and($user->preference->locale)->not->toBeNull();
    expect(app(KycStatusService::class)->forUser($this->company->id, $user->id))->toBe(KycUserStatus::Approved);
    $kyc = app(UserKycQuery::class)->get($this->company->id, $user->id);
    expect($kyc['status'])->toBe('APPROVED')->and($kyc['frontUrl'])->toBeNull()->and($kyc['maskedIdentityNumber'])->toBeNull();
    expect(Wallet::where('tenant_id', $this->company->id)->where('user_id', $user->id)->sole()->status->value)->toBe('ACTIVE');
    expect(DB::table('ledger_accounts')->where('tenant_id', $this->company->id)->where('user_id', $user->id)->where('balance', '<>', 0)->count())->toBe(0);
    expect(DB::table('ledger_entries')->count())->toBe($entries)->and(DB::table('kyc_applications')->count())->toBe($applications)->and(DB::table('identity_records')->count())->toBe($identities);
    expect(DB::table('audit_logs')->where('action', 'PLATFORM_USER_CREATED')->where('resource_id', $user->id)->value('actor_id'))->toBe($this->owner->id);
    expect(DB::table('promotion_members')->where('tenant_id', $this->company->id)->where('user_id', $user->id)->count())->toBe(1);
    $this->post($this->url, $this->data)->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('platform_user_creations')->count())->toBe(1);
    $this->postJson($this->url, [...$this->data, 'display_name' => 'changed'])->assertConflict();
});

it('rejects duplicate email in a company but allows the same email in another company and keeps verification scoped', function () {
    $this->actingAs($this->owner, 'platform_admin')->post($this->url, $this->data)->assertSessionHasNoErrors();
    $this->postJson($this->url, [...$this->data, 'request_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('email');
    $other = Tenant::where('slug', 'tenant-b')->sole();
    $this->post(str_replace($this->company->id, $other->id, $this->url), $this->data)->assertSessionHasNoErrors();
    $user = User::where('tenant_id', $this->company->id)->where('email', $this->data['email'])->sole();
    expect(app(KycStatusService::class)->forUser($other->id, $user->id))->toBe(KycUserStatus::NotSubmitted);
});

it('requires write permission and validates company and passwords without creating users', function () {
    $count = User::count();
    $this->actingAs($this->owner, 'platform_admin')->postJson($this->url, [...$this->data, 'password_confirmation' => 'bad'])->assertUnprocessable();
    $this->postJson($this->url, [...$this->data, 'password' => '123', 'password_confirmation' => '123'])->assertUnprocessable();
    $this->postJson(str_replace($this->company->id, (string) Str::uuid(), $this->url), $this->data)->assertNotFound();
    $permission = DB::table('permissions')->where('name', 'users.create')->value('id');
    DB::table('role_permissions')->where('permission_id', $permission)->delete();
    $this->postJson($this->url, $this->data)->assertForbidden();
    expect(User::count())->toBe($count);
});

it('rolls back all creation records if wallet provisioning fails', function () {
    $count = User::count();
    $event = 'eloquent.creating: '.Wallet::class;
    Event::listen($event, fn () => throw new RuntimeException('offline test failure'));
    try {
        expect(fn () => app(CreatePlatformUserAction::class)->execute($this->company->id, $this->owner, $this->data))->toThrow(RuntimeException::class);
    } finally {
        Event::forget($event);
    }
    expect(User::count())->toBe($count)->and(DB::table('platform_user_creations')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'PLATFORM_USER_CREATED')->count())->toBe(0);
});

it('rejects inactive companies and preserves existing consumer verification state', function () {
    $existing = User::where('tenant_id', $this->company->id)->firstOrFail();
    $status = app(KycStatusService::class)->forUser($this->company->id, $existing->id);
    $count = User::count();
    $this->company->update(['status' => 'SUSPENDED']);
    $this->actingAs($this->owner, 'platform_admin')->postJson($this->url, $this->data)->assertUnprocessable()->assertJsonValidationErrors('company');
    expect(User::count())->toBe($count)->and(app(KycStatusService::class)->forUser($this->company->id, $existing->id))->toBe($status);
});
