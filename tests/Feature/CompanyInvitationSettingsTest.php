<?php

use App\Application\Promotion\CompanyInvitationSettings;
use App\Application\Promotion\CompleteInvitedRegistrationAction;
use App\Application\Promotion\PromotionMembershipAction;
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
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    config(['inertia.ssr.enabled' => false]);
    $this->tenant = Tenant::where('slug', 'tenant-a')->sole();
    $this->other = Tenant::where('slug', 'tenant-b')->sole();
    $this->actor = AdminUser::where('email', 'owner@platform.local')->sole();
    $this->members = app(PromotionMembershipAction::class);
    $this->invitation = $this->members->companyInvitation($this->tenant->id);
    $this->settings = app(CompanyInvitationSettings::class);
    $this->data = ['old_code' => $this->invitation->invitation_code, 'new_code' => (string) ((int) DB::table('promotion_invitation_counter')->value('next_value') + 2),
        'revision' => 0, 'enabled' => true, 'reason' => 'Isolated company invitation change', 'confirmed' => true, 'request_id' => (string) Str::uuid()];
    $this->base = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/configuration';
});

it('changes effective company codes with permanent reservations and immutable audit without altering originals or money', function () {
    $tables = ['users', 'promotion_members', 'wallets', 'ledger_entries', 'ledger_postings'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()]);
    $legacy = str_repeat('B', 24);
    DB::table('promotion_invitation_aliases')->insert(['tenant_id' => $this->tenant->id, 'old_code' => $legacy, 'company_invitation_id' => $this->invitation->id]);
    expect($this->members->enrollment($this->tenant->id, $legacy)['companyId'])->toBe($this->invitation->id);
    $row = $this->settings->change($this->tenant->id, $this->actor, $this->data);
    expect($this->settings->change($this->tenant->id, $this->actor, $this->data)->id)->toBe($row->id);
    expect($this->invitation->fresh()->invitation_code)->toBe($this->data['old_code'])
        ->and($this->settings->current($this->invitation)['code'])->toBe($this->data['new_code']);
    foreach ([$this->data['old_code'], $legacy] as $code) {
        expect(fn () => $this->members->enrollment($this->tenant->id, $code))->toThrow(DomainException::class);
    }
    expect($this->members->enrollment($this->tenant->id, $this->data['new_code'])['companyId'])->toBe($this->invitation->id);
    expect(fn () => $this->members->enrollment($this->other->id, $this->data['new_code']))->toThrow(DomainException::class);
    foreach ($tables as $table) {
        expect(DB::table($table)->get()->toJson())->toBe($before[$table]);
    }
    expect(DB::table('promotion_invitation_reservations')->where('company_invitation_id', $this->invitation->id)->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('action', 'COMPANY_INVITATION_CHANGED')->value('actor_id'))->toBe($this->actor->id);
    expect(fn () => DB::transaction(fn () => DB::table('company_invitation_changes')->delete()))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('company_invitation_changes')->update(['reason' => 'rewrite'])))->toThrow(QueryException::class);
});

it('disables and re-enables only company enrollment and recovers stale browser invitations', function () {
    $user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $member = $this->members->ensure($this->tenant->id, $user->id);
    $data = array_replace($this->data, ['new_code' => $this->invitation->invitation_code, 'enabled' => false]);
    $this->settings->change($this->tenant->id, $this->actor, $data);
    expect(fn () => $this->members->enrollment($this->tenant->id, $data['new_code']))->toThrow(DomainException::class);
    expect($this->members->enrollment($this->tenant->id, $member->invitation_code)['memberId'])->toBe($member->id);
    $key = 'promotion.invitation.'.$this->tenant->id;
    $this->withSession([$key => $data['new_code']])->get('http://a.localhost/register')->assertOk()->assertSessionMissing($key);
    $count = DB::table('registration_challenges')->count();
    expect(fn () => app(CreateRegistrationChallengeAction::class)->execute($this->tenant, RegistrationChannel::Email, 'blocked@example.test',
        companyInvitationId: $this->invitation->id, invitationCode: $data['new_code']))->toThrow(DomainException::class);
    expect(DB::table('registration_challenges')->count())->toBe($count);
    $this->settings->change($this->tenant->id, $this->actor, array_replace($data, ['revision' => 1, 'enabled' => true, 'request_id' => (string) Str::uuid()]));
    expect($this->members->enrollment($this->tenant->id, $data['new_code'])['companyId'])->toBe($this->invitation->id);
});

