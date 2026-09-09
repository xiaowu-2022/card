<?php

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserPreference;
use App\Domain\User\Models\UserProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(fn () => $this->seed());

function phaseTwoUser(string $tenantId, ?string $email, ?string $phone): User
{
    return User::query()->create(['tenant_id' => $tenantId, 'email' => $email, 'phone' => $phone, 'password_hash' => Hash::make('StrongPass1234'), 'status' => UserStatus::Active]);
}

it('allows the same email and phone in different tenants', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    phaseTwoUser($tenantA->id, 'same@example.test', null);
    phaseTwoUser($tenantB->id, 'same@example.test', null);
    phaseTwoUser($tenantA->id, null, '+60111111111');
    phaseTwoUser($tenantB->id, null, '+60111111111');
    expect(User::query()->where('email', 'same@example.test')->count())->toBe(2)
        ->and(User::query()->where('phone', '+60111111111')->count())->toBe(2);
});

it('rejects duplicate email in the same tenant', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    phaseTwoUser($tenant->id, 'duplicate@example.test', null);
    phaseTwoUser($tenant->id, 'duplicate@example.test', null);
})->throws(QueryException::class);

it('rejects duplicate phone in the same tenant', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    phaseTwoUser($tenant->id, null, '+60111111111');
    phaseTwoUser($tenant->id, null, '+60111111111');
})->throws(QueryException::class);

it('requires at least one contact', function (): void {
    phaseTwoUser(Tenant::query()->where('slug', 'tenant-a')->value('id'), null, null);
})->throws(QueryException::class);

it('rejects malformed stored phones', function (): void {
    phaseTwoUser(Tenant::query()->where('slug', 'tenant-a')->value('id'), null, '60123');
})->throws(QueryException::class);

it('rejects a profile linked across tenants', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $user = phaseTwoUser($tenantA->id, 'constraint@example.test', null);
    UserProfile::query()->create(['tenant_id' => $tenantB->id, 'user_id' => $user->id]);
})->throws(QueryException::class);

it('rejects a preference linked across tenants', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $user = phaseTwoUser($tenantA->id, 'preference-constraint@example.test', null);
    UserPreference::query()->create(['tenant_id' => $tenantB->id, 'user_id' => $user->id, 'locale' => 'en']);
})->throws(QueryException::class);

it('installs the end-user status contact normalization and challenge checks', function (): void {
    $names = DB::table('pg_constraint')->whereIn('conname', [
        'users_status_check', 'users_contact_required_check', 'users_email_normalized_check', 'users_phone_format_check',
        'registration_challenges_channel_check', 'registration_challenges_status_check', 'registration_challenges_destination_check',
    ])->pluck('conname');
    expect($names)->toHaveCount(7);
});
