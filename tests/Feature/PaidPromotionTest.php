<?php

use App\Application\Assets\AssetOverviewQuery;
use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Promotion\AccountActivationStatus;
use App\Application\Promotion\CommissionAccounts;
use App\Application\Promotion\CompanyFundBookQuery;
use App\Application\Promotion\ConfigurePaidPromotion;
use App\Application\Promotion\ConsolidateDevelopmentCommission;
use App\Application\Promotion\PaidPromotionPurchase;
use App\Application\Promotion\PaidPromotionQuery;
use App\Application\Promotion\PaidPromotionRebate;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\Promotion\PromotionQuery;
use App\Application\Promotion\PromotionRanks;
use App\Application\Promotion\PromotionReportQuery;
use App\Application\Promotion\PromotionUpgradeEligibility;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\SecurityDeposit\RefundSecurityDepositAction;
use App\Application\User\PlatformUserQuery;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Application\Wallet\TransferWalletBalanceAction;
use App\Application\Wallet\UserWalletQuery;
use App\Application\Wallet\WalletActivityQuery;
use App\Application\Wallet\WalletEligibilityService;
use App\Application\Withdrawal\CreateWithdrawalAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Promotion\Models\CommissionAward;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->tenant->businessSettings()->update(['required_security_deposit_amount' => '50', 'security_deposit_refund_wait_days' => 0]);
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->admin = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    $this->platform = AdminUser::findOrFail(DB::table('admin_memberships')->where('scope_type', 'PLATFORM')->where('status', 'ACTIVE')->value('admin_user_id'));
});

afterEach(function () {
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

function paidWallet($test, User $user, string $amount = '500000'): void
{
    $application = app(SubmitKycApplicationAction::class)->execute($test->tenant, $user, 'MY', 'PAID-'.$user->id, kycTestImage(), kycTestImage());
    app(ApproveKycAction::class)->execute($test->tenant->id, $application->id, $test->admin);
    $wallet = app(ActivateUserWalletAction::class)->execute($test->tenant->id, $user->id)->wallet;
    $available = LedgerAccount::where('tenant_id', $test->tenant->id)->where('wallet_id', $wallet->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $company = LedgerAccount::where('tenant_id', $test->tenant->id)->where('asset_code', 'USDT')->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
    app(LedgerWriter::class)->post(new LedgerPostingPlan($test->tenant->id, 'USDT', 'paid_test:'.Str::uuid(), 'TEST_TOPUP', null, null, null, [
        new LedgerPostingInstruction($company->id, Money::of('-'.$amount, 'USDT')), new LedgerPostingInstruction($available->id, Money::of($amount, 'USDT')),
    ]));
}
function paidChild($test, User $parent): User
{
    $member = app(PromotionMembershipAction::class)->ensure($test->tenant->id, $parent->id);
    $child = $parent->replicate(['account_id']);
    $child->forceFill(['email' => Str::uuid().'@example.test'])->save();
    app(PromotionMembershipAction::class)->ensure($test->tenant->id, $child->id, $member->id);
    paidWallet($test, $child);

    return $child;
}
function paidBuy($test, User $user, int $rank): object
{
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $test->tenant->id)->where('rank', $rank)->value('id');
    $quote = app(PaidPromotionPurchase::class)->quote($test->tenant->id, $user->id, $level, (string) Str::uuid());

    return app(PaidPromotionPurchase::class)->confirm($test->tenant->id, $user->id, $quote->id);
}

it('charges exact annual fees activates qualification and preserves replay', function () {
    paidWallet($this, $this->user);
    $order = paidBuy($this, $this->user, 1);
    expect($order->amount)->toBe('1000.00000000')->and($order->status)->toBe('COMPLETED');
    app(PaidPromotionPurchase::class)->confirm($this->tenant->id, $this->user->id, $order->id);
    expect(DB::table('paid_promotion_cycles')->count())->toBe(1)->and(DB::table('ledger_entries')->where('event_type', 'PROMOTION_ANNUAL_FEE')->count())->toBe(1);
    expect(app(PaidPromotionQuery::class)->execute($this->tenant->id, $this->user->id)['rank'])->toBe(1);
});

it('pays differential annual fees and direct ordinary activation exactly once', function () {
    paidWallet($this, $this->user);
    paidBuy($this, $this->user, 8);
    $middle = paidChild($this, $this->user);
    paidBuy($this, $middle, 4);
    $child = paidChild($this, $middle);
    $order = paidBuy($this, $child, 3);
    $event = DB::table('paid_promotion_events')->where('source_id', $order->id)->first();
    $shares = DB::table('paid_promotion_shares')->where('event_id', $event->id)->pluck('amount', 'user_id');
    expect($shares[$middle->id])->toBe('3000.00000000')->and($shares[$this->user->id])->toBe('2000.00000000');
    $ordinary = paidChild($this, $this->user);
    $leaf = paidChild($this, $ordinary);
    $request = (string) Str::uuid();
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $leaf->id, $request, '50');
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $leaf->id, $request, '50');
    $event = DB::table('paid_promotion_events')->where('kind', 'ACTIVATION')->where('user_id', $leaf->id)->first();
    $shares = DB::table('paid_promotion_shares')->where('event_id', $event->id)->pluck('amount', 'user_id');
    expect($shares[$ordinary->id])->toBe('20.00000000')->and($shares[$this->user->id])->toBe('100.00000000');
});

function paidTarget($test, int $rank, int $target): void
{
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $test->tenant->id)->where('rank', $rank)->first();
    app(ConfigurePaidPromotion::class)->execute($test->tenant->id, $test->platform, $level->id,
        ['fee' => $level->fee, 'percent' => $level->percent, 'reward' => $level->reward, 'target' => $target, 'revision' => $level->revision, 'enabled' => true]);
}

it('rolls back qualification and rewards on insufficient funds or downstream award failure', function () {
    paidWallet($this, $this->user, '200');
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 1)->value('id');
    $q = app(PaidPromotionPurchase::class)->quote($this->tenant->id, $this->user->id, $level, (string) Str::uuid());
    expect(fn () => app(PaidPromotionPurchase::class)->confirm($this->tenant->id, $this->user->id, $q->id))->toThrow(DomainException::class);
    expect(DB::table('paid_promotion_cycles')->count())->toBe(0)->and(DB::table('paid_promotion_events')->count())->toBe(0)->and(DB::table('paid_promotion_orders')->value('status'))->toBe('QUOTED');
    $child = paidChild($this, $this->user);
    DB::unprepared("CREATE OR REPLACE FUNCTION test_paid_reward_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'test downstream rollback'; END $$; CREATE TRIGGER test_paid_reward_failure BEFORE INSERT ON paid_promotion_events FOR EACH ROW EXECUTE FUNCTION test_paid_reward_failure()");
    expect(fn () => paidBuy($this, $child, 1))->toThrow(QueryException::class);
    DB::unprepared('DROP TRIGGER test_paid_reward_failure ON paid_promotion_events; DROP FUNCTION test_paid_reward_failure()');
    expect(DB::table('paid_promotion_cycles')->count())->toBe(0)->and(DB::table('ledger_entries')->where('event_type', 'PROMOTION_ANNUAL_FEE')->count())->toBe(0);
});

it('rejects expired changed and superseded quotes without partial charges', function () {
    paidWallet($this, $this->user);
    $purchase = app(PaidPromotionPurchase::class);
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 1)->value('id');
    $q = $purchase->quote($this->tenant->id, $this->user->id, $level, (string) Str::uuid());
    $this->travel(6)->minutes();
    expect(fn () => $purchase->confirm($this->tenant->id, $this->user->id, $q->id))->toThrow(DomainException::class);
    $this->travelBack();
    $q = $purchase->quote($this->tenant->id, $this->user->id, $level, (string) Str::uuid());
    paidTarget($this, 1, 2);
    expect(fn () => $purchase->confirm($this->tenant->id, $this->user->id, $q->id))->toThrow(DomainException::class);
    $q = $purchase->quote($this->tenant->id, $this->user->id, $level, (string) Str::uuid());
    paidBuy($this, $this->user, 1);
    expect(fn () => $purchase->confirm($this->tenant->id, $this->user->id, $q->id))->toThrow(DomainException::class);
    expect(DB::table('ledger_entries')->where('event_type', 'PROMOTION_ANNUAL_FEE')->count())->toBe(1);
});

it('uses calendar years and settles a captured automatic return after period expiry', function () {
    $this->travelTo(CarbonImmutable::parse('2028-02-29 10:00:00', 'Asia/Kuala_Lumpur'));
    paidWallet($this, $this->user);
    paidTarget($this, 1, 1);
    $order = paidBuy($this, $this->user, 1);
    $cycle = DB::table('paid_promotion_cycles')->where('id', $order->cycle_id)->first();
    expect(CarbonImmutable::parse($cycle->ends_at)->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i:s'))->toBe('2029-02-28 10:00:00');
    $child = paidChild($this, $this->user);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $claim = DB::table('paid_promotion_rebates')->where('user_id', $this->user->id)->firstOrFail();
    $this->travelTo(CarbonImmutable::parse($cycle->ends_at));
    expect(app(PaidPromotionQuery::class)->execute($this->tenant->id, $this->user->id)['rank'])->toBe(0);
    app(PaidPromotionRebate::class)->settleAutomatic($this->tenant->id, $claim->id);
    $new = paidBuy($this, $this->user, 2);
    expect($new->cycle_id)->not->toBe($order->cycle_id)->and($new->amount)->toBe('2000.00000000');
});

it('counts first funding at all indirect depths without counting refunded or repeated funding', function () {
    paidWallet($this, $this->user);
    paidTarget($this, 1, 2);
    paidBuy($this, $this->user, 1);
    $middle = paidChild($this, $this->user);
    $leaf = paidChild($this, $middle);
    $fund = app(FundSecurityDepositAction::class);
    $refund = app(RefundSecurityDepositAction::class);
    $fund->execute($this->tenant->id, $leaf->id, (string) Str::uuid(), '50');
    $request = $refund->request($this->tenant->id, $leaf->id, (string) Str::uuid());
    $refund->settle($this->tenant->id, $leaf->id, $request->id);
    $fund->execute($this->tenant->id, $leaf->id, (string) Str::uuid(), '50');
    expect(CommissionAward::query()->where('tenant_id', $this->tenant->id)->sum('amount'))->toBe('50.00000000');
    expect(CommissionAward::query()->where('tenant_id', $this->tenant->id)->count())->toBe(2);
    $p = app(PaidPromotionQuery::class)->execute($this->tenant->id, $this->user->id);
    expect($p['indirectPeople'])->toBe(1)->and($p['progress']['indirect'])->toBe(1)->and($p['progress']['direct'])->toBe(0);
    $fund->execute($this->tenant->id, $middle->id, (string) Str::uuid(), '50');
    expect(DB::table('paid_promotion_rebates')->where('user_id', $this->user->id)->count())->toBe(0);
    $second = paidChild($this, $middle);
    $fund->execute($this->tenant->id, $second->id, (string) Str::uuid(), '50');
    expect(DB::table('paid_promotion_rebates')->where('user_id', $this->user->id)->value('amount'))->toBe('1000.00000000');
});

function racePaidOperations(array $operations): array
{
    if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'card_ui_test' || DB::transactionLevel() !== 1) {
        throw new RuntimeException('Concurrent fixtures require isolated card_ui_test.');
    }
    DB::commit();
    RefreshDatabaseState::$migrated = false;
    DB::disconnect();
    $directory = sys_get_temp_dir().'/asset-race-'.Str::uuid();
    mkdir($directory, 0700);
    $children = [];
    foreach ($operations as $index => $operation) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to start isolated worker');
        }
        if ($pid === 0) {
            DB::purge();
            while (! is_file($directory.'/go')) {
                usleep(1000);
            }
            try {
                $operation();
                $result = 'completed';
            } catch (DomainException $e) {
                $result = 'rejected';
            } catch (Throwable $e) {
                $result = 'error:'.get_class($e);
            }
            file_put_contents($directory.'/'.$index, $result);
            exit(0);
        }
        $children[] = $pid;
    }
    touch($directory.'/go');
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        if (pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('Worker failed');
        }
    }
    DB::reconnect();
    DB::beginTransaction();
    $results = [];
    foreach (array_keys($operations) as $index) {
        $results[] = file_get_contents($directory.'/'.$index);
        unlink($directory.'/'.$index);
    }
    unlink($directory.'/go');
    rmdir($directory);

    return $results;
}