it('preserves accepted verified registrations after company code disable', function () {
    $created = app(CreateRegistrationChallengeAction::class)->execute($this->tenant, RegistrationChannel::Email, 'accepted-company@example.test',
        companyInvitationId: $this->invitation->id, invitationCode: $this->invitation->invitation_code);
    app(VerifyRegistrationChallengeAction::class)->execute($this->tenant->id, $created->challenge->id, $created->rawCode);
    $this->settings->change($this->tenant->id, $this->actor, array_replace($this->data, ['new_code' => $this->invitation->invitation_code, 'enabled' => false]));
    $user = app(CompleteInvitedRegistrationAction::class)->execute($this->tenant, $created->challenge->id, 'secret123');
    expect(PromotionMember::where('user_id', $user->id)->sole()->inviter_id)->toBeNull();
});

it('requires authorization confirmation fresh revisions unused codes and idempotent payloads', function () {
    $url = $this->base.'/company-invitation';
    $this->post($url, $this->data)->assertRedirect();
    $this->actingAs($this->actor, 'platform_admin')->post($url, array_replace($this->data, ['confirmed' => false]))->assertSessionHasErrors('confirmed');
    $this->postJson($url, array_replace($this->data, ['new_code' => $this->members->companyInvitation($this->other->id)->invitation_code]))->assertStatus(409);
    $this->post($url, $this->data)->assertRedirect();
    $this->post($url, $this->data)->assertRedirect();
    $this->postJson($url, array_replace($this->data, ['enabled' => false]))->assertStatus(409);
    $this->postJson($url, array_replace($this->data, ['request_id' => (string) Str::uuid()]))->assertStatus(409);
    expect(DB::table('company_invitation_changes')->count())->toBe(1);
    $companyAdmin = AdminUser::where('email', 'owner@a.localhost')->first();
    if ($companyAdmin) {
        $this->actingAs($companyAdmin, 'tenant_admin')->post($url, $this->data)->assertRedirect();
    }
});

it('shows current settings and exact independent history pagination without provisioning on reads', function () {
    $this->settings->change($this->tenant->id, $this->actor, $this->data);
    $count = DB::table('promotion_invitation_reservations')->count();
    $this->actingAs($this->actor, 'platform_admin')->get($this->base.'/paid-promotion', ['X-Admin-Dialog' => '1'])->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('platform/PaidPromotion')
            ->where('companyInvitation.code', $this->data['new_code'])->where('companyInvitation.revision', 1)
            ->where('companyInvitation.enabled', true)->where('invitationHistory.total', 1));
    expect(DB::table('promotion_invitation_reservations')->count())->toBe($count);
    $this->get($this->base.'/paid-promotion?invitation_page=0')->assertSessionHasErrors('invitation_page');
});

it('skips new company reservations during automatic allocation without changing intermediate codes', function () {
    $next = (int) DB::table('promotion_invitation_counter')->value('next_value');
    $this->settings->change($this->tenant->id, $this->actor, array_replace($this->data, ['new_code' => (string) ($next + 1)]));
    $other = $this->members->companyInvitation($this->other->id);
    expect($other->invitation_code)->toBe((string) $next);
    $user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    expect($this->members->ensure($this->tenant->id, $user->id)->invitation_code)->toBe((string) ($next + 2));
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('rejects stale direct evidence inserts and inactive or company-only administrators', function () {
    $row = $this->settings->change($this->tenant->id, $this->actor, $this->data);
    $copy = (array) $row;
    $copy['id'] = (string) Str::uuid();
    $copy['request_id'] = (string) Str::uuid();
    $copy['revision'] = 3;
    expect(fn () => DB::transaction(fn () => DB::table('company_invitation_changes')->insert($copy)))->toThrow(QueryException::class);
    $companyAdmin = AdminUser::where('email', 'owner@a.localhost')->sole();
    $this->actingAs($companyAdmin, 'platform_admin')->postJson($this->base.'/company-invitation', $this->data)->assertForbidden();
    $this->actor->update(['status' => 'SUSPENDED']);
    expect(fn () => $this->settings->change($this->tenant->id, $this->actor, $this->data))->toThrow(HttpException::class);
    expect(DB::table('company_invitation_changes')->count())->toBe(1);
});

it('paginates company changes independently with exact scoped totals', function () {
    $code = $this->invitation->invitation_code;
    for ($revision = 0; $revision < 11; $revision++) {
        $this->settings->change($this->tenant->id, $this->actor, array_replace($this->data, [
            'new_code' => $code, 'revision' => $revision, 'enabled' => $revision % 2 !== 0, 'request_id' => (string) Str::uuid(),
        ]));
    }
    $headers = ['X-Admin-Dialog' => '1'];
    $this->actingAs($this->actor, 'platform_admin')->get($this->base.'/promotion?invitation_page=2', $headers)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationHistory.total', 11)
            ->where('invitationHistory.current_page', 2)->where('invitationHistory.last_page', 2)->has('invitationHistory.data', 1)
            ->where('companyInvitation.enabled', false)->where('companyInvitation.revision', 11));
    $this->get('http://admin.localhost/platform/tenants/'.$this->other->id.'/configuration/promotion', $headers)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationHistory.total', 0)->where('companyInvitation', null));
});
