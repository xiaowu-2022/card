<?php

require_once __DIR__.'/../Support/PromotionAdjustmentRace.php';

use App\Application\Promotion\ChangeInvitationCode;
use App\Application\Promotion\CompleteInvitedRegistrationAction;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\User\CreatePlatformUserAction;
use App\Application\User\CreateRegistrationChallengeAction;
use App\Application\User\VerifyRegistrationChallengeAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Promotion\Models\PromotionMember;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    config(['inertia.ssr.enabled' => false]);
    $this->tenant = Tenant::where('slug', 'tenant-a')->sole();
    $this->other = Tenant::where('slug', 'tenant-b')->sole();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->actor = AdminUser::where('email', 'owner@platform.local')->sole();
    $this->members = app(PromotionMembershipAction::class);
    $this->member = $this->members->ensure($this->tenant->id, $this->user->id);
    $this->next = (int) DB::table('promotion_invitation_counter')->value('next_value');
    $this->data = ['new_code' => (string) ($this->next + 2), 'old_code' => $this->member->invitation_code,
        'revision' => 0, 'reason' => 'Isolated invitation test', 'request_id' => (string) Str::uuid(), 'confirmed' => true];
    $this->url = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/users/'.$this->user->id.'/invitation-code';
});

function changeTestInvitation($test, array $overrides = []): object
{
    return app(ChangeInvitationCode::class)->execute($test->tenant->id, $test->user->id, $test->actor, array_replace($test->data, $overrides));
}

it('changes only the code with immutable evidence and retires old and legacy invitations', function () {
    $legacy = str_repeat('A', 24);
    DB::table('promotion_invitation_aliases')->insert(['tenant_id' => $this->tenant->id, 'old_code' => $legacy, 'member_id' => $this->member->id]);
    $tables = ['users', 'wallets', 'ledger_entries', 'ledger_postings', 'account_activations', 'account_activation_relations', 'paid_promotion_shares'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()]);
    $row = changeTestInvitation($this);
    expect(changeTestInvitation($this)->id)->toBe($row->id);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    expect($this->member->fresh()->invitation_code)->toBe($this->data['new_code'])
        ->and((int) $this->member->fresh()->invitation_revision)->toBe(1)
        ->and($this->member->fresh()->inviter_id)->toBe($this->member->inviter_id);
    foreach ($tables as $table) {
        expect(DB::table($table)->get()->toJson())->toBe($before[$table]);
    }
    foreach ([$legacy, $this->member->invitation_code] as $code) {
        expect(fn () => $this->members->enrollment($this->tenant->id, $code))->toThrow(DomainException::class);
    }
    expect($this->members->enrollment($this->tenant->id, $row->new_code)['memberId'])->toBe($this->member->id);
    expect(DB::table('promotion_invitation_reservations')->where('member_id', $this->member->id)->count())->toBe(2);
    expect(DB::table('audit_logs')->where('action', 'USER_INVITATION_CODE_CHANGED')->value('actor_id'))->toBe($this->actor->id);
    foreach (['promotion_invitation_changes', 'promotion_invitation_reservations'] as $table) {
        expect(fn () => DB::transaction(fn () => DB::table($table)->delete()))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table($table)->update(['created_at' => now()])))->toThrow(QueryException::class);
    }
});

