<?php

use App\Application\Partners\PartnerInvitationReport;
use App\Application\Partners\PartnerManagement;
use App\Application\Promotion\PaidPromotionQuery;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\Promotion\PromotionReportQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    Http::preventStrayRequests();
    Queue::fake();
    $this->company = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->admin = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->user = User::where('tenant_id', $this->company->id)->firstOrFail();
    $this->url = 'http://admin.localhost/platform/invitation-data';
    $this->actingAs($this->admin, 'platform_admin');
});

function invitationDirectoryUser(User $source): User
{
    $user = $source->replicate(['account_id']);
    $user->forceFill(['email' => Str::uuid().'@invitation-directory.test'])->save();

    return $user->refresh();
}

it('filters all users and enabled partners without losing disabled partners or crossing companies', function () {
    $disabled = invitationDirectoryUser($this->user);
    $ordinary = invitationDirectoryUser($this->user);
    foreach ([[$this->user, true], [$disabled, false]] as [$user, $enabled]) {
        app(PartnerManagement::class)->configure($this->admin, $user->tenant_id, ['account_id' => $user->account_id, 'enabled' => $enabled, 'share_percent' => '40']);
    }
    $base = $this->url.'?company='.$this->company->id;
    $this->get($base.'&category=all')->assertOk()->assertInertia(fn ($p) => $p->component('platform/InvitationData')->where('accounts.total', 3));
    $this->get($base.'&category=partners')->assertOk()->assertInertia(fn ($p) => $p->where('accounts.total', 1)->where('accounts.data.0.userId', $this->user->id));
    $this->get($base.'&category=users')->assertOk()->assertInertia(fn ($p) => $p->where('accounts.total', 2));
    foreach ([$disabled, $ordinary] as $user) {
        $this->get($base.'&category=users&search='.$user->account_id)->assertOk()->assertInertia(fn ($p) => $p->where('accounts.total', 1)->where('accounts.data.0.userId', $user->id));
    }
    $this->get($base.'&category=users&search='.$this->user->account_id)->assertOk()->assertInertia(fn ($p) => $p->where('accounts.total', 0));
    $other = User::where('tenant_id', '<>', $this->company->id)->firstOrFail();
    $this->get($base.'&search='.$other->account_id)->assertOk()->assertInertia(fn ($p) => $p->where('accounts.total', 0));
    $this->get($base.'&subject='.$other->id.'&subject_company='.$other->tenant_id)->assertNotFound();
    $this->get($base.'&category=users&subject='.$this->user->id.'&subject_company='.$this->company->id)->assertNotFound();
    $this->get($base.'&category=invalid')->assertSessionHasErrors('category');
});

it('reuses every consumer invitation summary field and detail projection with no provisioning or financial writes', function () {
    $member = app(PromotionMembershipAction::class)->ensure($this->company->id, $this->user->id);
    $direct = invitationDirectoryUser($this->user);
    $directMember = app(PromotionMembershipAction::class)->ensure($this->company->id, $direct->id, $member->id);
    $indirect = invitationDirectoryUser($this->user);
    app(PromotionMembershipAction::class)->ensure($this->company->id, $indirect->id, $directMember->id);
    $unprovisioned = invitationDirectoryUser($this->user);
    $counts = fn () => [DB::table('promotion_members')->count(), DB::table('wallets')->count(), DB::table('ledger_entries')->count(), DB::table('ledger_postings')->count(), DB::table('partner_journal_entries')->count()];
    $before = $counts();
    $expected = Arr::only(app(PaidPromotionQuery::class)->execute($this->company->id, $this->user->id), ['tables', 'totals', 'legacy', 'teamByLevel', 'registeredMembers', 'directPeople', 'indirectPeople']);
    $report = app(PartnerInvitationReport::class)->readUser($this->user->id, $this->company->id);
    expect($report['summary'])->toBe($expected)
        ->and($report['summary']['registeredMembers'])->toBe(['direct' => 1, 'indirect' => 1])
        ->and($report['commission'])->toBe(app(PromotionReportQuery::class)->cumulative($this->company->id, $this->user->id));
    $this->get($this->url.'?company='.$this->company->id.'&search='.$this->user->account_id.'&subject='.$this->user->id.'&subject_company='.$this->company->id)
        ->assertOk()->assertInertia(fn ($p) => $p->where('accounts.data.0.summary', $expected)->where('selected.id', $this->user->id));
    $endpoint = $this->url.'/'.$this->company->id.'/'.$this->user->id.'/report';
    $this->getJson($endpoint)->assertOk()->assertJsonPath('summary.registeredMembers.direct', 1);
    $this->getJson($endpoint.'?kind=ACTIVATION&rank=0&page=1')->assertOk()->assertJsonPath('details.total', 0)->assertJsonPath('details.lastPage', 1);
    $this->getJson($endpoint.'?kind=ANNUAL&rank=999')->assertUnprocessable();
    $this->getJson($endpoint.'?rank=1')->assertUnprocessable();
    $this->getJson($this->url.'/'.$this->company->id.'/'.$unprovisioned->id.'/report')->assertOk();
    $other = User::where('tenant_id', '<>', $this->company->id)->firstOrFail();
    $this->getJson($this->url.'/'.$this->company->id.'/'.$other->id.'/report')->assertNotFound();
    expect($counts())->toBe($before);
    Http::assertNothingSent();
});

it('preserves exact numbered pagination totals and enforces scoped platform permissions', function () {
    for ($i = 0; $i < 20; $i++) {
        invitationDirectoryUser($this->user);
    }
    $this->get($this->url.'?company='.$this->company->id.'&category=users&page=2')->assertOk()->assertInertia(fn ($p) => $p
        ->where('accounts.total', 21)->where('accounts.last_page', 2)->where('accounts.current_page', 2)->has('accounts.data', 1)
        ->where('accounts.prev_page_url', fn ($url) => str_contains($url, 'category=users') && str_contains($url, 'company='.$this->company->id)));
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'ledger.read')->value('id'))->delete();
    $this->get($this->url)->assertForbidden();
    $this->getJson($this->url.'/'.$this->company->id.'/'.$this->user->id.'/report')->assertForbidden();
    $this->app['auth']->guard('platform_admin')->logout();
    $this->get($this->url)->assertRedirect();
});
