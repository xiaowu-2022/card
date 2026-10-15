<?php

use App\Application\Partners\PartnerBalanceHierarchy;
use App\Application\Partners\PartnerHierarchy;
use App\Application\Partners\PartnerManagement;
use App\Application\Partners\PartnerReport;
use App\Application\Promotion\ChangeReferrer;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\Wallet\AdjustWalletBalance;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Services\WalletProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed();
    Http::preventStrayRequests();
    Queue::fake();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->owner = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->admin = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    app(PromotionMembershipAction::class)->ensure($this->tenant->id, $this->owner->id);
    $this->root = hierarchyPartner($this, $this->owner);
});
function hierarchyChild(User $parent): User
{
    $user = $parent->replicate(['account_id']);
    $user->forceFill(['email' => Str::uuid().'@example.test'])->save();
    $member = app(PromotionMembershipAction::class)->ensure($parent->tenant_id, $parent->id);
    app(PromotionMembershipAction::class)->ensure($parent->tenant_id, $user->id, $member->id);

    return $user->refresh();
}
function hierarchyPartner($test, User $user): object
{
    return app(PartnerManagement::class)->configure($test->admin, $user->tenant_id, ['account_id' => $user->account_id, 'enabled' => true, 'share_percent' => '40']);
}
function hierarchyRead($test, ?string $id = null, string $view = 'children', int $page = 1): array
{
    return app(PartnerHierarchy::class)->read($test->tenant->id, $test->owner->id, $id, $view, $page);
}

it('finds nearest enabled partners through ordinary members and counts all descendants', function () {
    $ordinary = hierarchyChild($this->owner);
    $a = hierarchyChild($ordinary);
    $pa = hierarchyPartner($this, $a);
    $b = hierarchyChild($a);
    $pb = hierarchyPartner($this, $b);
    hierarchyChild($b);
    $other = hierarchyChild($this->owner);
    $pc = hierarchyPartner($this, $other);
    $list = hierarchyRead($this);
    expect(array_column($list['items'], 'id'))->toEqualCanonicalizing([$pa->id, $pc->id])
        ->and(collect($list['items'])->keyBy('id')[$pa->id]['teamCount'])->toBe(2)
        ->and(collect($list['items'])->keyBy('id')[$pa->id]['name'])->toBe($a->account_id);
    expect(array_column(hierarchyRead($this, $pa->id)['items'], 'id'))->toBe([$pb->id]);
    DB::table('partner_configurations')->where('id', $pa->id)->update(['enabled' => false]);
    expect(array_column(hierarchyRead($this)['items'], 'id'))->toEqualCanonicalizing([$pb->id, $pc->id]);
    expect(fn () => hierarchyRead($this, $pa->id, 'report'))->toThrow(HttpException::class);
});

it('paginates deterministically and returns empty lower lists', function () {
    for ($i = 0; $i < 21; $i++) {
        hierarchyPartner($this, hierarchyChild($this->owner));
    }
    $first = hierarchyRead($this);
    $second = hierarchyRead($this, null, 'children', 2);
    expect($first['total'])->toBe(21)->and($first['items'])->toHaveCount(20)->and($first['hasMore'])->toBeTrue()
        ->and($second['items'])->toHaveCount(1)->and($second['hasMore'])->toBeFalse()
        ->and(hierarchyRead($this, $second['items'][0]['id'])['items'])->toBe([]);
});

it('denies unrelated and cross company partners and revokes moved descendants', function () {
    $child = hierarchyChild($this->owner);
    $partner = hierarchyPartner($this, $child);
    $outsider = $this->owner->replicate(['account_id']);
    $outsider->forceFill(['email' => 'outside@example.test'])->save();
    $outsidePartner = hierarchyPartner($this, $outsider->refresh());
    $other = User::where('tenant_id', Tenant::where('slug', 'tenant-b')->value('id'))->firstOrFail();
    $otherPartner = hierarchyPartner($this, $other);
    foreach ([$outsidePartner->id, $otherPartner->id] as $id) {
        expect(fn () => hierarchyRead($this, $id))->toThrow(HttpException::class);
    }
    $member = app(PromotionMembershipAction::class)->ensure($child->tenant_id, $child->id);
    $target = app(PromotionMembershipAction::class)->ensure($outsider->tenant_id, $outsider->id);
    app(ChangeReferrer::class)->execute($child->tenant_id, $child->id, $this->admin, ['old_inviter_id' => $member->inviter_id, 'new_inviter_id' => $target->id, 'revision' => $member->referrer_revision, 'reason' => 'Offline hierarchy test', 'request_id' => (string) Str::uuid()]);
    expect(fn () => hierarchyRead($this, $partner->id, 'report'))->toThrow(HttpException::class);
    DB::table('partner_configurations')->where('id', $this->root->id)->update(['enabled' => false]);
    expect(fn () => hierarchyRead($this))->toThrow(HttpException::class);
});

