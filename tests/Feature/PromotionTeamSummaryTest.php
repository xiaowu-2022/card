<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Promotion\PaidPromotionPurchase;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\Promotion\PromotionReportQuery;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\SecurityDeposit\RefundSecurityDepositAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Promotion\Models\PromotionMember;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed();
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false]);
    Queue::fake();
    Storage::fake('private');
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->tenant->businessSettings()->update(['required_security_deposit_amount' => '50']);
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->query = app(PromotionReportQuery::class);
    $this->member = app(PromotionMembershipAction::class)->ensure($this->tenant->id, $this->user->id);
});

function teamSummaryChild(User $parent): User
{
    $membership = app(PromotionMembershipAction::class);
    $member = $membership->ensure($parent->tenant_id, $parent->id);
    $child = $parent->replicate(['account_id']);
    $child->forceFill(['email' => Str::uuid().'@example.test'])->save();
    $child->refresh();
    $membership->ensure($parent->tenant_id, $child->id, $member->id);

    return $child;
}

function teamSummaryMember(User $user): string
{
    return PromotionMember::where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->value('id');
}

function teamSummaryFundWallet($test, User $user): void
{
    $ocr = Mockery::mock(KycOcrProviderInterface::class);
    $ocr->shouldReceive('name')->andReturn('TEST');
    $ocr->shouldReceive('extractIdentityDocument')->andReturn(new KycOcrResultDTO(KycOcrOutcome::Success, 'TEAM-'.$user->id));
    app()->instance(KycOcrProviderInterface::class, $ocr);
    $application = app(SubmitKycApplicationAction::class)->execute($test->tenant, $user, 'CN', 'TEAM-'.$user->id, kycTestImage(), kycTestImage());
    app(ApproveKycAction::class)->execute($test->tenant->id, $application->id, AdminUser::where('email', 'owner@a.localhost')->firstOrFail());
    $wallet = app(ActivateUserWalletAction::class)->execute($test->tenant->id, $user->id)->wallet;
    $available = LedgerAccount::where('wallet_id', $wallet->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $clearing = LedgerAccount::where('tenant_id', $test->tenant->id)->where('asset_code', 'USDT')->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
    app(LedgerWriter::class)->post(new LedgerPostingPlan($test->tenant->id, 'USDT', 'team-test:'.Str::uuid(), 'TEST_TOPUP', null, null, null, [
        new LedgerPostingInstruction($available->id, Money::of('500000', 'USDT')),
        new LedgerPostingInstruction($clearing->id, Money::of('-500000', 'USDT')),
    ]));
}

function teamSummaryBuy($test, User $user, int $rank): void
{
    $id = DB::table('paid_promotion_levels')->where('tenant_id', $test->tenant->id)->where('rank', $rank)->value('id');
    $action = app(PaidPromotionPurchase::class);
    $quote = $action->quote($test->tenant->id, $user->id, $id, (string) Str::uuid());
    $action->confirm($test->tenant->id, $user->id, $quote->id);
}

it('lists every descendant once with filtered totals stable pagination and relative relationships', function () {
    $child = teamSummaryChild($this->user);
    $grandchild = teamSummaryChild($child);
    $deep = teamSummaryChild($grandchild);
    for ($i = 0; $i < 20; $i++) {
        teamSummaryChild($this->user);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $default = $this->query->members($this->tenant->id, $this->user->id, []);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($default['total'])->toBe(21)->and($queries)->toBeLessThanOrEqual(11)->and($default['memberCounts'])->toBe(['direct' => 21, 'total' => 23]);
    expect($this->query->members($this->tenant->id, $this->user->id, ['scope' => 'all'])['total'])->toBe(21);
    $first = $this->query->members($this->tenant->id, $this->user->id, ['account_id' => substr($child->account_id, 0, 4)]);
    $second = $this->query->members($this->tenant->id, $this->user->id, ['account_id' => substr($child->account_id, 0, 4), 'page' => 2]);
    expect($first['total'])->toBe(23)->and($first['items'])->toHaveCount(20)->and($first['hasMore'])->toBeTrue();
    expect($second['items'])->toHaveCount(3)->and($second['hasMore'])->toBeFalse();
    $all = collect([...$first['items'], ...$second['items']]);
    expect($all->pluck('id')->unique())->toHaveCount(23);
    expect($all->firstWhere('accountId', $child->account_id)['relation'])->toBe('direct')
        ->and($all->firstWhere('accountId', $deep->account_id)['relation'])->toBe('indirect');
    $filtered = $this->query->members($this->tenant->id, $this->user->id, ['account_id' => $deep->account_id, 'funding' => 'unfunded', 'rank' => '0']);
    expect($filtered['total'])->toBe(1)->and($filtered['items'][0]['id'])->toBe(teamSummaryMember($deep));
    expect($this->query->members($this->tenant->id, $this->user->id, ['funding' => 'funded'])['total'])->toBe(0);
    $summary = $this->query->memberTeam($this->tenant->id, $this->user->id, teamSummaryMember($child));
    expect($summary['totalMembers'])->toBe(2)->and($summary['rows'][0])->toMatchArray(['rank' => 0, 'direct' => 1, 'indirect' => 1, 'annual' => '0.00000000', 'activation' => '0.00000000']);
    expect($this->query->memberTeam($this->tenant->id, $this->user->id, teamSummaryMember($deep))['totalMembers'])->toBe(0);
    Http::assertNothingSent();
});

it('authorizes summaries against the viewer subtree and tenant with no public caching', function () {
    $child = teamSummaryChild($this->user);
    $sibling = teamSummaryChild($this->user);
    $grandchild = teamSummaryChild($child);
    $otherTenant = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $other = User::where('tenant_id', $otherTenant->id)->firstOrFail();
    $otherMember = app(PromotionMembershipAction::class)->ensure($otherTenant->id, $other->id);
    $base = 'http://a.localhost/promotion/members/';
    $this->getJson($base.teamSummaryMember($grandchild).'/team-summary')->assertRedirect('/login');
    $this->actingAs($child, 'tenant_user')->getJson($base.teamSummaryMember($grandchild).'/team-summary')->assertOk()->assertJsonPath('totalMembers', 0)->assertHeader('Cache-Control', 'no-store, private');
    foreach ([teamSummaryMember($child), teamSummaryMember($sibling), $this->member->id, $otherMember->id, (string) Str::uuid(), 'invalid'] as $id) {
        $this->getJson($base.$id.'/team-summary')->assertNotFound();
    }
});

it('groups viewer-only posted commissions by historical source rank while counting current people', function () {
    teamSummaryFundWallet($this, $this->user);
    teamSummaryBuy($this, $this->user, 8);
    $child = teamSummaryChild($this->user);
    teamSummaryFundWallet($this, $child);
    teamSummaryBuy($this, $child, 1);
    $leaf = teamSummaryChild($child);
    teamSummaryFundWallet($this, $leaf);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $leaf->id, (string) Str::uuid(), '50');
    teamSummaryBuy($this, $leaf, 1);
    $outside = teamSummaryChild($this->user);
    teamSummaryFundWallet($this, $outside);
    teamSummaryBuy($this, $outside, 1);
    $before = DB::table('ledger_entries')->count();
    $summary = $this->query->memberTeam($this->tenant->id, $this->user->id, teamSummaryMember($child));
    $rows = collect($summary['rows'])->keyBy('rank');
    expect($summary['totalMembers'])->toBe(1)->and($rows[0]['direct'])->toBe(0)->and($rows[1]['direct'])->toBe(1);
    $activation = $this->query->income($this->tenant->id, $this->user->id)->where('source_user_id', $leaf->id)->where('kind', 'activation')->sum('amount');
    $annual = $this->query->income($this->tenant->id, $this->user->id)->where('source_user_id', $leaf->id)->where('kind', 'annual')->sum('amount');
    expect((float) $activation)->toBeGreaterThan(0)->and((float) $annual)->toBeGreaterThan(0);
    expect($rows[0]['activation'])->toBe(Money::of((string) $activation, 'USDT')->amount())
        ->and($rows[1]['annual'])->toBe(Money::of((string) $annual, 'USDT')->amount());
    expect($rows[0]['annual'])->toBe('0.00000000')->and($rows[1]['activation'])->toBe('0.00000000');
    $this->travel(367)->days();
    $expired = collect($this->query->memberTeam($this->tenant->id, $this->user->id, teamSummaryMember($child))['rows'])->keyBy('rank');
    expect($expired[0]['direct'])->toBe(1)->and($expired[1]['direct'])->toBe(0)
        ->and($expired[0]['activation'])->toBe($rows[0]['activation'])->and($expired[1]['annual'])->toBe($rows[1]['annual']);
    expect(DB::table('ledger_entries')->count())->toBe($before);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('drills through relative direct teams and builds a bounded breadcrumb without leaking identities', function () {
    $a = teamSummaryChild($this->user);
    $b = teamSummaryChild($a);
    $c = teamSummaryChild($b);
    $sibling = teamSummaryChild($this->user);
    $view = $this->query->members($this->tenant->id, $this->user->id, ['subject' => teamSummaryMember($a)]);
    expect($view['subject']['accountId'])->toBe($a->account_id)->and($view['total'])->toBe(1)
        ->and($view['items'][0]['accountId'])->toBe($b->account_id)->and($view['items'][0]['relation'])->toBe('direct');
    $all = $this->query->members($this->tenant->id, $this->user->id, ['subject' => teamSummaryMember($a), 'account_id' => substr($b->account_id, 0, 4)]);
    expect($all['total'])->toBe(2)->and(collect($all['items'])->firstWhere('accountId', $c->account_id)['relation'])->toBe('indirect');
    $deep = $this->query->members($this->tenant->id, $this->user->id, ['subject' => teamSummaryMember($c)]);
    expect($deep['items'])->toBe([])->and(array_column($deep['breadcrumbs'], 'accountId'))->toBe([$a->account_id, $b->account_id, $c->account_id]);
    expect(json_encode($deep))->not->toContain($c->email)->not->toContain($sibling->account_id);
    $filtered = $this->query->members($this->tenant->id, $this->user->id, ['subject' => teamSummaryMember($a), 'account_id' => $c->account_id]);
    expect($filtered['total'])->toBe(1)->and($filtered['memberCounts'])->toBe(['direct' => 1, 'total' => 2]);
    $filtered = $this->query->members($this->tenant->id, $this->user->id, ['subject' => teamSummaryMember($a), 'account_id' => '']);
    expect($filtered['total'])->toBe(1)->and($filtered['items'][0]['accountId'])->toBe($b->account_id);
    Http::assertNothingSent();
});

it('rejects forged viewing subjects and source members across all browsing endpoints', function () {
    $a = teamSummaryChild($this->user);
    $b = teamSummaryChild($a);
    $sibling = teamSummaryChild($this->user);
    $foreignUser = User::where('tenant_id', Tenant::where('slug', 'tenant-b')->value('id'))->firstOrFail();
    $foreign = app(PromotionMembershipAction::class)->ensure($foreignUser->tenant_id, $foreignUser->id)->id;
    $this->actingAs($a, 'tenant_user');
    foreach ([$foreign, $this->member->id, teamSummaryMember($a), teamSummaryMember($sibling), (string) Str::uuid(), 'invalid'] as $subject) {
        $this->getJson('http://a.localhost/promotion/direct?subject='.$subject)->assertNotFound();
        $this->getJson('http://a.localhost/promotion/commissions?subject='.$subject)->assertNotFound();
        $this->getJson('http://a.localhost/promotion/members/'.teamSummaryMember($b).'/team-summary?subject='.$subject)->assertNotFound();
    }
    $this->actingAs($this->user, 'tenant_user');
    $subject = teamSummaryMember($a);
    foreach ([teamSummaryMember($sibling), $foreign, $subject, 'invalid'] as $source) {
        $this->getJson("http://a.localhost/promotion/commissions?subject=$subject&source_member=$source")->assertNotFound();
        $this->getJson("http://a.localhost/promotion/members/$source/team-summary?subject=$subject")->assertNotFound();
    }
    $this->getJson('http://a.localhost/promotion/members/'.teamSummaryMember($b).'/team-summary?subject='.$subject)
        ->assertOk()->assertJsonPath('totalMembers', 0);
});

it('switches the beneficiary consistently for member income team totals and exact source history', function () {
    teamSummaryFundWallet($this, $this->user);
    teamSummaryBuy($this, $this->user, 8);
    $a = teamSummaryChild($this->user);
    teamSummaryFundWallet($this, $a);
    teamSummaryBuy($this, $a, 1);
    $b = teamSummaryChild($a);
    $c = teamSummaryChild($b);
    foreach ([$b, $c] as $payer) {
        teamSummaryFundWallet($this, $payer);
        app(FundSecurityDepositAction::class)->execute($this->tenant->id, $payer->id, (string) Str::uuid(), '50');
    }
    $before = DB::table('ledger_entries')->count();
    $subject = teamSummaryMember($a);
    $asA = $this->query->members($this->tenant->id, $this->user->id, ['subject' => $subject, 'account_id' => substr($b->account_id, 0, 4)]);
    $asRoot = $this->query->members($this->tenant->id, $this->user->id, ['account_id' => $b->account_id]);
    $bAmount = collect($asA['items'])->firstWhere('accountId', $b->account_id)['totals']['total'];
    expect(Money::of($bAmount, 'USDT')->amount())->not->toBe(Money::of(collect($asRoot['items'])->firstWhere('accountId', $b->account_id)['totals']['total'], 'USDT')->amount());
    $expected = $this->query->cumulative($this->tenant->id, $a->id);
    expect(Money::of($asA['subjectTotals']['total'], 'USDT')->amount())->toBe($expected);
    foreach ([null, $subject] as $viewingSubject) {
        $search = ['account_id' => substr($b->account_id, 0, 4), 'subject' => $viewingSubject];
        $items = $this->query->members($this->tenant->id, $this->user->id, $search)['items'];
        foreach (['commission_asc' => 1, 'commission_desc' => -1] as $sort => $direction) {
            $ordered = $this->query->members($this->tenant->id, $this->user->id, $search + ['sort' => $sort]);
            $expectedAmounts = array_column(array_column($items, 'totals'), 'total');
            usort($expectedAmounts, fn ($left, $right) => $direction * Money::of($left, 'USDT')->compare(Money::of($right, 'USDT')));
            expect(array_column(array_column($ordered['items'], 'totals'), 'total'))->toBe($expectedAmounts);
        }
    }
    $filtered = $this->query->members($this->tenant->id, $this->user->id, ['subject' => $subject, 'account_id' => '000000000000000000000000']);
    expect($filtered['total'])->toBe(0)->and($filtered['subjectTotals'])->toBe($asA['subjectTotals']);
    $history = $this->query->commissions($this->tenant->id, $this->user->id, ['subject' => $subject, 'source_member' => teamSummaryMember($b)]);
    expect(Money::of($history['totals']['total'], 'USDT')->amount())->toBe(Money::of($bAmount, 'USDT')->amount());
    expect(collect($history['items'])->pluck('sourceAccountId')->unique()->values()->all())->toBe([$b->account_id]);
    $summary = $this->query->memberTeam($this->tenant->id, $this->user->id, teamSummaryMember($b), $subject);
    $cIncome = $this->query->income($this->tenant->id, $a->id)->where('source_user_id', $c->id)->where('kind', 'activation')->sum('amount');
    expect($summary['totalMembers'])->toBe(1)->and($summary['rows'][0]['activation'])->toBe(Money::of((string) $cIncome, 'USDT')->amount());
    expect(DB::table('ledger_entries')->count())->toBe($before);
    Http::assertNothingSent();
});

it('serves subject-scoped pages without public caching and retains exact source filters', function () {
    $a = teamSummaryChild($this->user);
    $b = teamSummaryChild($a);
    $aId = teamSummaryMember($a);
    $bId = teamSummaryMember($b);
    $this->actingAs($this->user, 'tenant_user');
    $this->get("http://a.localhost/promotion/direct?subject=$aId&scope=all")
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('user/PromotionReport')->where('report.subject.accountId', $a->account_id)
            ->missing('report.filters.scope')->where('report.memberCounts', ['direct' => 1, 'total' => 1])->where('report.total', 1)
            ->where('report.items.0.accountId', $b->account_id)->where('report.items.0.relation', 'direct'));
    $this->get("http://a.localhost/promotion/commissions?subject=$aId&source_member=$bId")
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('user/PromotionCommissions')->where('history.subject.accountId', $a->account_id)
            ->where('history.filters.source_member', $bId)->where('history.items', []));

});

it('sorts scoped members by registration before pagination with stable zero income ties', function () {
    $children = [];
    for ($i = 0; $i < 22; $i++) {
        $child = teamSummaryChild($this->user);
        $child->forceFill(['created_at' => now()->subDays(30 - $i)])->save();
        $children[] = teamSummaryMember($child);
    }
    $ledgerCount = DB::table('ledger_entries')->count();
    foreach (['registered_asc' => $children, 'registered_desc' => array_reverse($children), 'commission_asc' => array_reverse($children), 'commission_desc' => array_reverse($children)] as $sort => $expected) {
        $first = $this->query->members($this->tenant->id, $this->user->id, ['sort' => $sort, 'funding' => 'unfunded']);
        $second = $this->query->members($this->tenant->id, $this->user->id, ['sort' => $sort, 'funding' => 'unfunded', 'page' => 2]);
        expect(array_column([...$first['items'], ...$second['items']], 'id'))->toBe($expected)
            ->and($first['total'])->toBe(22)->and($second['items'])->toHaveCount(2);
    }
    $this->actingAs($this->user, 'tenant_user');
    $this->get('http://a.localhost/promotion/direct?sort=commission_asc')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('report.filters.sort', 'commission_asc'));
    $this->getJson('http://a.localhost/promotion/direct?sort=invalid')->assertUnprocessable()->assertJsonValidationErrors('sort');
    expect(DB::table('ledger_entries')->count())->toBe($ledgerCount);
});

