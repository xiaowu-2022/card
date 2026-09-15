<?php

use App\Application\User\RegisterUserAction;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\RegistrationChallengeStatus;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\RegistrationChallenge;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed();
    $this->idTenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->idOtherTenant = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
});

function accountIdFixture(string $tenantId, string $createdAt = '2026-09-03 18:30:00+00'): User
{
    $user = User::query()->create([
        'tenant_id' => $tenantId,
        'email' => str()->uuid().'@account-id.test',
        'password_hash' => 'Non-login test fixture',
        'status' => UserStatus::Active,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return User::query()->where('tenant_id', $tenantId)->whereKey($user->id)->firstOrFail();
}

it('assigns twelve digit string IDs using the creation date in the company timezone', function (): void {
    $this->idTenant->update(['timezone' => 'Asia/Kuala_Lumpur']);
    $user = accountIdFixture($this->idTenant->id);
    expect($user->account_id)->toBeString()->toMatch('/^20260904[0-9]{4}$/')
        ->and($user->id)->toMatch('/^[a-f0-9-]{36}$/')
        ->and($user->created_at->format('Y-m-d'))->toBe('2026-09-03');
    $originalId = $user->account_id;
    $this->idTenant->update(['timezone' => 'America/Los_Angeles']);
    $user->update(['last_login_at' => now(), 'status' => UserStatus::Suspended]);
    expect(User::query()->where('tenant_id', $this->idTenant->id)->whereKey($user->id)->value('account_id'))->toBe($originalId);
});

it('does not reuse account IDs for users created on the same day', function (): void {
    $ids = [];
    foreach (range(1, 30) as $_) {
        $ids[] = accountIdFixture($this->idTenant->id)->account_id;
    }
    expect(array_unique($ids))->toHaveCount(30);
});

it('rejects caller supplied and modified IDs at the database boundary', function (): void {
    $user = accountIdFixture($this->idTenant->id);
    foreach (['202609034426', '123', null] as $replacement) {
        expect(fn () => DB::transaction(fn () => DB::table('users')
            ->where('tenant_id', $this->idTenant->id)->where('id', $user->id)
            ->update(['account_id' => $replacement])))->toThrow(QueryException::class);
    }
    expect(fn () => DB::transaction(fn () => User::query()->create([
        'tenant_id' => $this->idTenant->id, 'email' => 'injected@account-id.test',
        'password_hash' => 'Non-login fixture', 'status' => UserStatus::Active,
        'account_id' => '202609034426',
    ])))->toThrow(QueryException::class);
    expect($user->fresh()->account_id)->toBe($user->account_id);
});

it('shares the authenticated company account ID without changing UUID identity or host isolation', function (): void {
    $user = User::query()->where('tenant_id', $this->idTenant->id)->where('email', 'user@a.localhost')->firstOrFail();
    $this->actingAs($user, 'tenant_user');
    foreach (range(1, 2) as $_) {
        $this->get('http://a.localhost/account')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('user/Account')->where('auth.user.accountId', $user->account_id)
            ->where('auth.user.id', $user->id));
    }
    $this->get('http://b.localhost/account')->assertRedirect('/login');
});

it('assigns an ID during verified registration and rejects client chosen IDs', function (): void {
    $challenge = RegistrationChallenge::query()->create([
        'tenant_id' => $this->idTenant->id, 'channel' => RegistrationChannel::Email,
        'destination' => 'registered@account-id.test', 'code_hash' => str_repeat('a', 64),
        'status' => RegistrationChallengeStatus::Verified, 'verified_at' => now(),
        'expires_at' => now()->addMinutes(10),
    ]);
    $this->withSession(['registration.challenge_ids' => [$challenge->id]])
        ->postJson("http://a.localhost/register/challenges/{$challenge->id}/complete", [
            'password' => 'StrongPass1234', 'password_confirmation' => 'StrongPass1234',
            'account_id' => '202609034426', 'accountId' => '202609034426',
        ])->assertUnprocessable()->assertJsonValidationErrors(['account_id', 'accountId']);
    expect($challenge->fresh()->consumed_at)->toBeNull();
    $user = app(RegisterUserAction::class)->execute($this->idTenant, $challenge->id, 'StrongPass1234');
    expect($user->account_id)->toBeString()->toMatch('/^[0-9]{12}$/')
        ->and(substr($user->account_id, 0, 8))->toBe($user->created_at->setTimezone($this->idTenant->timezone)->format('Ymd'))
        ->and($challenge->fresh()->consumed_at)->not->toBeNull();
});

it('backfills legacy users without changing existing UUIDs data timestamps or related rows', function (): void {
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $migration = require database_path('migrations/2026_09_11_000200_add_public_account_ids_to_users.php');
    $migration->down();
    $this->idTenant->update(['timezone' => 'Asia/Kuala_Lumpur']);
    DB::table('users')->where('tenant_id', $this->idTenant->id)->update(['created_at' => '2026-09-03 18:30:00+00']);
    $before = DB::table('users')->orderBy('tenant_id')->orderBy('id')->get();
    $relations = [];
    foreach (['user_profiles', 'user_preferences', 'wallets', 'ledger_accounts', 'ledger_entries', 'ledger_postings', 'user_cards'] as $table) {
        $relations[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    $migration->up();
    foreach ($before as $original) {
        $current = DB::table('users')->where('tenant_id', $original->tenant_id)->where('id', $original->id)->first();
        $data = (array) $current;
        unset($data['account_id']);
        expect($data)->toBe((array) $original);
        $timezone = Tenant::query()->whereKey($original->tenant_id)->value('timezone');
        expect($current->account_id)->toMatch('/^[0-9]{12}$/')
            ->and(substr($current->account_id, 0, 8))->toBe(CarbonImmutable::parse($original->created_at)->setTimezone($timezone)->format('Ymd'));
    }
    foreach ($relations as $table => $snapshot) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($snapshot);
    }
});

it('preserves zero-padded suffixes and fails full capacity without consuming registration', function (): void {
    $this->travelTo(CarbonImmutable::parse('2099-09-03 12:00:00Z'));
    $this->idTenant->update(['timezone' => 'UTC']);
    // Test-only dense occupancy setup, never a production bypass or live fixture.
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('ALTER TABLE users DISABLE TRIGGER users_assign_account_id');
    DB::insert(<<<'SQL'
        INSERT INTO users (id, tenant_id, email, password_hash, status, created_at, updated_at, account_id)
        SELECT gen_random_uuid(), ?::uuid, 'capacity-' || n || '@account-id.test', 'Non-login fixture', 'ACTIVE',
            '2099-09-03 12:00:00+00'::timestamptz, '2099-09-03 12:00:00+00'::timestamptz,
            '20990903' || lpad(n::text, 4, '0')
        FROM generate_series(0, 9999) n WHERE n <> 42
        SQL, [$this->idTenant->id]);
    DB::statement('ALTER TABLE users ENABLE TRIGGER users_assign_account_id');
    $last = accountIdFixture($this->idTenant->id, '2099-09-03 12:00:00+00');
    expect($last->account_id)->toBe('209909030042');

    $challenge = RegistrationChallenge::query()->create([
        'tenant_id' => $this->idTenant->id, 'channel' => RegistrationChannel::Email,
        'destination' => 'capacity-full@account-id.test', 'code_hash' => str_repeat('b', 64),
        'status' => RegistrationChallengeStatus::Verified, 'verified_at' => now(),
        'expires_at' => now()->addMinutes(10),
    ]);
    try {
        app(RegisterUserAction::class)->execute($this->idTenant, $challenge->id, 'StrongPass1234');
        $this->fail('Expected an explicit capacity error.');
    } catch (DomainException $exception) {
        expect($exception->errorCode)->toBe('ACCOUNT_ID_CAPACITY_EXHAUSTED');
    }
    expect($challenge->fresh()->consumed_at)->toBeNull()
        ->and(User::query()->where('tenant_id', $this->idTenant->id)->where('email', $challenge->destination)->exists())->toBeFalse();
    $other = accountIdFixture($this->idOtherTenant->id, '2099-09-03 12:00:00+00');
    expect($other->account_id)->toMatch('/^20990903[0-9]{4}$/');
});