it('serializes duplicate confirmations and prevents competing payments from spending twice', function () {
    paidWallet($this, $this->user, '1100');
    $p = app(PaidPromotionPurchase::class);
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 1)->value('id');
    $q = $p->quote($this->tenant->id, $this->user->id, $level, (string) Str::uuid());
    $tenant = $this->tenant->id;
    $user = $this->user->id;
    $results = racePaidOperations([fn () => app(PaidPromotionPurchase::class)->confirm($tenant, $user, $q->id), fn () => app(PaidPromotionPurchase::class)->confirm($tenant, $user, $q->id)]);
    expect($results)->toBe(['completed', 'completed'])->and(DB::table('ledger_entries')->where('event_type', 'PROMOTION_ANNUAL_FEE')->count())->toBe(1);
    expect(LedgerAccount::where('user_id', $user)->where('account_type', 'USER_AVAILABLE')->value('balance'))->toBe('100.00000000');
});

it('serializes independent fee payment and wallet withdrawal without overspending', function () {
    paidWallet($this, $this->user, '1100');
    $p = app(PaidPromotionPurchase::class);
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 1)->value('id');
    $q = $p->quote($this->tenant->id, $this->user->id, $level, (string) Str::uuid());
    $tenant = $this->tenant->id;
    $user = $this->user->id;
    $results = racePaidOperations([fn () => app(PaidPromotionPurchase::class)->confirm($tenant, $user, $q->id), fn () => app(CreateWithdrawalAction::class)->executeWithAddress($tenant, $user, (string) Str::uuid(), 'T'.str_repeat('A', 33), '1000', expectedFee: '0')]);
    sort($results);
    expect($results)->toBe(['completed', 'rejected']);
    expect(LedgerAccount::where('user_id', $user)->where('balance', '<', 0)->exists())->toBeFalse();
});

it('keeps direct awards for lower inviters and excludes same or lower indirect levels', function () {
    paidWallet($this, $this->user);
    paidBuy($this, $this->user, 1);
    $higher = paidChild($this, $this->user);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $higher->id, (string) Str::uuid(), '50');
    paidBuy($this, $higher, 8);
    expect(CommissionAward::where('user_id', $this->user->id)->sum('amount'))->toBe('50.00000000');
    $same = paidChild($this, $higher);
    paidBuy($this, $same, 8);
    $leaf = paidChild($this, $same);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $leaf->id, (string) Str::uuid(), '50');
    expect(CommissionAward::where('user_id', $same->id)->sum('amount'))->toBe('120.00000000');
    expect(CommissionAward::where('user_id', $higher->id)->count())->toBe(0)->and(CommissionAward::where('user_id', $this->user->id)->count())->toBe(1);
});

it('enforces HTTP ownership and platform scope while removing manual rebate endpoints', function () {
    paidWallet($this, $this->user);
    $order = paidBuy($this, $this->user, 1);
    $otherTenant = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $other = User::where('tenant_id', $otherTenant->id)->firstOrFail();
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/promotion/membership')->assertOk();
    $this->postJson('http://a.localhost/promotion/quotes/'.$order->id.'/confirm', ['current_password' => 'wrong', 'confirmed' => true])->assertUnprocessable();
    $this->actingAs($other, 'tenant_user')->get('http://b.localhost/promotion/membership?order='.$order->id)->assertNotFound();
    $base = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/configuration/paid-promotion';
    $this->actingAs($this->platform, 'platform_admin')->get($base)->assertOk();
    $this->get(str_replace('/paid-promotion', '/promotion', $base))->assertOk()->assertInertia(fn ($page) => $page->component('platform/PaidPromotion')->has('paid.levels')->missing('promotion.members'));
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->first();
    $this->postJson($base.'/levels/'.$level->id, ['fee' => $level->fee, 'reward' => $level->reward, 'percent' => $level->percent, 'target' => $level->target, 'enabled' => true, 'revision' => $level->revision])->assertRedirect()->assertSessionHasNoErrors();
    $this->postJson($base.'/rebates/'.Str::uuid(), ['decision' => 'approve', 'confirmed' => true])->assertNotFound();
    $this->actingAs($this->user, 'tenant_user')->postJson('http://a.localhost/promotion/rebates', [])->assertNotFound();
    $this->postJson('http://a.localhost/promotion/rebates/'.Str::uuid().'/withdraw', [])->assertNotFound();
});

it('uses all eight upgrade tariffs without rewarding the same activated member again', function () {
    paidWallet($this, $this->user);
    paidBuy($this, $this->user, 1);
    $child = paidChild($this, $this->user);
    $fund = app(FundSecurityDepositAction::class);
    $fund->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $refunds = app(RefundSecurityDepositAction::class);
    $r = $refunds->request($this->tenant->id, $child->id, (string) Str::uuid());
    $refunds->settle($this->tenant->id, $child->id, $r->id);
    foreach ([2 => '1000', 3 => '3000', 4 => '5000', 5 => '10000', 6 => '30000', 7 => '50000', 8 => '100000'] as $rank => $difference) {
        expect(paidBuy($this, $this->user, $rank)->amount)->toBe($difference.'.00000000');
    }
    $fund->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $p = app(PaidPromotionQuery::class)->execute($this->tenant->id, $this->user->id);
    $row = $p['tables']['ACTIVATION'][0]['direct'];
    expect($row['count'])->toBe(2)->and($row['amount'])->toBe('50.00000000')->and($row['minimum'])->toBe('0.00000000')->and($row['maximum'])->toBe('50.00000000');
    expect($p['progress']['paid'])->toBe('200000.00000000');
});

it('rounds annual percentages down and never spends the fractional remainder', function () {
    paidWallet($this, $this->user);
    $l = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 1)->first();
    app(ConfigurePaidPromotion::class)->execute($this->tenant->id, $this->platform, $l->id, ['fee' => '1000.00000001', 'reward' => $l->reward, 'percent' => $l->percent, 'target' => $l->target, 'enabled' => true, 'revision' => $l->revision]);
    paidBuy($this, $this->user, 1);
    $child = paidChild($this, $this->user);
    $o = paidBuy($this, $child, 1);
    $e = DB::table('paid_promotion_events')->where('source_id', $o->id)->first();
    expect(DB::table('paid_promotion_shares')->where('event_id', $e->id)->value('amount'))->toBe('300.00000000');
    $book = app(CompanyFundBookQuery::class)->execute($this->tenant->id, null, 1);
    expect($book['totals']['annualFees'])->toBe('2000.00000002')->and($book['totals']['annualCommissions'])->toBe('300.00000000');
});

it('rejects disabled tariffs and cross-company quotes without moving funds', function () {
    paidWallet($this, $this->user);
    $l = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 1)->first();
    $q = app(PaidPromotionPurchase::class)->quote($this->tenant->id, $this->user->id, $l->id, (string) Str::uuid());
    app(ConfigurePaidPromotion::class)->execute($this->tenant->id, $this->platform, $l->id, ['fee' => $l->fee, 'reward' => $l->reward, 'percent' => $l->percent, 'target' => $l->target, 'revision' => $l->revision, 'enabled' => false]);
    expect(fn () => app(PaidPromotionPurchase::class)->confirm($this->tenant->id, $this->user->id, $q->id))->toThrow(RecordNotFoundException::class);
    $foreign = DB::table('paid_promotion_levels')->where('tenant_id', '<>', $this->tenant->id)->value('id');
    expect(fn () => app(PaidPromotionPurchase::class)->quote($this->tenant->id, $this->user->id, $foreign, (string) Str::uuid()))->toThrow(RecordNotFoundException::class);
    expect(DB::table('ledger_entries')->where('event_type', 'PROMOTION_ANNUAL_FEE')->count())->toBe(0);
});

it('serializes automatic return against a previously quoted upgrade without losing the paid basis', function () {
    paidWallet($this, $this->user);
    paidTarget($this, 1, 1);
    paidBuy($this, $this->user, 1);
    $child = paidChild($this, $this->user);
    $tenant = $this->tenant->id;
    $user = $this->user->id;
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $tenant)->where('rank', 2)->value('id');
    $q = app(PaidPromotionPurchase::class)->quote($tenant, $user, $level, (string) Str::uuid());
    app(FundSecurityDepositAction::class)->execute($tenant, $child->id, (string) Str::uuid(), '50');
    $claim = DB::table('paid_promotion_rebates')->where('user_id', $user)->firstOrFail();
    $results = racePaidOperations([fn () => app(PaidPromotionRebate::class)->settleAutomatic($tenant, $claim->id), fn () => app(PaidPromotionPurchase::class)->confirm($tenant, $user, $q->id)]);
    expect($results[0])->toBe('completed')->and($results[1])->toBeIn(['completed', 'rejected']);
    $p = app(PaidPromotionQuery::class)->execute($tenant, $user);
    expect($p['progress']['returned'])->toBe('1000.00000000')->and($p['rank'])->toBe($results[1] === 'completed' ? 2 : 1);
    expect($p['progress']['paid'])->toBe($results[1] === 'completed' ? '2000.00000000' : '1000.00000000');
});

it('excludes non-earning annual orders from commission tables for ordinary ancestors', function () {
    paidWallet($this, $this->user);
    $child = paidChild($this, $this->user);
    paidBuy($this, $child, 1);
    $report = app(PaidPromotionQuery::class)->execute($this->tenant->id, $this->user->id);
    expect($report['totals']['ANNUAL'])->toBe('0');
    expect(array_sum(array_column(array_column($report['tables']['ANNUAL'], 'direct'), 'count')))->toBe(0);
});

