<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Promotion\CompanyFundBookQuery;
use App\Application\Promotion\ConfigurePaidPromotion;
use App\Application\Promotion\PaidPromotionPurchase;
use App\Application\Promotion\PaidPromotionQuery;
use App\Application\Promotion\PaidPromotionRebate;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\SecurityDeposit\RefundSecurityDepositAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Application\Withdrawal\CreateWithdrawalAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Promotion\Models\CommissionAward;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

it('rebates only paid unreturned fees and allows later upgrade difference without extending expiry', function () {
    paidWallet($this, $this->user);
    paidTarget($this, 1, 1);
    paidTarget($this, 2, 1);
    $first = paidBuy($this, $this->user, 1);
    $ends = DB::table('paid_promotion_cycles')->value('ends_at');
    $child = paidChild($this, $this->user);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $rebates = app(PaidPromotionRebate::class);
    $claim = $rebates->apply($this->tenant->id, $this->user->id, (string) Str::uuid());
    expect($claim->amount)->toBe('1000.00000000');
    $rebates->review($this->tenant->id, $claim->id, $this->platform, true, null);
    $rebates->review($this->tenant->id, $claim->id, $this->platform, true, null);
    $second = paidBuy($this, $this->user, 2);
    expect($second->amount)->toBe('1000.00000000')->and($second->cycle_id)->toBe($first->cycle_id)->and(DB::table('paid_promotion_cycles')->value('ends_at'))->toBe($ends);
    $next = $rebates->apply($this->tenant->id, $this->user->id, (string) Str::uuid());
    expect($next->amount)->toBe('1000.00000000');
    $rebates->review($this->tenant->id, $next->id, $this->platform, true, 'Verified');
    $p = app(PaidPromotionQuery::class)->execute($this->tenant->id, $this->user->id);
    expect($p['progress']['returned'])->toBe('2000.00000000')->and($p['progress']['remaining'])->toBe('0.00000000')->and($p['rank'])->toBe(2);
    expect(DB::table('ledger_entries')->where('event_type', 'PROMOTION_FEE_REBATE')->count())->toBe(2);
    expect(DB::table('audit_logs')->where('action', 'PROMOTION_REBATE_APPROVED')->where('actor_id', $this->platform->id)->count())->toBe(2);
});

it('blocks upgrade during review then permits it after withdrawal and preserves rejected history', function () {
    paidWallet($this, $this->user);
    paidTarget($this, 1, 1);
    paidBuy($this, $this->user, 1);
    $child = paidChild($this, $this->user);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $r = app(PaidPromotionRebate::class);
    $claim = $r->apply($this->tenant->id, $this->user->id, (string) Str::uuid());
    expect(fn () => paidBuy($this, $this->user, 2))->toThrow(DomainException::class);
    expect(fn () => $r->review($this->tenant->id, $claim->id, $this->admin, true, null))->toThrow(HttpException::class);
    $r->review($this->tenant->id, $claim->id, $this->platform, false, 'Evidence reviewed');
    $new = $r->apply($this->tenant->id, $this->user->id, (string) Str::uuid());
    $r->withdraw($this->tenant->id, $this->user->id, $new->id);
    $r->withdraw($this->tenant->id, $this->user->id, $new->id);
    expect(paidBuy($this, $this->user, 2)->amount)->toBe('1000.00000000');
});

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

