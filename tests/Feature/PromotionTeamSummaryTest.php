<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Promotion\PaidPromotionPurchase;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\Promotion\PromotionReportQuery;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
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

beforeEach(function () {
    $this->seed();
    Http::preventStrayRequests();
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
    $first = $this->query->members($this->tenant->id, $this->user->id, []);
    $second = $this->query->members($this->tenant->id, $this->user->id, ['page' => 2]);
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