it('exposes read-only scoped membership states without reviving expired qualification', function () {
    $query = app(PaidPromotionQuery::class);
    $count = DB::table('ledger_accounts')->count();
    $initial = $query->execute($this->tenant->id, $this->user->id);
    expect($initial['membershipStatus'])->toBe('NONE')
        ->and($initial['previousCycle'])->toBeNull()
        ->and($initial['availableBalance'])->toBe('0.00000000')
        ->and(DB::table('ledger_accounts')->count())->toBe($count);
    paidWallet($this, $this->user);
    $order = paidBuy($this, $this->user, 1);
    $active = $query->execute($this->tenant->id, $this->user->id);
    expect($active['membershipStatus'])->toBe('ACTIVE')
        ->and($active['availableBalance'])->toBe('499000.00000000');
    $this->travelTo(CarbonImmutable::parse(DB::table('paid_promotion_cycles')->where('id', $order->cycle_id)->value('ends_at')));
    $expired = $query->execute($this->tenant->id, $this->user->id);
    expect($expired['membershipStatus'])->toBe('EXPIRED')
        ->and($expired['previousCycle']['rank'])->toBe(1)
        ->and($expired['rank'])->toBe(0)
        ->and($expired['cycle'])->toBeNull()
        ->and($expired['percent'])->toBe(0)
        ->and($expired['reward'])->toBe('20')
        ->and($expired['availableBalance'])->toBe($active['availableBalance']);
    $otherTenant = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $otherUser = User::where('tenant_id', $otherTenant->id)->firstOrFail();
    expect($query->execute($otherTenant->id, $otherUser->id)['membershipStatus'])->toBe('NONE');
});

it('groups current team levels at one boundary without regrouping historical rewards', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC'));
    paidWallet($this, $this->user);
    paidBuy($this, $this->user, 6);
    $direct = paidChild($this, $this->user);
    paidChild($this, $this->user);
    paidBuy($this, $direct, 1);
    $indirect = paidChild($this, $direct);
    $lastOrder = paidBuy($this, $indirect, 2);
    paidChild($this, $indirect);
    $query = app(PaidPromotionQuery::class);
    $before = $query->execute($this->tenant->id, $this->user->id);
    expect($before['teamByLevel'])->toHaveCount(9)
        ->and($before['directPeople'])->toBe(2)
        ->and($before['indirectPeople'])->toBe(2)
        ->and($before['teamByLevel'][0])->toBe(['rank' => 0, 'direct' => 1, 'indirect' => 1])
        ->and($before['teamByLevel'][1]['direct'])->toBe(1)
        ->and($before['teamByLevel'][2]['indirect'])->toBe(1)
        ->and($before['teamByLevel'][6]['direct'])->toBe(0);
    paidBuy($this, $direct, 3);
    $upgraded = $query->execute($this->tenant->id, $this->user->id);
    expect($upgraded['teamByLevel'][1]['direct'])->toBe(0)
        ->and($upgraded['teamByLevel'][3]['direct'])->toBe(1)
        ->and($upgraded['tables']['ANNUAL'][1])->toBe($before['tables']['ANNUAL'][1]);
    $this->travelTo(CarbonImmutable::parse(DB::table('paid_promotion_cycles')->where('id', $lastOrder->cycle_id)->value('ends_at')));
    $entries = DB::table('ledger_entries')->count();
    $expired = $query->execute($this->tenant->id, $this->user->id);
    expect($expired['teamByLevel'][0])->toBe(['rank' => 0, 'direct' => 2, 'indirect' => 2])
        ->and(array_sum(array_column($expired['teamByLevel'], 'direct')))->toBe($expired['directPeople'])
        ->and(array_sum(array_column($expired['teamByLevel'], 'indirect')))->toBe($expired['indirectPeople'])
        ->and($expired['tables'])->toBe($upgraded['tables'])
        ->and(DB::table('ledger_entries')->count())->toBe($entries);
    paidBuy($this, $indirect, 4);
    $renewed = $query->execute($this->tenant->id, $this->user->id);
    expect($renewed['teamByLevel'][4]['indirect'])->toBe(1)
        ->and($renewed['teamByLevel'][0]['indirect'])->toBe(1);
    $foreign = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $foreignUser = User::where('tenant_id', $foreign->id)->firstOrFail();
    $other = $query->execute($foreign->id, $foreignUser->id);
    expect($other['directPeople'])->toBe(0)->and($other['indirectPeople'])->toBe(0);
});

it('serves a scoped benefits home without income detail or creating financial orders', function () {
    $this->actingAs($this->user, 'tenant_user');
    $before = DB::table('paid_promotion_orders')->count();
    foreach (['/promotion' => 'overview', '/promotion/rules' => 'rules'] as $path => $section) {
        $this->get('http://a.localhost'.$path)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('user/PromotionHub')->where('section', $section)
            ->where('home.paid.rank', 0)->where('home.paid.membershipStatus', 'NONE')
            ->has('home.paid.levels', 8)->missing('home.paid.tables')->missing('home.direct')->missing('home.paid.teamByLevel'));
    }
    $this->get('http://a.localhost/promotion/invitations')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('user/Promotion')->where('section', 'invitations')->has('promotion.paid.teamByLevel', 9));
    $this->get('http://a.localhost/promotion/team')->assertRedirect('/promotion/invitations');
    expect(DB::table('paid_promotion_orders')->count())->toBe($before);
    $otherTenant = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $this->get('http://b.localhost/promotion/invitations')->assertRedirect();
    expect(fn () => app(PaidPromotionQuery::class)->benefits($otherTenant->id, $this->user->id))->toThrow(ModelNotFoundException::class);
});

it('keeps current benefit snapshots distinct from changed offers and falls back on expiry', function () {
    paidWallet($this, $this->user);
    $order = paidBuy($this, $this->user, 1);
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 1)->first();
    app(ConfigurePaidPromotion::class)->execute($this->tenant->id, $this->platform, $level->id,
        ['fee' => '1500', 'reward' => 55, 'percent' => 35, 'target' => $level->target, 'revision' => $level->revision, 'enabled' => false]);
    $query = app(PaidPromotionQuery::class);
    $view = $query->benefits($this->tenant->id, $this->user->id);
    expect($view['rank'])->toBe(1)->and($view['percent'])->toBe(30)->and($view['reward'])->toBe('50')
        ->and($view['cycle']['tariff'])->toBe('1000.00000000')
        ->and($view['levels'][0]['percent'])->toBe(35)->and($view['levels'][0]['enabled'])->toBeFalse();
    $this->travelTo(CarbonImmutable::parse($view['cycle']['endsAt']));
    $expired = $query->benefits($this->tenant->id, $this->user->id);
    expect($expired['rank'])->toBe(0)->and($expired['reward'])->toBe('20')
        ->and($expired['membershipStatus'])->toBe('EXPIRED')->and($expired['previousCycle']['rank'])->toBe(1);
});

it('reports annual and activation income once and keeps daily source events unique', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-16 12:00:00 UTC'));
    paidWallet($this, $this->user);
    paidBuy($this, $this->user, 8);
    $child = paidChild($this, $this->user);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    paidBuy($this, $child, 1);
    $query = app(PromotionReportQuery::class);
    $income = $query->commissions($this->tenant->id, $this->user->id, []);
    expect($income['items'])->toHaveCount(2)->and($income['totals']['annual'])->toBe('950.00000000')
        ->and($income['totals']['activation'])->toBe('120.00000000')->and($income['totals']['legacy'])->toBe('0')
        ->and($income['totals']['total'])->toBe('1070.00000000');
    $daily = $query->daily($this->tenant->id, $this->user->id, []);
    expect($daily['items'])->toHaveCount(3)->and($daily['counts'])->toBe(['invited' => 1, 'funded' => 1, 'orders' => 1]);
    expect(collect($daily['items'])->firstWhere('kind', 'activation')['firstFunding'])->toBeTrue();
    $filtered = $query->daily($this->tenant->id, $this->user->id, ['activity' => 'annual']);
    expect($filtered['items'])->toHaveCount(1)->and($filtered['items'][0]['purchaseKind'])->toBe('purchase')
        ->and($filtered['totals'])->toBe($daily['totals'])->and($filtered['counts'])->toBe($daily['counts']);
    $members = $query->members($this->tenant->id, $this->user->id, ['rank' => '1', 'funding' => 'unfunded']);
    expect($members['items'])->toHaveCount(1)->and($members['items'][0]['totals']['total'])->toBe('1070.00000000');
    paidBuy($this, $child, 2);
    expect($query->members($this->tenant->id, $this->user->id, [])['items'][0]['rank'])->toBe(2)
        ->and($query->commissions($this->tenant->id, $this->user->id, ['rank' => '1'])['items'])->toHaveCount(1);
    expect(array_column($query->daily($this->tenant->id, $this->user->id, ['activity' => 'annual'])['items'], 'purchaseKind'))->toContain('upgrade');
    $this->travelTo(CarbonImmutable::parse('2027-09-17 12:00:00 UTC'));
    expect($query->members($this->tenant->id, $this->user->id, ['rank' => '0'])['items'])->toHaveCount(1)
        ->and($query->commissions($this->tenant->id, $this->user->id, ['rank' => '1'])['items'])->toHaveCount(1);
    paidBuy($this, $child, 1);
    expect($query->daily($this->tenant->id, $this->user->id, ['activity' => 'annual'])['items'][0]['purchaseKind'])->toBe('renewal');
});

it('keeps zero commission annual payments visible and rejects the retired transfer tab', function () {
    paidWallet($this, $this->user);
    $child = paidChild($this, $this->user);
    paidBuy($this, $child, 1);
    $query = app(PromotionReportQuery::class);
    $daily = $query->daily($this->tenant->id, $this->user->id, ['activity' => 'annual']);
    expect($daily['items'])->toHaveCount(1)->and((string) $daily['items'][0]['amount'])->toBe('0');
    $history = $query->commissions($this->tenant->id, $this->user->id, []);
    expect($history['items'])->toHaveCount(0)->and($history['totals']['total'])->toBe('0');
    $this->actingAs($this->user, 'tenant_user')->getJson('http://a.localhost/promotion/commissions?tab=transfers')->assertUnprocessable();
    $foreign = Tenant::where('id', '<>', $this->tenant->id)->firstOrFail();
    expect(fn () => $query->daily($foreign->id, $this->user->id, []))->toThrow(ModelNotFoundException::class);
});