it('projects current membership qualification without rewriting activation or money', function () {
    $inactive = teamSummaryChild($this->user);
    $ordinary = teamSummaryChild($this->user);
    $agent = teamSummaryChild($this->user);
    foreach ([$ordinary, $agent] as $member) {
        teamSummaryFundWallet($this, $member);
        app(FundSecurityDepositAction::class)->execute($this->tenant->id, $member->id, (string) Str::uuid(), '50');
    }
    teamSummaryBuy($this, $agent, 1);
    $snapshot = fn () => [DB::table('ledger_entries')->count(), DB::table('account_activations')->count(), DB::table('ledger_accounts')->orderBy('id')->get(['id', 'balance'])->toJson()];
    $before = $snapshot();
    $rows = collect($this->query->members($this->tenant->id, $this->user->id, [])['items'])->keyBy('accountId');
    expect($rows[$inactive->account_id]['membershipStatus'])->toBe('inactive')
        ->and($rows[$ordinary->account_id]['membershipStatus'])->toBe('ordinary')
        ->and($rows[$agent->account_id]['membershipStatus'])->toBe('agent')
        ->and($rows[$agent->account_id]['rank'])->toBe(1)
        ->and($rows[$agent->account_id]['depositAmount'])->toBe('0.000000000000000000')
        ->and($snapshot())->toBe($before);
    // The same balance becomes insufficient after the company raises its requirement.
    $this->tenant->businessSettings()->update(['required_security_deposit_amount' => '100']);
    $row = $this->query->members($this->tenant->id, $this->user->id, ['account_id' => $ordinary->account_id])['items'][0];
    expect($row['membershipStatus'])->toBe('inactive')->and($snapshot())->toBe($before);
    $this->tenant->businessSettings()->update(['required_security_deposit_amount' => '50', 'security_deposit_refund_wait_days' => 0]);
    $refunds = app(RefundSecurityDepositAction::class);
    $refund = $refunds->request($this->tenant->id, $ordinary->id, (string) Str::uuid());
    $refunds->settle($this->tenant->id, $ordinary->id, $refund->id);
    $before = $snapshot();
    $row = $this->query->members($this->tenant->id, $this->user->id, ['account_id' => $ordinary->account_id])['items'][0];
    expect($row['membershipStatus'])->toBe('inactive')
        ->and(DB::table('account_activations')->where('user_id', $ordinary->id)->exists())->toBeTrue()
        ->and($snapshot())->toBe($before);
    $this->travel(367)->days();
    $row = $this->query->members($this->tenant->id, $this->user->id, ['account_id' => $agent->account_id])['items'][0];
    expect($row['membershipStatus'])->toBe('inactive')->and($row['rank'])->toBe(0)->and($snapshot())->toBe($before);
    // Reporting follows the existing zero-requirement rule rather than inventing a positive-balance gate.
    $this->tenant->businessSettings()->update(['required_security_deposit_amount' => '0']);
    expect($this->query->members($this->tenant->id, $this->user->id, ['account_id' => $inactive->account_id])['items'][0]['membershipStatus'])->toBe('ordinary');
    Http::assertNothingSent();
});