it('reuses complete stock totals while removing administrator identities', function () {
    $child = hierarchyChild($this->owner);
    $p = hierarchyPartner($this, $child);
    app(PartnerManagement::class)->journal($this->admin, $this->tenant->id, $p->id, ['kind' => 'ADVANCE', 'amount' => '10', 'business_date' => now()->format('Y-m-d'), 'note' => 'Shared cooperation note', 'request_id' => (string) Str::uuid()]);
    app(PartnerManagement::class)->journal($this->admin, $this->tenant->id, $p->id, ['kind' => 'REIMBURSEMENT', 'amount' => '12.34567891', 'business_date' => now()->format('Y-m-d'), 'note' => 'Offline stock precision fixture', 'request_id' => (string) Str::uuid()]);
    $expected = app(PartnerReport::class)->read($this->tenant->id, $child->id);
    $actual = hierarchyRead($this, $p->id, 'report')['report'];
    $row = collect(hierarchyRead($this)['items'])->firstWhere('id', $p->id);
    expect($row['email'])->toBe($child->email)
        ->and($expected['stockBeforeDifference'])->toBe('-12.34567891')
        ->and($row['stock'])->toBe('-10.00000000')->toBe($expected['stock']);
    foreach (['stock', 'totals', 'accountBalance', 'share', 'risks'] as $field) {
        expect($actual[$field])->toBe($expected[$field]);
    }
    expect(array_column($actual['journal']['items'], 'note'))->toContain('Shared cooperation note')
        ->and(data_get($actual, 'journal.items.0.actor_id'))->toBeNull();
    $details = app(PartnerHierarchy::class)->read($this->tenant->id, $this->owner->id, $p->id, 'report', 1, 'inflow', 2)['report'];
    expect($details['subject']['id'])->toBe($p->id)->and($details['flowDetails']['page'])->toBe(2);
});

it('requires platform permission and respects explicit company filters', function () {
    $p = hierarchyPartner($this, hierarchyChild($this->owner));
    $url = 'http://admin.localhost/platform/partners/'.$p->id.'/children';
    $this->actingAs($this->admin, 'platform_admin')->getJson($url)->assertOk()->assertJsonPath('subject.id', $p->id);
    $this->getJson($url.'?company='.Tenant::where('slug', 'tenant-b')->value('id'))->assertNotFound();
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'partners.manage')->value('id'))->delete();
    $this->getJson($url)->assertForbidden();
});

it('serves consumer pages and rechecks scope on detail pagination without writes', function () {
    $child = hierarchyChild($this->owner);
    $p = hierarchyPartner($this, $child);
    $before = [DB::table('wallets')->count(), DB::table('ledger_entries')->count(), DB::table('promotion_members')->count(), DB::table('partner_journal_entries')->count()];
    $url = 'http://a.localhost/api/v1/client/promotion/stock/partners';
    $this->actingAs($this->owner, 'tenant_user')->getJson($url)->assertOk()->assertJsonPath('component', 'user/PartnerChildren')->assertJsonPath('props.partners.items.0.id', $p->id)->assertJsonPath('props.partners.items.0.email', $child->email)->assertJsonPath('props.partners.items.0.stock', '0.00000000');
    $this->getJson($url.'/'.$p->id.'/report?flow=inflow&flow_page=2')->assertOk()->assertJsonPath('props.report.subject.id', $p->id)->assertJsonPath('props.report.flowDetails.page', 2);
    expect([DB::table('wallets')->count(), DB::table('ledger_entries')->count(), DB::table('promotion_members')->count(), DB::table('partner_journal_entries')->count()])->toBe($before);
    $this->actingAs($child, 'tenant_user')->getJson($url.'/'.$this->root->id.'/report')->assertNotFound();
    $this->actingAs($this->owner, 'tenant_user');
    DB::table('partner_configurations')->where('id', $p->id)->update(['enabled' => false]);
    $this->getJson($url.'/'.$p->id.'/report?flow=inflow&flow_page=3')->assertNotFound();
});

