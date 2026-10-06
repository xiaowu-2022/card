<?php

use App\Application\Promotion\ManualPromotion;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\Promotion\PromotionReportQuery;
use App\Application\SecurityDeposit\RefundSecurityDepositAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed();
    Http::preventStrayRequests();
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00 UTC'));
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->tenant->businessSettings()->update(['security_deposit_refund_wait_days' => 0]);
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->query = app(PromotionReportQuery::class);
    $this->membership = app(PromotionMembershipAction::class);
    $this->membership->ensure($this->tenant->id, $this->user->id);
});

function filterMember(User $parent): User
{
    $membership = app(PromotionMembershipAction::class);
    $inviter = $membership->ensure($parent->tenant_id, $parent->id);
    $child = $parent->replicate(['account_id']);
    $child->forceFill(['email' => Str::uuid().'@filter.test'])->save();
    $membership->ensure($parent->tenant_id, $child->id, $inviter->id);

    return $child->refresh();
}

// Sealed read-model evidence in the isolated test database, without KYC/provider flows.
function filterPosting(User $user, string $event = 'SECURITY_DEPOSIT_FUND', string $type = 'USER_SECURITY_DEPOSIT'): string
{
    $wallet = app(ActivateUserWalletAction::class)->execute($user->tenant_id, $user->id)->wallet;
    $account = LedgerAccount::where('wallet_id', $wallet->id)->where('account_type', $type)->firstOrFail();
    $clearing = LedgerAccount::where('tenant_id', $user->tenant_id)->where('asset_code', 'USDT')->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();

    return app(LedgerWriter::class)->post(new LedgerPostingPlan($user->tenant_id, 'USDT', 'member-filter:'.Str::uuid(), $event, null, null, null, [
        new LedgerPostingInstruction($account->id, Money::of('50', 'USDT')),
        new LedgerPostingInstruction($clearing->id, Money::of('-50', 'USDT')),
    ]))->id;
}

it('filters current registered ordinary and agent identities through refunds and manual rank changes', function () {
    $registered = filterMember($this->user);
    $ordinary = filterMember($this->user);
    $agent = filterMember($this->user);
    filterPosting($registered, 'WALLET_TOPUP_CREDIT', 'USER_AVAILABLE');
    filterPosting($registered, 'ADMIN_ADJUSTMENT', 'USER_SECURITY_DEPOSIT');
    filterPosting($ordinary);
    filterPosting($ordinary); // Repeated funding is still one member.
    filterPosting($agent);
    $manual = app(ManualPromotion::class);
    $owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 1)->value('id');
    $grant = $manual->adjust($this->tenant->id, $agent->id, $owner, $level, 'Filter fixture', (string) Str::uuid(), null);
    $assert = function (string $rank, array $users, string $status): void {
        $rows = $this->query->members($this->tenant->id, $this->user->id, ['rank' => $rank]);
        expect(array_column($rows['items'], 'accountId'))->toEqualCanonicalizing(array_map(fn ($user) => $user->account_id, $users))
            ->and($rows['total'])->toBe(count($users));
        foreach ($rows['items'] as $row) {
            expect($row['membershipStatus'])->toBe($status);
        }
    };
    $assert('registered', [$registered], 'inactive');
    $assert('0', [$ordinary], 'ordinary');
    $assert('1', [$agent], 'agent');
    $refunds = app(RefundSecurityDepositAction::class);
    $refund = $refunds->request($this->tenant->id, $ordinary->id, (string) Str::uuid());
    $assert('0', [$ordinary], 'ordinary');
    $refunds->settle($this->tenant->id, $ordinary->id, $refund->id);
    $assert('0', [$ordinary], 'ordinary');
    $this->tenant->businessSettings()->update(['required_security_deposit_amount' => '0']);
    $assert('registered', [$registered], 'inactive');
    // Same timestamp as funding: agent grant invalidates that deposit even after downgrade.
    $manual->adjust($this->tenant->id, $agent->id, $owner, 'ordinary', 'Filter downgrade', (string) Str::uuid(), $grant->id);
    $assert('registered', [$registered, $agent], 'inactive');
    $assert('0', [$ordinary], 'ordinary');
    $this->travel(1)->minutes();
    filterPosting($agent);
    $assert('0', [$ordinary, $agent], 'ordinary');
    $assert('registered', [$registered], 'inactive');
    $before = collect(['ledger_entries', 'wallets', 'promotion_members', 'manual_promotion_adjustments'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
    foreach (['funded', 'unfunded', 'invalid'] as $oldFilter) {
        $rows = $this->query->members($this->tenant->id, $this->user->id, ['funding' => $oldFilter]);
        expect($rows['total'])->toBe(3)->and($rows['filters'])->not->toHaveKey('funding');
    }
    foreach ($before as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
    Http::assertNothingSent();
});

it('requires new deposit funding after a paid agent cycle expires', function () {
    $child = filterMember($this->user);
    $entry = filterPosting($child);
    $this->travel(1)->minutes();
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 1)->first();
    $cycle = (string) Str::uuid();
    $terms = ['tenant_id' => $this->tenant->id, 'user_id' => $child->id, 'level_id' => $level->id,
        'revision' => $level->revision, 'rank' => 1, 'tariff' => $level->fee, 'percent' => $level->percent,
        'reward' => $level->reward, 'target' => $level->target, 'created_at' => now()];
    DB::table('paid_promotion_cycles')->insert($terms + ['id' => $cycle, 'starts_at' => now(), 'ends_at' => now()->addDay()]);
    DB::table('paid_promotion_orders')->insert($terms + ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
        'cycle_id' => $cycle, 'status' => 'COMPLETED', 'completed_at' => now(), 'expires_at' => now()->addMinutes(5),
        'ledger_entry_id' => $entry, 'amount' => $level->fee, 'previous_tariff' => '0', 'deposit_applied' => '0',
        'settlement_total' => $level->fee, 'deposit_snapshot' => '50']);
    $count = fn ($rank) => $this->query->members($this->tenant->id, $this->user->id, ['rank' => $rank])['total'];
    expect($count('1'))->toBe(1)->and($count('0'))->toBe(0)->and($count('registered'))->toBe(0);
    $this->travel(2)->days();
    expect($count('1'))->toBe(0)->and($count('0'))->toBe(0)->and($count('registered'))->toBe(1);
    filterPosting($child);
    expect($count('0'))->toBe(1)->and($count('registered'))->toBe(0);
});