it('validates report date pairs and keeps old single dates subordinate to explicit ranges', function () {
    $this->actingAs($this->user, 'tenant_user');
    foreach (['date_from=2026-09-01', 'date_to=2026-09-16', 'date_from=2026-09-16&date_to=2026-09-01', 'date_from=2026-02-30&date_to=2026-03-01'] as $query) {
        $this->getJson('http://a.localhost/promotion/daily?'.$query)->assertUnprocessable();
    }
    $this->get('http://a.localhost/promotion/daily?date=2026-09-01')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('user/PromotionReport')->where('section', 'daily')->where('report.dateFrom', '2026-09-01')->where('report.dateTo', '2026-09-01'));
    $this->get('http://a.localhost/promotion/commissions?date=invalid&date_from=2026-09-01&date_to=2026-09-16&rank=unknown&relation=unknown&kind=legacy')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('user/PromotionCommissions')
        ->where('history.dateFrom', '2026-09-01')->where('history.dateTo', '2026-09-16')->where('history.filters.rank', 'unknown'));
    $this->getJson('http://a.localhost/promotion/direct?rank=unknown')->assertUnprocessable();
    $this->get('http://a.localhost/promotion/direct')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('user/PromotionReport')->where('section', 'direct')->has('report.items', 0));
});

it('uses inclusive company days and counts repeat funding once per business event', function () {
    $this->tenant->update(['timezone' => 'Asia/Kuala_Lumpur']);
    $this->travelTo(CarbonImmutable::parse('2026-09-16 15:59:59 UTC'));
    paidWallet($this, $this->user);
    paidBuy($this, $this->user, 1);
    $middle = paidChild($this, $this->user);
    $leaf = paidChild($this, $middle);
    $fund = app(FundSecurityDepositAction::class);
    $fund->execute($this->tenant->id, $leaf->id, (string) Str::uuid(), '50');
    $this->travelTo(CarbonImmutable::parse('2026-09-16 16:00:00 UTC'));
    $this->tenant->businessSettings()->update(['required_security_deposit_amount' => '100']);
    $fund->execute($this->tenant->id, $leaf->id, (string) Str::uuid(), '50');
    $query = app(PromotionReportQuery::class);
    $day = ['date_from' => '2026-09-16', 'date_to' => '2026-09-16'];
    $first = $query->daily($this->tenant->id, $this->user->id, $day + ['activity' => 'activation']);
    $second = $query->daily($this->tenant->id, $this->user->id, []);
    expect($first['items'])->toHaveCount(1)->and($first['items'][0]['firstFunding'])->toBeTrue()
        ->and($second['dateFrom'])->toBe('2026-09-17')->and($second['items'])->toHaveCount(1)
        ->and($second['items'][0]['firstFunding'])->toBeFalse()->and($second['counts']['funded'])->toBe(1)
        ->and($second['totals']['activation'])->toBe('0')
        ->and($second['items'][0]['amount'])->toBe('0');
    $combined = $query->commissions($this->tenant->id, $this->user->id, $day + [
        'kind' => 'activation', 'rank' => '0', 'relation' => 'indirect', 'account_id' => $leaf->fresh()->account_id,
    ]);
    expect($combined['items'])->toHaveCount(1)->and($combined['items'][0]['beneficiaryRank'])->toBe(1)
        ->and($combined['items'][0]['rate'])->toBe('30.00000000')->and($combined['totals']['total'])->toBe('30.00000000')
        ->and($query->commissions($this->tenant->id, $this->user->id, ['relation' => 'direct'])['items'])->toBe([])
        ->and($query->commissions($this->tenant->id, $this->user->id, ['rank' => 'unknown'])['items'])->toBe([]);
    $foreign = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $foreignUser = User::where('tenant_id', $foreign->id)->firstOrFail();
    foreach (['commissions', 'daily', 'members'] as $method) {
        expect($query->$method($foreign->id, $foreignUser->id, [])['items'])->toBe([]);
    }
});

it('aggregates all matching income before pagination and batches member reporting', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-16 10:00:00 UTC'));
    $fund = app(FundSecurityDepositAction::class);
    for ($n = 1; $n <= 31; $n++) {
        $child = paidChild($this, $this->user);
        $fund->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
        $this->travel(1)->seconds();
    }
    $query = app(PromotionReportQuery::class);
    $first = $query->commissions($this->tenant->id, $this->user->id, ['kind' => 'activation']);
    $second = $query->commissions($this->tenant->id, $this->user->id, ['kind' => 'activation', 'page' => 2]);
    expect($first['items'])->toHaveCount(30)->and($first['hasMore'])->toBeTrue()
        ->and($second['items'])->toHaveCount(1)->and($second['hasMore'])->toBeFalse()
        ->and($first['totals']['total'])->toBe('620.00000000')->and($second['totals'])->toBe($first['totals']);
    $daily = $query->daily($this->tenant->id, $this->user->id, ['activity' => 'activation']);
    expect($daily['items'])->toHaveCount(30)->and($daily['counts']['funded'])->toBe(31)
        ->and($daily['totals']['total'])->toBe('620.00000000');
    $parent = app(PromotionMembershipAction::class)->ensure($this->tenant->id, $this->user->id);
    for ($n = 0; $n < 20; $n++) {
        $member = $this->user->replicate(['account_id']);
        $member->forceFill(['email' => Str::uuid().'@example.test'])->save();
        app(PromotionMembershipAction::class)->ensure($this->tenant->id, $member->id, $parent->id);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $members = $query->members($this->tenant->id, $this->user->id, []);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($members['items'])->toHaveCount(20)->and($members['total'])->toBe(51)->and($members['hasMore'])->toBeTrue()
        ->and($queries)->toBeLessThanOrEqual(5)
        ->and($query->members($this->tenant->id, $this->user->id, ['page' => 2])['items'])->toHaveCount(20)
        ->and($query->members($this->tenant->id, $this->user->id, ['funding' => 'unfunded'])['total'])->toBe(20);
});

it('separates business dates from commission posting dates across midnight without rewriting history', function () {
    $this->tenant->update(['timezone' => 'Asia/Kuala_Lumpur']);
    $this->travelTo(CarbonImmutable::parse('2026-09-16 15:59:59 UTC'));
    $child = paidChild($this, $this->user);
    $advanced = false;
    // Simulate midnight passing between the source event and its reward posting.
    DB::listen(function ($query) use (&$advanced) {
        if (! $advanced && str_starts_with($query->sql, 'insert into "paid_promotion_events"')) {
            $advanced = true;
            $this->travelTo(CarbonImmutable::parse('2026-09-16 16:00:01 UTC'));
        }
    });
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $query = app(PromotionReportQuery::class);
    $businessDay = $query->daily($this->tenant->id, $this->user->id, ['date' => '2026-09-16', 'activity' => 'activation']);
    $postingDay = $query->daily($this->tenant->id, $this->user->id, ['date' => '2026-09-17']);
    expect($advanced)->toBeTrue()->and($businessDay['items'])->toHaveCount(1)
        ->and($businessDay['totals']['total'])->toBe('0')->and($businessDay['items'][0]['amount'])->toBe('20.00000000')
        ->and($businessDay['items'][0]['postedAt'])->not->toBe($businessDay['items'][0]['occurredAt'])
        ->and($postingDay['items'])->toBe([])->and($postingDay['totals']['activation'])->toBe('20.00000000');
});

it('does not unlock a zero-share ancestor reward by upgrading before later funding', function () {
    paidWallet($this, $this->user);
    $middle = paidChild($this, $this->user);
    $leaf = paidChild($this, $middle);
    $fund = app(FundSecurityDepositAction::class);
    $firstRequest = (string) Str::uuid();
    $fund->execute($this->tenant->id, $leaf->id, $firstRequest, '50');
    $firstEvent = DB::table('paid_promotion_events')->where('user_id', $leaf->id)->where('kind', 'ACTIVATION')->first();
    expect(DB::table('paid_promotion_shares')->where('event_id', $firstEvent->id)->where('user_id', $this->user->id)->value('amount'))->toBe('0.00000000');
    paidBuy($this, $this->user, 8);
    $this->tenant->businessSettings()->update(['required_security_deposit_amount' => '100']);
    $request = (string) Str::uuid();
    $fund->execute($this->tenant->id, $leaf->id, $request, '50');
    $fund->execute($this->tenant->id, $leaf->id, $request, '50');
    $fund->execute($this->tenant->id, $leaf->id, $firstRequest, '50');
    expect(CommissionAward::query()->where('user_id', $this->user->id)->count())->toBe(0)
        ->and(CommissionAward::query()->where('user_id', $middle->id)->sum('amount'))->toBe('20.00000000')
        ->and(DB::table('paid_promotion_events')->where('user_id', $leaf->id)->where('kind', 'ACTIVATION')->count())->toBe(2)
        ->and(DB::table('paid_promotion_shares')->where('event_id', $firstEvent->id)->where('user_id', $this->user->id)->value('amount'))->toBe('0.00000000');
});

it('automatically returns annual fees once and returns only the later upgrade difference', function () {
    paidWallet($this, $this->user);
    paidTarget($this, 1, 1);
    paidTarget($this, 2, 2);
    $order = paidBuy($this, $this->user, 1);
    $cycle = DB::table('paid_promotion_cycles')->where('id', $order->cycle_id)->first();
    expect($cycle->rebate_policy)->toBe('AUTO_FIRST_FUNDING');
    $child = paidChild($this, $this->user);
    $fund = app(FundSecurityDepositAction::class);
    DB::unprepared("CREATE OR REPLACE FUNCTION test_auto_rebate_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action='PROMOTION_REBATE_AUTO_COMPLETED' THEN RAISE EXCEPTION 'test rollback'; END IF; RETURN NEW; END $$; CREATE TRIGGER test_auto_rebate_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION test_auto_rebate_failure()");
    $request = (string) Str::uuid();
    $fund->execute($this->tenant->id, $child->id, $request, '50');
    $fund->execute($this->tenant->id, $child->id, $request, '50');
    $rebates = app(PaidPromotionRebate::class);
    $claim = DB::table('paid_promotion_rebates')->where('cycle_id', $cycle->id)->firstOrFail();
    expect($claim->source)->toBe('AUTO')->and($claim->amount)->toBe('1000.00000000')->and($claim->reviewer_id)->toBeNull();
    expect(fn () => paidBuy($this, $this->user, 2))->toThrow(DomainException::class);
    DB::unprepared('DROP TRIGGER test_auto_rebate_failure ON audit_logs; DROP FUNCTION test_auto_rebate_failure()');
    $rebates->settleAutomatic($this->tenant->id, $claim->id);
    $rebates->settleAutomatic($this->tenant->id, $claim->id);
    expect(DB::table('paid_promotion_rebates')->where('id', $claim->id)->value('processed_at'))->not->toBeNull();
    expect(DB::table('audit_logs')->where('action', 'PROMOTION_REBATE_AUTO_COMPLETED')->value('actor_type'))->toBe('SYSTEM');
    $upgrade = paidBuy($this, $this->user, 2);
    expect($upgrade->amount)->toBe('1000.00000000')->and($upgrade->cycle_id)->toBe($cycle->id)
        ->and(DB::table('paid_promotion_cycles')->where('id', $cycle->id)->value('ends_at'))->toBe($cycle->ends_at);
    $next = paidChild($this, $this->user);
    $fund->execute($this->tenant->id, $next->id, (string) Str::uuid(), '50');
    $second = DB::table('paid_promotion_rebates')->where('cycle_id', $cycle->id)->where('id', '<>', $claim->id)->firstOrFail();
    expect($second->amount)->toBe('1000.00000000')->and($second->paid_total)->toBe('2000.00000000');
    $this->artisan('promotion:recover', ['--tenant' => $this->tenant->id])->assertSuccessful();
    expect(DB::table('paid_promotion_rebates')->where('cycle_id', $cycle->id)->where('status', 'APPROVED')->sum('amount'))->toBe('2000.00000000')
        ->and(DB::table('ledger_entries')->where('event_type', 'PROMOTION_FEE_REBATE')->count())->toBe(2);
    expect(fn () => DB::transaction(fn () => DB::table('paid_promotion_cycles')->where('id', $cycle->id)->update(['rebate_policy' => 'MANUAL_ALL_FUNDING'])))->toThrow(QueryException::class);
});

