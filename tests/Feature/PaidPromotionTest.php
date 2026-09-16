<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Promotion\CompanyFundBookQuery;
use App\Application\Promotion\ConfigurePaidPromotion;
use App\Application\Promotion\PaidPromotionPurchase;
use App\Application\Promotion\PaidPromotionQuery;
use App\Application\Promotion\PaidPromotionRebate;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\Promotion\PromotionReportQuery;
use App\Application\Promotion\TransferCommissionAction;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
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
    paidBuy($this, $child, 1);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $query = app(PromotionReportQuery::class);
    $income = $query->commissions($this->tenant->id, $this->user->id, []);
    expect($income['items'])->toHaveCount(2)->and($income['totals']['annual'])->toBe('1000.00000000')
        ->and($income['totals']['activation'])->toBe('120.00000000')->and($income['totals']['legacy'])->toBe('0')
        ->and($income['totals']['total'])->toBe('1120.00000000');
    $daily = $query->daily($this->tenant->id, $this->user->id, []);
    expect($daily['items'])->toHaveCount(3)->and($daily['counts'])->toBe(['invited' => 1, 'funded' => 1, 'orders' => 1]);
    expect(collect($daily['items'])->firstWhere('kind', 'activation')['firstFunding'])->toBeTrue();
    $filtered = $query->daily($this->tenant->id, $this->user->id, ['activity' => 'annual']);
    expect($filtered['items'])->toHaveCount(1)->and($filtered['items'][0]['purchaseKind'])->toBe('purchase')
        ->and($filtered['totals'])->toBe($daily['totals'])->and($filtered['counts'])->toBe($daily['counts']);
    $members = $query->members($this->tenant->id, $this->user->id, ['rank' => '1', 'funding' => 'funded']);
    expect($members['items'])->toHaveCount(1)->and($members['items'][0]['totals']['total'])->toBe('1120.00000000');
    paidBuy($this, $child, 2);
    expect($query->members($this->tenant->id, $this->user->id, [])['items'][0]['rank'])->toBe(2)
        ->and($query->commissions($this->tenant->id, $this->user->id, ['rank' => '1'])['items'])->toHaveCount(2);
    expect(array_column($query->daily($this->tenant->id, $this->user->id, ['activity' => 'annual'])['items'], 'purchaseKind'))->toContain('upgrade');
    $this->travelTo(CarbonImmutable::parse('2027-09-17 12:00:00 UTC'));
    expect($query->members($this->tenant->id, $this->user->id, ['rank' => '0'])['items'])->toHaveCount(1)
        ->and($query->commissions($this->tenant->id, $this->user->id, ['rank' => '1'])['items'])->toHaveCount(2);
    paidBuy($this, $child, 1);
    expect($query->daily($this->tenant->id, $this->user->id, ['activity' => 'annual'])['items'][0]['purchaseKind'])->toBe('renewal');
});

it('keeps zero commission annual payments visible and separates transfers from earned income', function () {
    paidWallet($this, $this->user);
    $child = paidChild($this, $this->user);
    paidBuy($this, $child, 1);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $query = app(PromotionReportQuery::class);
    $daily = $query->daily($this->tenant->id, $this->user->id, ['activity' => 'annual']);
    expect($daily['items'])->toHaveCount(1)->and((string) $daily['items'][0]['amount'])->toBe('0');
    app(TransferCommissionAction::class)->execute($this->tenant->id, $this->user->id, (string) Str::uuid());
    $history = $query->commissions($this->tenant->id, $this->user->id, []);
    expect($history['items'])->toHaveCount(1)->and($history['totals']['total'])->toBe('20.00000000');
    $transfer = $query->commissions($this->tenant->id, $this->user->id, ['tab' => 'transfers', 'rank' => '8', 'kind' => 'annual']);
    expect($transfer['items'])->toHaveCount(1)->and($transfer['totals']['total'])->toBe('20.00000000')->and($transfer['filters'])->not->toHaveKey('rank');
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
        ->and($second['totals']['activation'])->toBe('30.00000000');
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
    $child = paidChild($this, $this->user);
    $fund = app(FundSecurityDepositAction::class);
    for ($n = 1; $n <= 31; $n++) {
        $this->tenant->businessSettings()->update(['required_security_deposit_amount' => (string) ($n * 50)]);
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
    expect($members['items'])->toHaveCount(20)->and($members['total'])->toBe(21)->and($members['hasMore'])->toBeTrue()
        ->and($queries)->toBeLessThanOrEqual(5)
        ->and($query->members($this->tenant->id, $this->user->id, ['page' => 2])['items'])->toHaveCount(1)
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