it('batches complete per-member team sizes independently of list filters and excludes the member', function () {
    $a = teamSummaryChild($this->user);
    $b = teamSummaryChild($a);
    $c = teamSummaryChild($b);
    $sibling = teamSummaryChild($this->user);
    teamSummaryChild($sibling);
    $foreign = User::where('tenant_id', Tenant::where('slug', 'tenant-b')->value('id'))->firstOrFail();
    teamSummaryChild($foreign);
    $before = DB::table('ledger_entries')->count();
    $rows = collect($this->query->members($this->tenant->id, $this->user->id, [])['items'])->keyBy('accountId');
    expect($rows[$a->account_id]['teamSize'])->toBe(2)->and($rows[$sibling->account_id]['teamSize'])->toBe(1);
    $filtered = $this->query->members($this->tenant->id, $this->user->id, ['account_id' => $a->account_id, 'rank' => '0', 'funding' => 'unfunded']);
    expect($filtered['total'])->toBe(1)->and($filtered['items'][0]['teamSize'])->toBe(2);
    $descendants = $this->query->members($this->tenant->id, $this->user->id, ['subject' => teamSummaryMember($a)]);
    expect($descendants['items'][0]['accountId'])->toBe($b->account_id)->and($descendants['items'][0]['teamSize'])->toBe(1);
    $leaf = $this->query->members($this->tenant->id, $this->user->id, ['account_id' => $c->account_id]);
    expect($leaf['items'][0]['teamSize'])->toBe(0);
    $summary = $this->query->memberTeam($this->tenant->id, $this->user->id, teamSummaryMember($a));
    expect($filtered['items'][0]['teamSize'])->toBe($summary['totalMembers']);
    expect(DB::table('ledger_entries')->count())->toBe($before);
    Http::assertNothingSent();
});