it('counts only first funding including zero-commission indirect activations for automatic rebates', function () {
    paidWallet($this, $this->user);
    paidTarget($this, 1, 2);
    paidBuy($this, $this->user, 1);
    $middle = paidChild($this, $this->user);
    paidBuy($this, $middle, 8);
    $leaf = paidChild($this, $middle);
    $fund = app(FundSecurityDepositAction::class);
    $fund->execute($this->tenant->id, $leaf->id, (string) Str::uuid(), '50');
    $refund = app(RefundSecurityDepositAction::class);
    $r = $refund->request($this->tenant->id, $leaf->id, (string) Str::uuid());
    $refund->settle($this->tenant->id, $leaf->id, $r->id);
    $fund->execute($this->tenant->id, $leaf->id, (string) Str::uuid(), '50');
    $p = app(PaidPromotionQuery::class)->benefits($this->tenant->id, $this->user->id)['progress'];
    expect($p['indirect'])->toBe(1)->and($p['pending'])->toBeFalse()
        ->and(CommissionAward::where('user_id', $this->user->id)->count())->toBe(0);
    $second = paidChild($this, $middle);
    $fund->execute($this->tenant->id, $second->id, (string) Str::uuid(), '50');
    $claim = DB::table('paid_promotion_rebates')->where('user_id', $this->user->id)->firstOrFail();
    expect($claim->indirect_count)->toBe(2)->and($claim->direct_count)->toBe(1);
});

it('keeps failed automatic returns durable and recovers after expiry without changing funding', function () {
    paidWallet($this, $this->user);
    paidTarget($this, 1, 1);
    $order = paidBuy($this, $this->user, 1);
    $child = paidChild($this, $this->user);
    DB::unprepared("CREATE OR REPLACE FUNCTION test_auto_rebate_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action='PROMOTION_REBATE_AUTO_COMPLETED' THEN RAISE EXCEPTION 'test rollback'; END IF; RETURN NEW; END $$; CREATE TRIGGER test_auto_rebate_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION test_auto_rebate_failure()");
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $claim = DB::table('paid_promotion_rebates')->firstOrFail();
    expect(app(PaidPromotionRebate::class)->attempt($this->tenant->id, $claim->id))->toBeFalse();
    expect(DB::table('paid_promotion_rebates')->where('id', $claim->id)->value('status'))->toBe('PENDING')
        ->and(DB::table('ledger_entries')->where('event_type', 'PROMOTION_FEE_REBATE')->count())->toBe(0)
        ->and(LedgerAccount::where('user_id', $child->id)->where('account_type', 'USER_SECURITY_DEPOSIT')->value('balance'))->toBe('50.00000000');
    DB::unprepared('DROP TRIGGER test_auto_rebate_failure ON audit_logs; DROP FUNCTION test_auto_rebate_failure()');
    $this->travelTo(CarbonImmutable::parse(DB::table('paid_promotion_cycles')->where('id', $order->cycle_id)->value('ends_at'))->addDay());
    $foreign = Tenant::where('slug', 'tenant-b')->firstOrFail();
    expect(app(PaidPromotionRebate::class)->attempt($foreign->id, $claim->id))->toBeFalse();
    $this->artisan('promotion:recover', ['--tenant' => $this->tenant->id])->assertSuccessful();
    $this->artisan('promotion:recover', ['--tenant' => $this->tenant->id])->assertSuccessful();
    expect(DB::table('paid_promotion_rebates')->where('id', $claim->id)->value('status'))->toBe('APPROVED')
        ->and(DB::table('ledger_entries')->where('event_type', 'PROMOTION_FEE_REBATE')->count())->toBe(1);
});

it('uses automatic returns for purchase upgrade and renewal', function () {
    paidWallet($this, $this->user);
    $first = paidBuy($this, $this->user, 1);
    $upgrade = paidBuy($this, $this->user, 2);
    $cycle = DB::table('paid_promotion_cycles')->where('id', $upgrade->cycle_id)->firstOrFail();
    expect($cycle->rebate_policy)->toBe('AUTO_FIRST_FUNDING')->and($upgrade->cycle_id)->toBe($first->cycle_id);
    $this->travelTo(CarbonImmutable::parse($cycle->ends_at));
    $new = paidBuy($this, $this->user, 1);
    expect($new->cycle_id)->not->toBe($cycle->id)
        ->and(DB::table('paid_promotion_cycles')->where('id', $new->cycle_id)->value('rebate_policy'))->toBe('AUTO_FIRST_FUNDING');
});

it('shows direct commission income once in USDT valuation and excludes fee returns from income', function () {
    paidWallet($this, $this->user);
    paidTarget($this, 1, 1);
    paidBuy($this, $this->user, 1);
    $child = paidChild($this, $this->user);
    paidBuy($this, $child, 1);
    $claim = DB::table('paid_promotion_rebates')->where('user_id', $this->user->id)->firstOrFail();
    app(PaidPromotionRebate::class)->settleAutomatic($this->tenant->id, $claim->id);
    $assets = app(AssetOverviewQuery::class);
    $before = $assets->get($this->tenant->id, $this->user->id, []);
    expect($before['cumulativeCommission'])->toBe('300.00000000');
    $after = $assets->get($this->tenant->id, $this->user->id, []);
    expect($after['cumulativeCommission'])->toBe($before['cumulativeCommission'])->and($after['estimate'])->toBe($before['estimate'])
        ->and(collect($after['assets'])->firstWhere('asset', 'USDT')['available'])->toBe('500300.00000000')
        ->and($after['estimate'])->toBe('500300.00000000')
        ->and(app(PromotionQuery::class)->execute($this->tenant->id, $this->user->id, null)['myCommission'])->toBe('300.00000000');
});

it('excludes funding before a new period and at its exclusive expiry boundary', function () {
    paidWallet($this, $this->user);
    paidTarget($this, 1, 1);
    $child = paidChild($this, $this->user);
    $fund = app(FundSecurityDepositAction::class);
    $fund->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $this->travel(1)->seconds();
    $order = paidBuy($this, $this->user, 1);
    $this->tenant->businessSettings()->update(['required_security_deposit_amount' => '100']);
    $fund->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $cycle = DB::table('paid_promotion_cycles')->where('id', $order->cycle_id)->firstOrFail();
    expect(app(PaidPromotionRebate::class)->progress($cycle)['direct'])->toBe(0);
    $second = paidChild($this, $this->user);
    $this->travelTo(CarbonImmutable::parse($cycle->ends_at));
    $fund->execute($this->tenant->id, $second->id, (string) Str::uuid(), '100');
    expect(DB::table('paid_promotion_rebates')->count())->toBe(0)
        ->and(app(PaidPromotionRebate::class)->progress($cycle)['direct'])->toBe(0);
});

it('serializes concurrent automatic recovery without duplicate return postings', function () {
    paidWallet($this, $this->user);
    paidTarget($this, 1, 1);
    paidBuy($this, $this->user, 1);
    $child = paidChild($this, $this->user);
    DB::unprepared("CREATE OR REPLACE FUNCTION test_auto_rebate_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action='PROMOTION_REBATE_AUTO_COMPLETED' THEN RAISE EXCEPTION 'test rollback'; END IF; RETURN NEW; END $$; CREATE TRIGGER test_auto_rebate_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION test_auto_rebate_failure()");
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $claim = DB::table('paid_promotion_rebates')->firstOrFail();
    DB::unprepared('DROP TRIGGER test_auto_rebate_failure ON audit_logs; DROP FUNCTION test_auto_rebate_failure()');
    $tenant = $this->tenant->id;
    $results = racePaidOperations([
        fn () => app(PaidPromotionRebate::class)->settleAutomatic($tenant, $claim->id),
        fn () => app(PaidPromotionRebate::class)->settleAutomatic($tenant, $claim->id),
    ]);
    expect($results)->toBe(['completed', 'completed']);
    expect(DB::table('ledger_entries')->where('event_type', 'PROMOTION_FEE_REBATE')->count())->toBe(1);
});

it('previews quoted upgrade return terms without changing the active period or money', function () {
    paidWallet($this, $this->user);
    paidTarget($this, 1, 1);
    $first = paidBuy($this, $this->user, 1);
    $purchase = app(PaidPromotionPurchase::class);
    $query = app(PaidPromotionQuery::class);
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 8)->value('id');
    $quote = $purchase->quote($this->tenant->id, $this->user->id, $level, (string) Str::uuid());
    $before = LedgerAccount::where('user_id', $this->user->id)->where('account_type', 'USER_AVAILABLE')->value('balance');
    $preview = $query->scopedOrder($this->tenant->id, $this->user->id, $quote->id)['rebatePreview'];
    expect($preview['target'])->toBe(4000)->and($preview['direct'])->toBe(0)
        ->and($preview['remaining'])->toBe('200000.00000000');
    expect($query->benefits($this->tenant->id, $this->user->id)['progress']['target'])->toBe(1)
        ->and(DB::table('paid_promotion_rebates')->count())->toBe(0)
        ->and(LedgerAccount::where('user_id', $this->user->id)->where('account_type', 'USER_AVAILABLE')->value('balance'))->toBe($before);
    $child = paidChild($this, $this->user);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $claim = DB::table('paid_promotion_rebates')->where('cycle_id', $first->cycle_id)->firstOrFail();
    app(PaidPromotionRebate::class)->settleAutomatic($this->tenant->id, $claim->id);
    $preview = $query->scopedOrder($this->tenant->id, $this->user->id, $quote->id)['rebatePreview'];
    expect($preview['direct'])->toBe(1)->and($preview['returned'])->toBe('1000.00000000')
        ->and($preview['remaining'])->toBe('199000.00000000');
    $purchase->confirm($this->tenant->id, $this->user->id, $quote->id);
    expect($query->scopedOrder($this->tenant->id, $this->user->id, $quote->id)['rebatePreview'])->toBeNull();
    $actual = $query->benefits($this->tenant->id, $this->user->id)['progress'];
    expect($actual['target'])->toBe($preview['target'])->and($actual['remaining'])->toBe($preview['remaining']);
});

