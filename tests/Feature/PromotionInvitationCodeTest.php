<?php

use App\Application\Promotion\PromotionMembershipAction;
use App\Domain\Promotion\Models\CompanyInvitation;
use App\Domain\Promotion\Models\PromotionMember;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->seed();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->other = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->members = app(PromotionMembershipAction::class);
});

it('releases only an invalid browser invitation and accepts a replacement without changing members', function (): void {
    $member = $this->members->ensure($this->tenant->id, $this->user->id);
    $company = $this->members->companyInvitation($this->tenant->id);
    $key = 'promotion.invitation.'.$this->tenant->id;
    $this->get('http://a.localhost/register?invite='.$member->invitation_code)->assertOk();
    $this->user->update(['status' => 'SUSPENDED']);
    $this->get('http://a.localhost/register')->assertOk()->assertSessionMissing($key)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('registration.invitationLocked', false)->where('registration.invitationInvalid', true));
    $this->withSession([$key => $member->invitation_code])
        ->get('http://a.localhost/register?invite='.$company->invitation_code)->assertOk()
        ->assertSessionHas($key, $company->invitation_code)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('registration.invitationLocked', true)->where('registration.invitationInvalid', false));
    expect($member->fresh()->inviter_id)->toBeNull()->and($member->fresh()->invitation_code)->toBe($member->invitation_code);
});

it('renders a recoverable registration form for an invalid or cross-company invite link', function (): void {
    $otherCode = $this->members->companyInvitation($this->other->id)->invitation_code;
    foreach (['invalid', $otherCode] as $code) {
        $this->get('http://a.localhost/register?invite='.$code)->assertOk()
            ->assertSessionMissing('promotion.invitation.'.$this->tenant->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('registration.invitationCode', '')->where('registration.invitationInvalid', true));
    }
});

it('allocates consecutive global codes to companies and people without consuming codes on reads', function (): void {
    $company = $this->members->companyInvitation($this->tenant->id);
    $member = $this->members->ensure($this->tenant->id, $this->user->id);
    $other = $this->members->companyInvitation($this->other->id);
    expect([$company->invitation_code, $member->invitation_code, $other->invitation_code])->toBe(['523612', '523613', '523614']);
    $this->members->companyInvitation($this->tenant->id);
    $this->members->ensure($this->tenant->id, $this->user->id);
    expect(DB::table('promotion_invitation_counter')->value('next_value'))->toBe(523615);
    expect(fn () => $this->members->enrollment($this->other->id, $company->invitation_code))->toThrow(DomainException::class);
});

it('rolls back allocation with its outer business transaction', function (): void {
    try {
        DB::transaction(function (): void {
            $this->members->companyInvitation($this->tenant->id);
            throw new RuntimeException('Test rollback');
        });
    } catch (RuntimeException) {
    }
    expect($this->members->companyInvitation($this->tenant->id)->invitation_code)->toBe('523612');
});

it('forbids supplied codes and counter rewinds and immutable code edits', function (): void {
    $member = $this->members->ensure($this->tenant->id, $this->user->id);
    expect(fn () => DB::transaction(fn () => CompanyInvitation::query()->create(['tenant_id' => $this->tenant->id, 'invitation_code' => '523613'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => $member->update(['invitation_code' => '523614'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('promotion_invitation_counter')->update(['next_value' => 523612])))->toThrow(QueryException::class);
    expect(DB::table('promotion_invitation_counter')->value('next_value'))->toBe(523613);
});

it('issues the last six-digit code then fails closed without wrapping', function (): void {
    // Isolated test-only exhaustion fixture, not an application reset mechanism.
    DB::statement('ALTER TABLE promotion_invitation_counter DISABLE TRIGGER protect_promotion_invitation_counter');
    DB::table('promotion_invitation_counter')->update(['next_value' => 999999]);
    DB::statement('ALTER TABLE promotion_invitation_counter ENABLE TRIGGER protect_promotion_invitation_counter');
    expect($this->members->companyInvitation($this->tenant->id)->invitation_code)->toBe('999999');
    expect(fn () => $this->members->ensure($this->tenant->id, $this->user->id))->toThrow(DomainException::class, 'Registration is temporarily unavailable.');
    expect(PromotionMember::query()->count())->toBe(0);
    expect(DB::table('promotion_invitation_counter')->value('next_value'))->toBe(1000000);
});

it('canonicalizes old tenant-scoped links and locked sessions without changing their inviter', function (): void {
    $company = $this->members->companyInvitation($this->tenant->id);
    $member = $this->members->ensure($this->tenant->id, $this->user->id);
    $legacy = str_repeat('A', 24);
    DB::table('promotion_invitation_aliases')->insert(['tenant_id' => $this->tenant->id, 'old_code' => $legacy, 'member_id' => $member->id]);
    expect($this->members->enrollment($this->tenant->id, strtolower($legacy))['memberId'])->toBe($member->id);
    expect(fn () => $this->members->enrollment($this->other->id, $legacy))->toThrow(DomainException::class);
    $this->get('http://a.localhost/register?invite='.$legacy)->assertOk()->assertSessionHas('promotion.invitation.'.$this->tenant->id, $member->invitation_code);
    $this->withSession(['promotion.invitation.'.$this->tenant->id => $legacy])
        ->get('http://a.localhost/register?invite='.$company->invitation_code)->assertOk()
        ->assertSessionHas('promotion.invitation.'.$this->tenant->id, $member->invitation_code);
    $this->postJson('http://a.localhost/register/challenges', ['channel' => 'EMAIL', 'destination' => 'different@example.test', 'invitation_code' => $company->invitation_code])->assertStatus(422);
});