it('uses calendar years and accepts a timely rebate review after period expiry', function () {
    $this->travelTo(CarbonImmutable::parse('2028-02-29 10:00:00', 'Asia/Kuala_Lumpur'));
    paidWallet($this, $this->user);
    paidTarget($this, 1, 1);
    $order = paidBuy($this, $this->user, 1);
    $cycle = DB::table('paid_promotion_cycles')->where('id', $order->cycle_id)->first();
    expect(CarbonImmutable::parse($cycle->ends_at)->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i:s'))->toBe('2029-02-28 10:00:00');
    $child = paidChild($this, $this->user);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $claim = app(PaidPromotionRebate::class)->apply($this->tenant->id, $this->user->id, (string) Str::uuid());
    $this->travelTo(CarbonImmutable::parse($cycle->ends_at));
    expect(app(PaidPromotionQuery::class)->execute($this->tenant->id, $this->user->id)['rank'])->toBe(0);
    app(PaidPromotionRebate::class)->review($this->tenant->id, $claim->id, $this->platform, true, null);
    expect(fn () => app(PaidPromotionRebate::class)->apply($this->tenant->id, $this->user->id, (string) Str::uuid()))->toThrow(DomainException::class);
    $new = paidBuy($this, $this->user, 2);
    expect($new->cycle_id)->not->toBe($order->cycle_id)->and($new->amount)->toBe('2000.00000000');
});

it('counts genuine repeated deposit funding and all indirect depths without duplicated identities', function () {
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
    $p = app(PaidPromotionQuery::class)->execute($this->tenant->id, $this->user->id);
    expect($p['indirectPeople'])->toBe(1)->and($p['progress']['indirect'])->toBe(2)->and($p['progress']['direct'])->toBe(0);
    expect(fn () => app(PaidPromotionRebate::class)->apply($this->tenant->id, $this->user->id, (string) Str::uuid()))->toThrow(DomainException::class);
    $fund->execute($this->tenant->id, $middle->id, (string) Str::uuid(), '50');
    expect(app(PaidPromotionRebate::class)->apply($this->tenant->id, $this->user->id, (string) Str::uuid())->amount)->toBe('1000.00000000');
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

it('serializes duplicate rebate approvals and upgrade races with a pending review', function () {
    paidWallet($this, $this->user);
    paidTarget($this, 1, 1);
    paidBuy($this, $this->user, 1);
    $child = paidChild($this, $this->user);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $claim = app(PaidPromotionRebate::class)->apply($this->tenant->id, $this->user->id, (string) Str::uuid());
    $tenant = $this->tenant->id;
    $actor = $this->platform;
    $results = racePaidOperations([fn () => app(PaidPromotionRebate::class)->review($tenant, $claim->id, $actor, true, null), fn () => app(PaidPromotionRebate::class)->review($tenant, $claim->id, $actor, true, null)]);
    expect($results)->toBe(['completed', 'completed'])->and(DB::table('ledger_entries')->where('event_type', 'PROMOTION_FEE_REBATE')->count())->toBe(1);
    expect(DB::table('audit_logs')->where('action', 'PROMOTION_REBATE_APPROVED')->count())->toBe(1);
});

it('keeps direct awards for lower inviters and excludes same or lower indirect levels', function () {
    paidWallet($this, $this->user);
    paidBuy($this, $this->user, 1);
    $higher = paidChild($this, $this->user);
    paidBuy($this, $higher, 8);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $higher->id, (string) Str::uuid(), '50');
    expect(CommissionAward::where('user_id', $this->user->id)->sum('amount'))->toBe('50.00000000');
    $same = paidChild($this, $higher);
    paidBuy($this, $same, 8);
    $leaf = paidChild($this, $same);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $leaf->id, (string) Str::uuid(), '50');
    expect(CommissionAward::where('user_id', $same->id)->sum('amount'))->toBe('120.00000000');
    expect(CommissionAward::where('user_id', $higher->id)->count())->toBe(0)->and(CommissionAward::where('user_id', $this->user->id)->count())->toBe(1);
});

it('enforces HTTP ownership passwords platform scope and dedicated review permission', function () {
    paidWallet($this, $this->user);
    $order = paidBuy($this, $this->user, 1);
    $otherTenant = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $other = User::where('tenant_id', $otherTenant->id)->firstOrFail();
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/promotion/membership')->assertOk();
    $this->postJson('http://a.localhost/promotion/quotes/'.$order->id.'/confirm', ['current_password' => 'wrong', 'confirmed' => true])->assertUnprocessable();
    $this->actingAs($other, 'tenant_user')->get('http://b.localhost/promotion/membership?order='.$order->id)->assertNotFound();
    $base = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/configuration/paid-promotion';
    $this->actingAs($this->platform, 'platform_admin')->get($base)->assertOk();
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->first();
    $this->postJson($base.'/levels/'.$level->id, ['fee' => $level->fee, 'reward' => $level->reward, 'percent' => $level->percent, 'target' => $level->target, 'enabled' => true, 'revision' => $level->revision, 'current_password' => 'wrong'])->assertUnprocessable();
    $permission = DB::table('permissions')->where('name', 'promotion_refunds.review')->value('id');
    DB::table('role_permissions')->where('permission_id', $permission)->delete();
    $this->postJson($base.'/rebates/'.Str::uuid(), ['decision' => 'approve', 'current_password' => 'local-password', 'confirmed' => true])->assertForbidden();
});

it('uses all eight exact upgrade tariffs and preserves mixed historical activation prices', function () {
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
    expect($row['count'])->toBe(2)->and($row['amount'])->toBe('170.00000000')->and($row['minimum'])->toBe('50.00000000')->and($row['maximum'])->toBe('120.00000000');
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

it('rolls back fee rebate and audit atomically when recording the review fails', function () {
    paidWallet($this, $this->user);
    paidTarget($this, 1, 1);
    paidBuy($this, $this->user, 1);
    $child = paidChild($this, $this->user);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $r = app(PaidPromotionRebate::class);
    $claim = $r->apply($this->tenant->id, $this->user->id, (string) Str::uuid());
    $before = LedgerAccount::where('user_id', $this->user->id)->where('account_type', 'USER_AVAILABLE')->value('balance');
    DB::unprepared("CREATE OR REPLACE FUNCTION test_rebate_audit_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action='PROMOTION_REBATE_APPROVED' THEN RAISE EXCEPTION 'test rollback'; END IF; RETURN NEW; END $$; CREATE TRIGGER test_rebate_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION test_rebate_audit_failure()");
    expect(fn () => $r->review($this->tenant->id, $claim->id, $this->platform, true, null))->toThrow(QueryException::class);
    DB::unprepared('DROP TRIGGER test_rebate_audit_failure ON audit_logs; DROP FUNCTION test_rebate_audit_failure()');
    expect(DB::table('paid_promotion_rebates')->where('id', $claim->id)->value('status'))->toBe('PENDING');
    expect(LedgerAccount::where('user_id', $this->user->id)->where('account_type', 'USER_AVAILABLE')->value('balance'))->toBe($before);
    expect(DB::table('ledger_entries')->where('event_type', 'PROMOTION_FEE_REBATE')->count())->toBe(0);
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

it('serializes rebate review against a previously quoted upgrade without losing the paid basis', function () {
    paidWallet($this, $this->user);
    paidTarget($this, 1, 1);
    paidBuy($this, $this->user, 1);
    $child = paidChild($this, $this->user);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $tenant = $this->tenant->id;
    $user = $this->user->id;
    $actor = $this->platform;
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $tenant)->where('rank', 2)->value('id');
    $q = app(PaidPromotionPurchase::class)->quote($tenant, $user, $level, (string) Str::uuid());
    $claim = app(PaidPromotionRebate::class)->apply($tenant, $user, (string) Str::uuid());
    $results = racePaidOperations([fn () => app(PaidPromotionRebate::class)->review($tenant, $claim->id, $actor, true, null), fn () => app(PaidPromotionPurchase::class)->confirm($tenant, $user, $q->id)]);
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