it('skips reserved codes without skipping intervening numbers for companies and Platform users', function () {
    changeTestInvitation($this, ['new_code' => (string) ($this->next + 1)]);
    expect(DB::table('promotion_invitation_counter')->value('next_value'))->toBe($this->next);
    expect($this->members->companyInvitation($this->tenant->id)->invitation_code)->toBe((string) $this->next);
    $created = app(CreatePlatformUserAction::class)->execute($this->tenant->id, $this->actor, [
        'email' => 'code-skip@example.test', 'display_name' => 'Code test', 'password' => 'secret123',
        'password_confirmation' => 'secret123', 'request_id' => (string) Str::uuid(),
    ]);
    expect(PromotionMember::where('user_id', $created->id)->value('invitation_code'))->toBe((string) ($this->next + 2));
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('keeps existing verified challenges and lets new registrations use the changed code', function () {
    $created = app(CreateRegistrationChallengeAction::class)->execute($this->tenant, RegistrationChannel::Email, 'before@example.test',
        promotionInviterId: $this->member->id, invitationCode: $this->member->invitation_code);
    app(VerifyRegistrationChallengeAction::class)->execute($this->tenant->id, $created->challenge->id, $created->rawCode);
    changeTestInvitation($this);
    $user = app(CompleteInvitedRegistrationAction::class)->execute($this->tenant, $created->challenge->id, 'secret123');
    expect(PromotionMember::where('user_id', $user->id)->value('inviter_id'))->toBe($this->member->id);
    $count = DB::table('registration_challenges')->count();
    expect(fn () => app(CreateRegistrationChallengeAction::class)->execute($this->tenant, RegistrationChannel::Email, 'stale@example.test',
        promotionInviterId: $this->member->id, invitationCode: $this->member->invitation_code))->toThrow(DomainException::class);
    expect(DB::table('registration_challenges')->count())->toBe($count);
    $next = app(CreateRegistrationChallengeAction::class)->execute($this->tenant, RegistrationChannel::Email, 'after@example.test',
        promotionInviterId: $this->member->id, invitationCode: $this->data['new_code']);
    app(VerifyRegistrationChallengeAction::class)->execute($this->tenant->id, $next->challenge->id, $next->rawCode);
    $newUser = app(CompleteInvitedRegistrationAction::class)->execute($this->tenant, $next->challenge->id, 'secret123');
    expect(PromotionMember::where('user_id', $newUser->id)->value('inviter_id'))->toBe($this->member->id)
        ->and(PromotionMember::where('user_id', $newUser->id)->value('invitation_code'))->not->toBe($this->data['new_code']);
});

it('returns read-only scoped dialog data and recovers an invalid saved browser selection', function () {
    changeTestInvitation($this);
    $tables = ['promotion_members', 'promotion_invitation_reservations', 'promotion_invitation_counter', 'promotion_invitation_changes', 'audit_logs'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()]);
    $this->actingAs($this->actor, 'platform_admin')->get($this->url, ['X-Admin-Dialog' => '1'])->assertOk()
        ->assertInertia(fn ($p) => $p->component('platform/UserInvitationCode')->where('currentCode', $this->data['new_code'])->where('history.total', 1));
    foreach ($tables as $table) {
        expect(DB::table($table)->get()->toJson())->toBe($before[$table]);
    }
    $this->get(str_replace($this->tenant->id, $this->other->id, $this->url), ['X-Admin-Dialog' => '1'])->assertNotFound();
    $this->get($this->url)->assertRedirect();
    $key = 'promotion.invitation.'.$this->tenant->id;
    $this->withSession([$key => $this->member->invitation_code])->get('http://a.localhost/register')->assertOk()->assertSessionMissing($key);
    $this->withSession([$key => $this->member->invitation_code])->post('http://a.localhost/register/challenges', [
        'channel' => 'EMAIL', 'destination' => 'browser-new@example.test', 'invitation_code' => $this->data['new_code'],
    ])->assertRedirect()->assertSessionHasNoErrors();
});

it('rejects invalid inputs occupied codes stale edits and conflicting retries', function () {
    $this->actingAs($this->actor, 'platform_admin');
    foreach (['12345', '1000000', 'abcdef', '５２３６１５'] as $code) {
        $this->postJson($this->url, [...$this->data, 'new_code' => $code])->assertUnprocessable();
    }
    foreach (['000000', $this->member->invitation_code] as $code) {
        $this->postJson($this->url, [...$this->data, 'new_code' => $code])->assertConflict();
    }
    $this->postJson($this->url, [...$this->data, 'revision' => 1])->assertConflict();
    $this->postJson($this->url, [...$this->data, 'confirmed' => false])->assertUnprocessable();
    $this->postJson($this->url, [...$this->data, 'reason' => '   '])->assertUnprocessable();
    changeTestInvitation($this);
    $this->postJson($this->url, [...$this->data, 'reason' => 'Different'])->assertConflict();
    $this->postJson($this->url, [...$this->data, 'request_id' => (string) Str::uuid()])->assertConflict();
    $this->postJson($this->url, [...$this->data, 'old_code' => $this->data['new_code'], 'revision' => 1, 'new_code' => $this->member->invitation_code, 'request_id' => (string) Str::uuid()])->assertConflict();
    expect(DB::table('promotion_invitation_changes')->count())->toBe(1);
});

it('requires both permissions active accounts and a membership without provisioning', function () {
    $this->actingAs($this->actor, 'platform_admin');
    $this->user->update(['status' => 'SUSPENDED']);
    $this->postJson($this->url, $this->data)->assertUnprocessable();
    $this->user->update(['status' => 'ACTIVE']);
    $this->tenant->update(['status' => 'SUSPENDED']);
    $this->postJson($this->url, $this->data)->assertUnprocessable();
    $this->tenant->update(['status' => 'ACTIVE']);
    $otherUser = User::where('tenant_id', $this->other->id)->firstOrFail();
    $missing = str_replace([$this->tenant->id, $this->user->id], [$this->other->id, $otherUser->id], $this->url);
    $this->get($missing, ['X-Admin-Dialog' => '1'])->assertNotFound();
    $this->postJson($missing, $this->data)->assertNotFound();
    expect(PromotionMember::where('user_id', $otherUser->id)->exists())->toBeFalse();
    foreach (['users.invitation.manage', 'users.read'] as $permission) {
        DB::beginTransaction();
        DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', $permission)->value('id'))->delete();
        $this->get($this->url, ['X-Admin-Dialog' => '1'])->assertForbidden();
        $this->postJson($this->url, $this->data)->assertForbidden();
        DB::rollBack();
    }
});

it('rejects direct code and revision edits and rolls back all evidence on failure', function () {
    expect(fn () => DB::transaction(fn () => $this->member->update(['invitation_code' => $this->data['new_code']])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => $this->member->fresh()->update(['invitation_revision' => 1])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(function () {
        changeTestInvitation($this);
        throw new RuntimeException('Rollback fixture');
    }))->toThrow(RuntimeException::class);
    expect(DB::table('promotion_invitation_changes')->count())->toBe(0)
        ->and(DB::table('promotion_invitation_reservations')->where('code', $this->data['new_code'])->exists())->toBeFalse()
        ->and(DB::table('promotion_invitation_counter')->value('next_value'))->toBe($this->next);
});

it('fails closed when the final code is reserved', function () {
    changeTestInvitation($this, ['new_code' => '999999']);
    DB::statement('ALTER TABLE promotion_invitation_counter DISABLE TRIGGER protect_promotion_invitation_counter');
    DB::table('promotion_invitation_counter')->update(['next_value' => 999999]);
    DB::statement('ALTER TABLE promotion_invitation_counter ENABLE TRIGGER protect_promotion_invitation_counter');
    expect(fn () => $this->members->companyInvitation($this->tenant->id))->toThrow(DomainException::class);
    expect(DB::table('promotion_invitation_counter')->value('next_value'))->toBe(999999);
});

it('serializes competing changes across companies for one globally reserved code', function () {
    $otherUser = User::where('tenant_id', $this->other->id)->firstOrFail();
    $otherMember = $this->members->ensure($this->other->id, $otherUser->id);
    $tenant = $this->tenant->id;
    $user = $this->user->id;
    $other = $this->other->id;
    $actor = $this->actor->id;
    $first = $this->data;
    $second = [...$first, 'old_code' => $otherMember->invitation_code, 'request_id' => (string) Str::uuid()];
    expect(racePromotionAdjustments([
        fn () => app(ChangeInvitationCode::class)->execute($tenant, $user, AdminUser::findOrFail($actor), $first),
        fn () => app(ChangeInvitationCode::class)->execute($other, $otherUser->id, AdminUser::findOrFail($actor), $second),
    ]))->toEqualCanonicalizing(['completed', 'rejected']);
    expect(DB::table('promotion_invitation_changes')->count())->toBe(1);
});

it('serializes a code change with challenge acceptance using the same company lock', function () {
    $tenant = $this->tenant->id;
    $user = $this->user->id;
    $member = $this->member->id;
    $actor = $this->actor->id;
    $data = $this->data;
    $results = racePromotionAdjustments([
        fn () => app(ChangeInvitationCode::class)->execute($tenant, $user, AdminUser::findOrFail($actor), $data),
        fn () => app(CreateRegistrationChallengeAction::class)->execute(Tenant::findOrFail($tenant), RegistrationChannel::Email, 'race@example.test', promotionInviterId: $member, invitationCode: $data['old_code']),
    ]);
    expect($results[0])->toBe('completed')->and($results[1])->toBeIn(['completed', 'rejected']);
    expect(DB::table('registration_challenges')->where('destination', 'race@example.test')->count())->toBe($results[1] === 'completed' ? 1 : 0);
    expect(fn () => app(CreateRegistrationChallengeAction::class)->execute(Tenant::findOrFail($tenant), RegistrationChannel::Email, 'later@example.test', promotionInviterId: $member, invitationCode: $data['old_code']))->toThrow(DomainException::class);
});

it('rejects a stale simultaneous edit of the same member', function () {
    $tenant = $this->tenant->id;
    $user = $this->user->id;
    $actor = $this->actor->id;
    $first = $this->data;
    $second = [...$first, 'new_code' => (string) ($this->next + 3), 'request_id' => (string) Str::uuid()];
    expect(racePromotionAdjustments([
        fn () => app(ChangeInvitationCode::class)->execute($tenant, $user, AdminUser::findOrFail($actor), $first),
        fn () => app(ChangeInvitationCode::class)->execute($tenant, $user, AdminUser::findOrFail($actor), $second),
    ]))->toEqualCanonicalizing(['completed', 'rejected']);
    expect(DB::table('promotion_invitation_changes')->count())->toBe(1);
});

it('serializes normal global allocation against manual reservation of the next code', function () {
    $tenant = $this->tenant->id;
    $other = $this->other->id;
    $user = $this->user->id;
    $actor = $this->actor->id;
    $data = [...$this->data, 'new_code' => (string) $this->next];
    $results = racePromotionAdjustments([
        fn () => app(ChangeInvitationCode::class)->execute($tenant, $user, AdminUser::findOrFail($actor), $data),
        fn () => app(PromotionMembershipAction::class)->companyInvitation($other),
    ]);
    expect($results[1])->toBe('completed')->and($results[0])->toBeIn(['completed', 'rejected']);
    $codes = DB::table('promotion_invitation_reservations')->pluck('code');
    expect($codes->unique()->count())->toBe($codes->count());
    $companyCode = app(PromotionMembershipAction::class)->companyInvitation($other)->invitation_code;
    expect($companyCode)->toBe((string) ($this->next + ($results[0] === 'completed' ? 1 : 0)));
});

it('keeps every retired reservation after repeated changes and replays the first request safely', function () {
    $first = changeTestInvitation($this);
    $second = changeTestInvitation($this, ['old_code' => $first->new_code, 'new_code' => (string) ($this->next + 4), 'revision' => 1, 'request_id' => (string) Str::uuid()]);
    expect(changeTestInvitation($this)->id)->toBe($first->id);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    expect($this->member->fresh()->invitation_code)->toBe($second->new_code)
        ->and(DB::table('promotion_invitation_reservations')->where('member_id', $this->member->id)->count())->toBe(3);
    expect(fn () => $this->members->enrollment($this->tenant->id, $first->new_code))->toThrow(DomainException::class);
});