it('previews a new period from its quote and hides superseded upgrade previews', function () {
    paidWallet($this, $this->user);
    $purchase = app(PaidPromotionPurchase::class);
    $query = app(PaidPromotionQuery::class);
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 1)->value('id');
    $quote = $purchase->quote($this->tenant->id, $this->user->id, $level, (string) Str::uuid());
    $preview = $query->scopedOrder($this->tenant->id, $this->user->id, $quote->id)['rebatePreview'];
    expect($preview['direct'])->toBe(0)->and($preview['target'])->toBe(100)->and($preview['remaining'])->toBe('1000.00000000');
    paidTarget($this, 1, 200);
    expect($query->scopedOrder($this->tenant->id, $this->user->id, $quote->id)['rebatePreview']['target'])->toBe(100);
    paidBuy($this, $this->user, 1);
    expect($query->scopedOrder($this->tenant->id, $this->user->id, $quote->id)['rebatePreview'])->toBeNull();
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 8)->value('id');
    $upgrade = $purchase->quote($this->tenant->id, $this->user->id, $level, (string) Str::uuid());
    paidBuy($this, $this->user, 2);
    expect($query->scopedOrder($this->tenant->id, $this->user->id, $upgrade->id)['rebatePreview'])->toBeNull();
});

it('credits first commission to an unverified users auto-created USDT wallet without permitting spending', function () {
    $tenant = $this->tenant->id;
    $user = $this->user->id;
    expect(Wallet::where('tenant_id', $tenant)->where('user_id', $user)->exists())->toBeFalse();
    $child = paidChild($this, $this->user);
    $fund = app(FundSecurityDepositAction::class);
    $request = (string) Str::uuid();
    $fund->execute($tenant, $child->id, $request, '50');
    $fund->execute($tenant, $child->id, $request, '50');
    $wallet = Wallet::where('tenant_id', $tenant)->where('user_id', $user)->firstOrFail();
    expect(LedgerAccount::where('wallet_id', $wallet->id)->where('account_type', 'USER_AVAILABLE')->value('balance'))->toBe('20.00000000')
        ->and(LedgerAccount::where('user_id', $user)->where('account_type', 'USER_COMMISSION')->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', 'COMMISSION_RECEIPT_WALLET_CREATED')->where('actor_type', 'SYSTEM')->count())->toBe(1)
        ->and(app(KycStatusService::class)->forUser($tenant, $user)->value)->toBe('NOT_SUBMITTED');
    expect(fn () => app(CreateWithdrawalAction::class)->executeWithAddress($tenant, $user, (string) Str::uuid(), 'T'.str_repeat('A', 33), '10', expectedFee: '0'))->toThrow(DomainException::class);
    expect(fn () => app(TransferWalletBalanceAction::class)->execute($tenant, $user, $child->fresh()->account_id, '10', (string) Str::uuid()))->toThrow(DomainException::class);
    expect(app(UserWalletQuery::class)->get($tenant, $user)['transferAvailable'])->toBeFalse();
    $query = app(AssetOverviewQuery::class)->get($tenant, $user, []);
    expect($query['cumulativeCommission'])->toBe('20.00000000')->and($query['estimate'])->toBe('20.00000000')
        ->and(collect($query['assets'])->firstWhere('asset', 'USDT')['activity'][0]['kind'])->toBe('Activation commission');
});

it('credits commissions through own refund pending cancellation completion and re-funding', function () {
    paidWallet($this, $this->user);
    $tenant = $this->tenant->id;
    $fund = app(FundSecurityDepositAction::class);
    $fund->execute($tenant, $this->user->id, (string) Str::uuid(), '50');
    $available = LedgerAccount::where('user_id', $this->user->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $earn = function () use ($tenant, $fund, $available) {
        $before = Money::of($available->fresh()->balance, 'USDT');
        $child = paidChild($this, $this->user);
        $fund->execute($tenant, $child->id, (string) Str::uuid(), '50');
        expect($available->fresh()->balance)->toBe($before->add(Money::of('20', 'USDT'))->amount());
    };
    $refunds = app(RefundSecurityDepositAction::class);
    $refund = $refunds->request($tenant, $this->user->id, (string) Str::uuid());
    $earn();
    $refunds->cancel($tenant, $this->user->id, $refund->id);
    expect($refunds->settle($tenant, $this->user->id, $refund->id)->status)->toBe('CANCELLED');
    $earn();
    $refund = $refunds->request($tenant, $this->user->id, (string) Str::uuid());
    $refunds->settle($tenant, $this->user->id, $refund->id);
    $earn();
    $fund->execute($tenant, $this->user->id, (string) Str::uuid(), '50');
    $earn();
    expect(app(PromotionReportQuery::class)->cumulative($tenant, $this->user->id))->toBe('80.00000000');
    expect(app(CreateWithdrawalAction::class)->executeWithAddress($tenant, $this->user->id, (string) Str::uuid(), 'T'.str_repeat('A', 33), '10', expectedFee: '0'))->not->toBeNull();
});

/** A pre-change balance fixture only; the production path cannot credit retired accounts. */
function developmentCommissionOpening($test): LedgerAccount
{
    if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'card_ui_test') {
        throw new RuntimeException('Opening fixture requires card_ui_test.');
    }
    $source = LedgerAccount::forceCreate(['tenant_id' => $test->tenant->id, 'user_id' => $test->user->id, 'wallet_id' => null,
        'asset_code' => 'USDT', 'account_type' => 'USER_COMMISSION', 'status' => 'ACTIVE']);
    $company = app(CommissionAccounts::class)->company($test->tenant->id);
    DB::statement('ALTER TABLE ledger_postings DISABLE TRIGGER commission_balance_credit_retired');
    try {
        app(LedgerWriter::class)->post(new LedgerPostingPlan($test->tenant->id, 'USDT', 'development_opening:'.$source->id, 'TEST_OPENING', null, null, null, [
            new LedgerPostingInstruction($company->id, Money::of('-16880', 'USDT')),
            new LedgerPostingInstruction($source->id, Money::of('16880', 'USDT')),
        ]));
    } finally {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('ALTER TABLE ledger_postings ENABLE TRIGGER commission_balance_credit_retired');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    return $source->refresh();
}

it('consolidates development commission once with exact evidence and no additional income', function () {
    $source = developmentCommissionOpening($this);
    $action = app(ConsolidateDevelopmentCommission::class);
    $before = DB::table('ledger_postings')->sum('delta');
    $entries = DB::table('ledger_entries')->count();
    $income = app(PromotionReportQuery::class)->cumulative($this->tenant->id, $this->user->id);
    $this->artisan('promotion:consolidate-development-commissions')->assertSuccessful();
    expect(DB::table('ledger_entries')->count())->toBe($entries)->and($source->fresh()->balance)->toBe('16880.00000000');
    $foreign = Tenant::where('id', '<>', $this->tenant->id)->firstOrFail();
    expect(fn () => $action->execute($foreign->id, $source->id))->toThrow(ModelNotFoundException::class);
    $record = $action->execute($this->tenant->id, $source->id);
    expect($action->execute($this->tenant->id, $source->id)->id)->toBe($record->id);
    $activity = app(WalletActivityQuery::class)->get($this->tenant->id, $this->user->id);
    expect($activity)->toHaveCount(1)->and($activity[0]['eventType'])->toBe('COMMISSION_BALANCE_CONSOLIDATED')->and($activity[0]['amount'])->toBe('16880.00000000');
    $this->artisan('promotion:consolidate-development-commissions', ['--execute' => true])->assertSuccessful();
    expect($source->fresh()->balance)->toBe('0.00000000')
        ->and(LedgerAccount::findOrFail($record->destination_account_id)->balance)->toBe('16880.00000000')
        ->and(DB::table('ledger_postings')->sum('delta'))->toBe($before)
        ->and(DB::table('ledger_entries')->count())->toBe($entries + 1)
        ->and(app(PromotionReportQuery::class)->cumulative($this->tenant->id, $this->user->id))->toBe($income)
        ->and(DB::table('audit_logs')->where('action', 'COMMISSION_BALANCE_CONSOLIDATED')->value('actor_type'))->toBe('SYSTEM');
    expect(fn () => DB::transaction(fn () => DB::table('commission_balance_consolidations')->where('id', $record->id)->update(['amount' => '1'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'USDT', 'forbidden:'.Str::uuid(), 'TEST_INVALID', null, null, null, [
        new LedgerPostingInstruction(app(CommissionAccounts::class)->company($this->tenant->id)->id, Money::of('-1', 'USDT')),
        new LedgerPostingInstruction($source->id, Money::of('1', 'USDT')),
    ]))))->toThrow(DomainException::class);
});

it('rolls back development consolidation and created wallet if its audit fails', function () {
    $source = developmentCommissionOpening($this);
    DB::unprepared("CREATE OR REPLACE FUNCTION test_consolidation_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action='COMMISSION_BALANCE_CONSOLIDATED' THEN RAISE EXCEPTION 'test rollback'; END IF; RETURN NEW; END $$; CREATE TRIGGER test_consolidation_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION test_consolidation_failure()");
    $action = app(ConsolidateDevelopmentCommission::class);
    expect(fn () => $action->execute($this->tenant->id, $source->id))->toThrow(QueryException::class);
    expect($source->fresh()->balance)->toBe('16880.00000000')
        ->and(DB::table('commission_balance_consolidations')->count())->toBe(0)
        ->and(Wallet::where('user_id', $this->user->id)->exists())->toBeFalse();
    DB::unprepared('DROP TRIGGER test_consolidation_failure ON audit_logs; DROP FUNCTION test_consolidation_failure()');
    $action->execute($this->tenant->id, $source->id);
    expect($source->fresh()->balance)->toBe('0.00000000');
    app()->detectEnvironment(fn () => 'production');
    try {
        expect(fn () => $action->execute($this->tenant->id, $source->id))->toThrow(RuntimeException::class);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('serializes direct commission retries and creates one recipient wallet', function () {
    $child = paidChild($this, $this->user);
    $tenant = $this->tenant->id;
    $request = (string) Str::uuid();
    $results = racePaidOperations([
        fn () => app(FundSecurityDepositAction::class)->execute($tenant, $child->id, $request, '50'),
        fn () => app(FundSecurityDepositAction::class)->execute($tenant, $child->id, $request, '50'),
    ]);
    expect($results)->toBe(['completed', 'completed'])
        ->and(Wallet::where('tenant_id', $tenant)->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(LedgerAccount::where('user_id', $this->user->id)->where('account_type', 'USER_AVAILABLE')->value('balance'))->toBe('20.00000000');
});

it('serializes development consolidation retries without duplicate money', function () {
    $source = developmentCommissionOpening($this);
    $tenant = $this->tenant->id;
    $results = racePaidOperations([
        fn () => app(ConsolidateDevelopmentCommission::class)->execute($tenant, $source->id),
        fn () => app(ConsolidateDevelopmentCommission::class)->execute($tenant, $source->id),
    ]);
    expect($results)->toBe(['completed', 'completed'])->and(DB::table('commission_balance_consolidations')->count())->toBe(1)
        ->and($source->fresh()->balance)->toBe('0.00000000')
        ->and(LedgerAccount::where('user_id', $this->user->id)->where('account_type', 'USER_AVAILABLE')->value('balance'))->toBe('16880.00000000');
});

it('never provisions a wallet on reads or reactivates an existing disabled receipt wallet', function () {
    $tenant = $this->tenant->id;
    $user = $this->user->id;
    app(PromotionReportQuery::class)->commissions($tenant, $user, []);
    app(AssetOverviewQuery::class)->get($tenant, $user, []);
    expect(DB::table('wallets')->where('user_id', $user)->exists())->toBeFalse();
    paidWallet($this, $this->user);
    $wallet = Wallet::where('user_id', $user)->firstOrFail();
    $wallet->update(['status' => 'SUSPENDED']);
    $child = paidChild($this, $this->user);
    app(FundSecurityDepositAction::class)->execute($tenant, $child->id, (string) Str::uuid(), '50');
    expect($wallet->fresh()->status->value)->toBe('SUSPENDED')
        ->and(LedgerAccount::where('wallet_id', $wallet->id)->where('account_type', 'USER_AVAILABLE')->value('balance'))->toBe('500020.00000000');
});

it('rolls back first receipt wallet rewards and source funding when system wallet audit fails', function () {
    $child = paidChild($this, $this->user);
    $tenant = $this->tenant->id;
    $before = LedgerAccount::orderBy('id')->pluck('balance', 'id')->all();
    DB::unprepared("CREATE OR REPLACE FUNCTION test_receipt_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action='COMMISSION_RECEIPT_WALLET_CREATED' THEN RAISE EXCEPTION 'test rollback'; END IF; RETURN NEW; END $$; CREATE TRIGGER test_receipt_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION test_receipt_failure()");
    $id = (string) Str::uuid();
    expect(fn () => app(FundSecurityDepositAction::class)->execute($tenant, $child->id, $id, '50'))->toThrow(QueryException::class);
    expect(DB::table('wallets')->where('user_id', $this->user->id)->exists())->toBeFalse()
        ->and(DB::table('paid_promotion_shares')->count())->toBe(0)
        ->and(LedgerAccount::orderBy('id')->pluck('balance', 'id')->all())->toBe($before);
    DB::unprepared('DROP TRIGGER test_receipt_failure ON audit_logs; DROP FUNCTION test_receipt_failure()');
    app(FundSecurityDepositAction::class)->execute($tenant, $child->id, $id, '50');
    $fundBook = app(CompanyFundBookQuery::class)->execute($tenant, null, 1);
    $platform = app(PlatformUserQuery::class)->paginate($tenant, $this->user->account_id, null, ['balances' => true, 'commission' => true])->items()[0];
    expect($platform['availableBalance'])->toBe('20.00000000')->and($platform['commission'])->toBe('20.00000000')
        ->and($fundBook['lifetimeTotals']['commissionCost'])->toBe('20.00000000');
});

it('applies the four approved annual commission paths using only eligible covered rates', function (int $payerRank, array $ranks, array $amounts) {
    paidWallet($this, $this->user);
    foreach (DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->orderBy('rank')->get() as $level) {
        app(ConfigurePaidPromotion::class)->execute($this->tenant->id, $this->platform, $level->id, ['fee' => (string) ($level->rank * 1000), 'percent' => $level->percent, 'reward' => $level->reward, 'target' => $level->target, 'revision' => $level->revision, 'enabled' => true]);
    }
    $parent = $this->user;
    $ancestors = [];
    foreach (array_reverse($ranks) as $index => $rank) {
        if ($index > 0) {
            $parent = paidChild($this, $parent);
        }
        paidBuy($this, $parent, $rank);
        array_unshift($ancestors, $parent);
    }
    $payer = paidChild($this, $parent);
    paidBuy($this, $payer, $payerRank - 1);
    $order = paidBuy($this, $payer, $payerRank);
    $event = DB::table('paid_promotion_events')->where('source_id', $order->id)->firstOrFail();
    $shares = DB::table('paid_promotion_shares')->where('event_id', $event->id)->orderBy('depth')->get();
    expect($order->amount)->toBe('1000.00000000')->and($shares->pluck('amount')->all())->toBe($amounts)
        ->and($shares->pluck('user_id')->all())->toBe(array_map(fn ($u) => $u->id, $ancestors))
        ->and(CommissionAward::count())->toBe(0);
})->with([
    [2, [3, 6], ['500.00000000', '300.00000000']],
    [4, [2, 3, 6], ['400.00000000', '0.00000000', '400.00000000']],
    [3, [3], ['500.00000000']],
    [4, [2, 4], ['400.00000000', '200.00000000']],
]);

it('converts a 300 deposit plus 700 wallet payment and returns the full 1000 without recounting', function () {
    paidWallet($this, $this->user);
    paidBuy($this, $this->user, 2);
    $child = paidChild($this, $this->user);
    $this->tenant->businessSettings()->update(['required_security_deposit_amount' => '300']);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '300');
    $activation = DB::table('account_activations')->where('user_id', $child->id)->firstOrFail();
    paidTarget($this, 1, 1);
    $order = paidBuy($this, $child, 1);
    expect($order->deposit_applied)->toBe('300.00000000')->and($order->amount)->toBe('700.00000000')->and($order->settlement_total)->toBe('1000.00000000');
    expect(LedgerAccount::where('user_id', $child->id)->where('account_type', 'USER_SECURITY_DEPOSIT')->value('balance'))->toBe('0.00000000');
    expect(DB::table('ledger_postings')->where('ledger_entry_id', $order->ledger_entry_id)->count())->toBe(3);
    $event = DB::table('paid_promotion_events')->where('source_id', $order->id)->value('id');
    expect(DB::table('paid_promotion_shares')->where('event_id', $event)->value('amount'))->toBe('280.00000000')
        ->and(DB::table('account_activations')->where('user_id', $child->id)->sole()->id)->toBe($activation->id);
    expect(fn () => app(RefundSecurityDepositAction::class)->request($this->tenant->id, $child->id, (string) Str::uuid()))->toThrow(DomainException::class);
    $grandchild = paidChild($this, $child);
    paidBuy($this, $grandchild, 1);
    $claim = DB::table('paid_promotion_rebates')->where('user_id', $child->id)->firstOrFail();
    expect($claim->amount)->toBe('1000.00000000');
    app(PaidPromotionRebate::class)->settleAutomatic($this->tenant->id, $claim->id);
    $upgrade = paidBuy($this, $child, 2);
    expect($upgrade->amount)->toBe('1000.00000000')->and($upgrade->cycle_id)->toBe($order->cycle_id)
        ->and(DB::table('account_activations')->where('user_id', $child->id)->count())->toBe(1);
});

it('keeps excess deposit and accepts zero wallet contribution without annual commission', function () {
    paidWallet($this, $this->user);
    paidBuy($this, $this->user, 2);
    $child = paidChild($this, $this->user);
    $this->tenant->businessSettings()->update(['required_security_deposit_amount' => '1500']);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '1500');
    $order = paidBuy($this, $child, 1);
    expect($order->amount)->toBe('0.00000000')->and($order->deposit_applied)->toBe('1000.00000000');
    expect(LedgerAccount::where('user_id', $child->id)->where('account_type', 'USER_SECURITY_DEPOSIT')->value('balance'))->toBe('500.00000000');
    $event = DB::table('paid_promotion_events')->where('source_id', $order->id)->value('id');
    expect(DB::table('paid_promotion_shares')->where('event_id', $event)->sum('amount'))->toBe('0.00000000')
        ->and(DB::table('ledger_postings')->where('ledger_entry_id', $order->ledger_entry_id)->count())->toBe(2);
});

it('invalidates a deposit quote on funding changes and blocks conversion during refund restoration', function () {
    paidWallet($this, $this->user);
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 1)->value('id');
    $purchase = app(PaidPromotionPurchase::class);
    $quote = $purchase->quote($this->tenant->id, $this->user->id, $level, (string) Str::uuid());
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $this->user->id, (string) Str::uuid(), '50');
    expect(fn () => $purchase->confirm($this->tenant->id, $this->user->id, $quote->id))->toThrow(DomainException::class, 'Promotion terms changed');
    $refund = app(RefundSecurityDepositAction::class);
    $request = $refund->request($this->tenant->id, $this->user->id, (string) Str::uuid());
    expect(fn () => $purchase->quote($this->tenant->id, $this->user->id, $level, (string) Str::uuid()))->toThrow(DomainException::class);
    expect(DB::table('ledger_entries')->where('event_type', 'PROMOTION_ANNUAL_FEE')->count())->toBe(0);
    $refund->cancel($this->tenant->id, $this->user->id, $request->id);
    expect(fn () => $purchase->quote($this->tenant->id, $this->user->id, $level, (string) Str::uuid()))->toThrow(DomainException::class);
    expect($refund->settle($this->tenant->id, $this->user->id, $request->id)->status)->toBe('CANCELLED');
    $fresh = paidBuy($this, $this->user, 1);
    expect($fresh->deposit_applied)->toBe('50.00000000')->and($fresh->amount)->toBe('950.00000000');
});

it('expires agent qualification without freezing cards and never rewards later deposit activation', function () {
    paidWallet($this, $this->user);
    $child = paidChild($this, $this->user);
    $order = paidBuy($this, $child, 1);
    $status = app(AccountActivationStatus::class);
    expect($status->get($this->tenant->id, $child->id)['qualified'])->toBeTrue()
        ->and(app(WalletEligibilityService::class)->forUser($this->tenant->fresh(), $child)['activationSatisfied'])->toBeTrue();
    $ends = DB::table('paid_promotion_cycles')->where('id', $order->cycle_id)->value('ends_at');
    $this->travelTo(CarbonImmutable::parse($ends));
    expect($status->get($this->tenant->id, $child->id)['qualified'])->toBeFalse();
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    expect($status->get($this->tenant->id, $child->id)['qualified'])->toBeTrue()
        ->and(CommissionAward::count())->toBe(0)
        ->and(DB::table('account_activations')->where('user_id', $child->id)->count())->toBe(1)
        ->and(DB::table('account_activations')->where('user_id', $child->id)->value('source_type'))->toBe('ANNUAL');
});

it('serializes the two activation paths to one lifetime fact without duplicate rewards', function () {
    paidWallet($this, $this->user);
    $child = paidChild($this, $this->user);
    $tenant = $this->tenant->id;
    $user = $child->id;
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $tenant)->where('rank', 1)->value('id');
    $quote = app(PaidPromotionPurchase::class)->quote($tenant, $user, $level, (string) Str::uuid());
    $results = racePaidOperations([
        fn () => app(PaidPromotionPurchase::class)->confirm($tenant, $user, $quote->id),
        fn () => app(FundSecurityDepositAction::class)->execute($tenant, $user, (string) Str::uuid(), '50'),
    ]);
    sort($results);
    expect($results)->toBe(['completed', 'rejected'])
        ->and(DB::table('account_activations')->where('user_id', $user)->count())->toBe(1)
        ->and(CommissionAward::count())->toBeLessThanOrEqual(1);
});

it('rolls back mixed settlement on reward failure without changing the existing activation or money', function () {
    paidWallet($this, $this->user);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $this->user->id, (string) Str::uuid(), '50');
    $balances = LedgerAccount::orderBy('id')->pluck('balance', 'id')->all();
    $entries = DB::table('ledger_entries')->count();
    $activation = DB::table('account_activations')->sole();
    DB::unprepared("CREATE FUNCTION test_mixed_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'mixed rollback'; END $$; CREATE TRIGGER test_mixed_failure BEFORE INSERT ON paid_promotion_events FOR EACH ROW EXECUTE FUNCTION test_mixed_failure()");
    expect(fn () => paidBuy($this, $this->user, 1))->toThrow(QueryException::class);
    DB::unprepared('DROP TRIGGER test_mixed_failure ON paid_promotion_events; DROP FUNCTION test_mixed_failure()');
    expect(LedgerAccount::orderBy('id')->pluck('balance', 'id')->all())->toBe($balances)
        ->and(DB::table('ledger_entries')->count())->toBe($entries)
        ->and(DB::table('paid_promotion_cycles')->count())->toBe(0)
        ->and(DB::table('account_activations')->sole()->id)->toBe($activation->id);
});