it('shows personal and complete enabled partner team balances by nearest recommendation level', function () {
    $ordinary = hierarchyChild($this->owner);
    $child = hierarchyChild($ordinary);
    $childPartner = hierarchyPartner($this, $child);
    $grandchild = hierarchyChild($child);
    $grandPartner = hierarchyPartner($this, $grandchild);
    foreach ([[$this->root, '10.00000001'], [$childPartner, '2.00000002'], [$grandPartner, '3.00000003']] as [$partner, $amount]) {
        app(PartnerManagement::class)->journal($this->admin, $this->tenant->id, $partner->id, ['kind' => 'ADVANCE', 'amount' => $amount, 'business_date' => now()->format('Y-m-d'), 'note' => 'Offline balance hierarchy fixture', 'request_id' => (string) Str::uuid()]);
    }
    DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $child, 'USDT'));
    app(AdjustWalletBalance::class)->execute($this->tenant->id, $child->id, $this->admin, ['asset' => 'USDT', 'direction' => 'INCREASE', 'amount' => '20', 'reason' => 'Offline hierarchy actual balance fixture', 'request_id' => (string) Str::uuid(), 'confirmed' => true]);
    $before = [DB::table('wallets')->count(), DB::table('ledger_entries')->count(), DB::table('promotion_members')->count(), DB::table('partner_journal_entries')->count()];
    $query = app(PartnerBalanceHierarchy::class);
    $root = $query->read($this->tenant->id, null, 1);
    expect(array_column($root['items'], 'id'))->toBe([$this->root->id])
        ->and($root['items'][0]['personal']['theoretical'])->toBe('10.00000001')
        ->and($root['items'][0]['team']['theoretical'])->toBe('15.00000006')
        ->and($root['items'][0]['team']['actual'])->toBe('20.00000000')
        ->and($root['items'][0]['team']['difference'])->toBe('-4.99999994')
        ->and($root['items'][0]['teamPartners'])->toBe(3);
    $level = $query->read($this->tenant->id, $this->root->id, 1);
    expect(array_column($level['items'], 'id'))->toBe([$childPartner->id])
        ->and($level['items'][0]['personal'])->toBe(array_intersect_key(app(PartnerReport::class)->read($this->tenant->id, $child->id)['accountBalance'], array_flip(['theoretical', 'actual', 'difference'])))
        ->and($level['items'][0]['team']['theoretical'])->toBe('5.00000005');
    $other = User::where('tenant_id', Tenant::where('slug', 'tenant-b')->value('id'))->firstOrFail();
    $outside = hierarchyPartner($this, $other);
    expect(fn () => $query->read($this->tenant->id, $outside->id, 1))->toThrow(HttpException::class);
    DB::table('partner_configurations')->where('id', $childPartner->id)->update(['enabled' => false]);
    expect(array_column($query->read($this->tenant->id, $this->root->id, 1)['items'], 'id'))->toBe([$grandPartner->id])
        ->and($query->read($this->tenant->id, null, 1)['items'][0]['team']['theoretical'])->toBe('13.00000004');
    expect(fn () => $query->read($this->tenant->id, $childPartner->id, 1))->toThrow(HttpException::class);
    $this->actingAs($this->admin, 'platform_admin')->get('http://admin.localhost/platform/partner-balances?company='.$this->tenant->id)->assertOk();
    expect([DB::table('wallets')->count(), DB::table('ledger_entries')->count(), DB::table('promotion_members')->count(), DB::table('partner_journal_entries')->count()])->toBe($before);
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'wallet.read')->value('id'))->delete();
    $this->get('http://admin.localhost/platform/partner-balances')->assertForbidden();
    $this->app['auth']->guard('platform_admin')->logout();
    $this->get('http://admin.localhost/platform/partner-balances')->assertRedirect();
});

it('counts exact hierarchy pages and sums teams beyond the visible page', function () {
    for ($i = 0; $i < 21; $i++) {
        hierarchyPartner($this, hierarchyChild($this->owner));
    }
    $query = app(PartnerBalanceHierarchy::class);
    $first = $query->read($this->tenant->id, $this->root->id, 1);
    $second = $query->read($this->tenant->id, $this->root->id, 2);
    expect($first['total'])->toBe(21)->and($first['pages'])->toBe(2)->and($first['items'])->toHaveCount(20)
        ->and($second['items'])->toHaveCount(1)
        ->and($query->read($this->tenant->id, null, 1)['items'][0]['teamPartners'])->toBe(22);
});