it('keeps registered filters scoped paginated and compatible with old funding links on web and native', function () {
    $branch = filterMember($this->user);
    $leaf = filterMember($branch);
    $sibling = filterMember($this->user);
    filterPosting($sibling);
    $member = $this->membership->ensure($branch->tenant_id, $branch->id);
    $rows = $this->query->members($this->tenant->id, $this->user->id, ['subject' => $member->id, 'rank' => 'registered', 'account_id' => '@filter.test', 'sort' => 'registered_asc']);
    expect($rows['total'])->toBe(1)->and($rows['items'][0]['accountId'])->toBe($leaf->account_id);
    $otherTenant = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $foreign = filterMember(User::where('tenant_id', $otherTenant->id)->firstOrFail());
    expect($this->query->members($this->tenant->id, $this->user->id, ['rank' => 'registered', 'account_id' => $foreign->account_id])['total'])->toBe(0);
    for ($i = 0; $i < 21; $i++) {
        filterMember($branch);
    }
    $filters = ['subject' => $member->id, 'rank' => 'registered', 'account_id' => '@filter.test', 'sort' => 'registered_asc'];
    $one = $this->query->members($this->tenant->id, $this->user->id, $filters);
    $two = $this->query->members($this->tenant->id, $this->user->id, $filters + ['page' => 2]);
    expect($one['total'])->toBe(22)->and($one['items'])->toHaveCount(20)->and($one['hasMore'])->toBeTrue()
        ->and($two['items'])->toHaveCount(2)->and($two['hasMore'])->toBeFalse()
        ->and(collect([...$one['items'], ...$two['items']])->pluck('id')->unique())->toHaveCount(22);
    $this->actingAs($this->user, 'tenant_user');
    $this->get('http://a.localhost/promotion/direct?rank=registered&funding=invalid')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('report.filters.rank', 'registered')->missing('report.filters.funding'));
    foreach (['daily', 'commissions'] as $section) {
        $this->getJson('http://a.localhost/promotion/'.$section.'?rank=registered')->assertUnprocessable()->assertJsonValidationErrors('rank');
    }
    $this->getJson('http://a.localhost/promotion/direct?rank=invalid')->assertUnprocessable()->assertJsonValidationErrors('rank');
    $this->app['auth']->guard('tenant_user')->logout();
    $flow = $this->getJson('http://a.localhost/api/mobile/v1/bootstrap')->assertOk()->headers->get('X-Consumer-Flow');
    $token = $this->postJson('http://a.localhost/api/mobile/v1/login', ['identifier' => $this->user->email, 'password' => 'local-password'])->assertCreated()->json('token');
    $this->withToken($token)->withHeader('X-Consumer-Flow', $flow)
        ->getJson('http://a.localhost/api/mobile/v1/client/promotion/direct?rank=registered&funding=unfunded')
        ->assertOk()->assertJsonPath('props.report.filters.rank', 'registered')->assertJsonMissingPath('props.report.filters.funding');
    Http::assertNothingSent();
});