it('rejects altered activation evidence and foreign user attribution at commit', function () {
    paidWallet($this, $this->user);
    $order = paidBuy($this, $this->user, 1);
    $activation = DB::table('account_activations')->sole();
    expect(fn () => DB::transaction(fn () => DB::table('account_activations')->where('id', $activation->id)->update(['activated_at' => now()->addDay()])))
        ->toThrow(QueryException::class);
    $child = paidChild($this, $this->user);
    expect(fn () => DB::transaction(function () use ($child, $activation) {
        DB::table('account_activations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $child->id,
            'source_type' => 'DEPOSIT', 'source_id' => $activation->ledger_entry_id, 'ledger_entry_id' => $activation->ledger_entry_id, 'activated_at' => $activation->activated_at, 'created_at' => now()]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }))->toThrow(QueryException::class);
    expect(DB::table('account_activations')->count())->toBe(1);
});

it('saves promotion tariffs as one final configuration and rolls back stale or invalid batches', function () {
    $base = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/configuration/paid-promotion/levels';
    $rows = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->orderBy('rank')->get();
    $payload = $rows->map(fn ($row) => [
        'id' => $row->id, 'fee' => (string) BigDecimal::of($row->fee)->plus('1000000'),
        'percent' => $row->percent, 'reward' => $row->reward, 'target' => $row->target,
        'enabled' => $row->enabled, 'revision' => $row->revision,
    ])->all();
    $this->actingAs($this->platform, 'platform_admin')->post($base, ['levels' => $payload])->assertRedirect()->assertSessionHasNoErrors();
    $saved = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->orderBy('rank')->get();
    foreach ($saved as $i => $row) {
        expect($row->fee)->toBe($payload[$i]['fee'])->and($row->revision)->toBe($payload[$i]['revision'] + 1);
    }
    $payload[0]['revision']++;
    $payload[0]['target']++;
    $this->post($base, ['levels' => $payload])->assertSessionHasErrors();
    expect(DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->orderBy('rank')->get()->toJson())->toBe($saved->toJson());
    foreach ($payload as &$row) {
        $row['revision'] = $saved->firstWhere('id', $row['id'])->revision;
    }
    unset($row);
    $payload[0]['fee'] = '999999999999';
    $this->post($base, ['levels' => $payload])->assertSessionHasErrors();
    expect(DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->orderBy('rank')->get()->toJson())->toBe($saved->toJson());
});

it('uses strict weighted cycle thresholds and the dynamic highest enabled rank', function (int $units, array $expected) {
    $policy = app(PromotionUpgradeEligibility::class);
    $cycle = (object) ['rank' => 1, 'tariff' => '1000'];
    $targets = [1 => 100, 2 => 200, 3 => 500, 4 => 1000, 5 => 2000, 6 => 5000, 7 => 10000, 8 => 20000];
    $eligible = [];
    foreach ($targets as $rank => $target) {
        $level = (object) ['rank' => $rank, 'target' => $target, 'fee' => (string) ($rank * 1000), 'enabled' => true];
        if ($policy->decision($cycle, $level, ['weightedUnits' => $units, 'highestEnabledRank' => 8, 'pending' => false])['selectable']) {
            $eligible[] = $rank;
        }
    }
    expect($eligible)->toBe($expected);
})->with([[999, [3, 4, 5, 6, 7, 8]], [1000, [4, 5, 6, 7, 8]], [1002, [4, 5, 6, 7, 8]], [2000, [5, 6, 7, 8]], [40000, [8]], [40001, [8]]]);

it('rechecks new activations before upgrade payment and does not debit an ineligible quote', function () {
    paidWallet($this, $this->user);
    paidBuy($this, $this->user, 1);
    paidTarget($this, 2, 2);
    $child = paidChild($this, $this->user);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $grandchild = paidChild($this, $child);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $grandchild->id, (string) Str::uuid(), '50');
    $query = app(PaidPromotionQuery::class);
    expect($query->benefits($this->tenant->id, $this->user->id)['upgradeEligibility']['weightedCount'])->toBe('1.5');
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 2)->value('id');
    $purchase = app(PaidPromotionPurchase::class);
    $quote = $purchase->quote($this->tenant->id, $this->user->id, $level, (string) Str::uuid());
    $second = paidChild($this, $this->user);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $second->id, (string) Str::uuid(), '50');
    $entries = DB::table('ledger_entries')->count();
    expect(fn () => $purchase->confirm($this->tenant->id, $this->user->id, $quote->id))->toThrow(DomainException::class, 'This level’s target');
    expect(fn () => $purchase->quote($this->tenant->id, $this->user->id, $level, (string) Str::uuid()))->toThrow(DomainException::class, 'This level’s target');
    expect(DB::table('ledger_entries')->count())->toBe($entries);
    $data = $query->execute($this->tenant->id, $this->user->id);
    expect($data['upgradeEligibility']['weightedCount'])->toBe('2.5')->and(collect($data['levels'])->firstWhere('rank', 2)['selectable'])->toBeFalse();
    $this->actingAs($this->user, 'tenant_user')->postJson('http://a.localhost/promotion/quotes', ['level_id' => $level, 'request_id' => (string) Str::uuid()])->assertStatus(409);
    paidTarget($this, 8, 1);
    $topId = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 8)->value('id');
    $topQuote = $purchase->quote($this->tenant->id, $this->user->id, $topId, (string) Str::uuid());
    DB::table('paid_promotion_levels')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'rank' => 9, 'fee' => '300000', 'percent' => 100, 'reward' => 130, 'target' => 50000, 'revision' => 1, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => $purchase->confirm($this->tenant->id, $this->user->id, $topQuote->id))->toThrow(DomainException::class, 'This level’s target');
    expect(DB::table('ledger_entries')->count())->toBe($entries);

    $cycle = DB::table('paid_promotion_cycles')->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->first();
    $this->travelTo(CarbonImmutable::parse($cycle->ends_at));
    expect(paidBuy($this, $this->user, 2)->status)->toBe('COMPLETED');
    expect($query->benefits($this->tenant->id, $this->user->id)['upgradeEligibility']['weightedCount'])->toBe('0');
});

it('supports ninth level purchases rewards reports and configurable highest enabled level', function () {
    $id = (string) Str::uuid();
    DB::table('paid_promotion_levels')->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'rank' => 9, 'fee' => '300000', 'percent' => 100, 'reward' => 130, 'target' => 50000, 'revision' => 1, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    paidWallet($this, $this->user);
    $order = paidBuy($this, $this->user, 9);
    $child = paidChild($this, $this->user);
    paidBuy($this, $child, 9);
    $query = app(PaidPromotionQuery::class);
    $data = $query->execute($this->tenant->id, $this->user->id);
    expect($data['rank'])->toBe(9)->and($data['upgradeEligibility']['highestEnabledRank'])->toBe(9)
        ->and(collect($data['tables']['ANNUAL'])->firstWhere('rank', 9)['direct']['amount'])->toBe('300000.00000000');
    expect(PromotionRanks::forTenant($this->tenant->id))->toContain(9);
    $level = DB::table('paid_promotion_levels')->where('id', $id)->first();
    app(ConfigurePaidPromotion::class)->execute($this->tenant->id, $this->platform, $id, ['fee' => $level->fee, 'percent' => $level->percent, 'reward' => $level->reward, 'target' => $level->target, 'revision' => $level->revision, 'enabled' => false]);
    expect($query->benefits($this->tenant->id, $this->user->id)['upgradeEligibility']['highestEnabledRank'])->toBe(8);
    $entries = DB::table('ledger_entries')->count();
    app(PaidPromotionPurchase::class)->confirm($this->tenant->id, $this->user->id, $order->id);
    expect(DB::table('ledger_entries')->count())->toBe($entries);
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/promotion/commissions?rank=9')->assertOk();
});

it('keeps highest-rank exceptions scoped to enabled configuration and preserves all other upgrade gates', function () {
    $policy = app(PromotionUpgradeEligibility::class);
    $cycle = (object) ['rank' => 1, 'tariff' => '1000'];
    $level = (object) ['rank' => 7, 'fee' => '100000', 'target' => 1, 'enabled' => true];
    $context = ['weightedUnits' => 50000, 'highestEnabledRank' => 7, 'pending' => false];
    expect($policy->decision($cycle, $level, $context)['selectable'])->toBeTrue();
    expect($policy->decision($cycle, $level, array_replace($context, ['highestEnabledRank' => 9]))['selectable'])->toBeFalse();
    expect($policy->decision($cycle, $level, array_replace($context, ['pending' => true]))['unavailableCode'])->toBe('PROMOTION_REBATE_PENDING');
    $level->fee = '1000';
    expect($policy->decision($cycle, $level, $context)['selectable'])->toBeFalse();
    $level->fee = '100000';
    $level->enabled = false;
    expect($policy->decision($cycle, $level, $context)['selectable'])->toBeFalse();
    $levels = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->get();
    app(ConfigurePaidPromotion::class)->batch($this->tenant->id, $this->platform, $levels->map(fn ($l) => ['id' => $l->id, 'revision' => $l->revision, 'fee' => $l->fee, 'percent' => $l->percent, 'reward' => $l->reward, 'target' => $l->target, 'enabled' => false])->all());
    expect($policy->context($this->tenant->id, null)['highestEnabledRank'])->toBe(0);
    expect(collect(app(PaidPromotionQuery::class)->benefits($this->tenant->id, $this->user->id)['levels'])->where('selectable', true))->toHaveCount(0);
});
