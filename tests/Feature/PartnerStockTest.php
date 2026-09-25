<?php

use App\Application\Assets\AssetAccess;
use App\Application\Assets\WithdrawAssetsAction;
use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Partners\FeeValuation;
use App\Application\Partners\PartnerManagement;
use App\Application\Partners\PartnerReport;
use App\Application\Promotion\ConfigurePaidPromotion;
use App\Application\Promotion\PaidPromotionPurchase;
use App\Application\Promotion\PaidPromotionRebate;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetRail;
use App\Domain\Assets\CompanyRail;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserProfile;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    $this->seed();
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('private');
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->tenant->businessSettings()->update(['required_security_deposit_amount' => '50']);
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->admin = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->management = app(PartnerManagement::class);
    $this->report = app(PartnerReport::class);
    app(PromotionMembershipAction::class)->ensure($this->tenant->id, $this->user->id);
});

function stockChild(User $parent): User
{
    $member = app(PromotionMembershipAction::class)->ensure($parent->tenant_id, $parent->id);
    $user = $parent->replicate(['account_id']);
    $user->forceFill(['email' => Str::uuid().'@example.test'])->save();
    app(PromotionMembershipAction::class)->ensure($parent->tenant_id, $user->id, $member->id);

    return $user->refresh();
}
function stockPartner($test, User $user, bool $enabled = true): object
{
    return $test->management->configure($test->admin, $user->tenant_id, ['account_id' => $user->account_id, 'enabled' => $enabled, 'share_percent' => '40']);
}
function stockEntry(string $kind, string $amount): array
{
    return ['kind' => $kind, 'amount' => $amount, 'business_date' => now()->format('Y-m-d'), 'note' => 'Offline fixture expense', 'request_id' => (string) Str::uuid()];
}

function stockFundWallet($test, User $user): void
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

function stockBuy($test, User $user, int $rank): void
{
    $id = DB::table('paid_promotion_levels')->where('tenant_id', $test->tenant->id)->where('rank', $rank)->value('id');
    $action = app(PaidPromotionPurchase::class);
    $quote = $action->quote($test->tenant->id, $user->id, $id, (string) Str::uuid());
    $action->confirm($test->tenant->id, $user->id, $quote->id);
}

it('hides and denies the report unless the current tenant user is an enabled partner', function () {
    $url = 'http://a.localhost/promotion/stock';
    $this->actingAs($this->user, 'tenant_user')->getJson($url)->assertNotFound();
    $this->get('http://a.localhost/promotion/daily')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canViewStock', false)->missing('stock'));
    $partner = stockPartner($this, $this->user);
    $this->getJson($url)->assertOk()->assertJsonPath('stock', '0.00000000')->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson($url.'?user_id='.Str::uuid())->assertUnprocessable();
    stockPartner($this, $this->user, false);
    $this->getJson($url)->assertNotFound();
    expect(DB::table('partner_configurations')->where('id', $partner->id)->exists())->toBeTrue();
    $tenantAdmin = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    expect(fn () => $this->management->configure($tenantAdmin, $this->tenant->id, ['account_id' => $this->user->account_id, 'enabled' => true, 'share_percent' => 40]))->toThrow(HttpException::class);
    $other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    expect(fn () => $this->management->configure($this->admin, $other->id, ['account_id' => $this->user->account_id, 'enabled' => true, 'share_percent' => 40]))->toThrow(ModelNotFoundException::class);
    Http::assertNothingSent();
});

it('rolls up nested journals once with immutable reversals independent advances and idempotent writes', function () {
    $parent = stockPartner($this, $this->user);
    $child = stockChild($this->user);
    $nested = stockPartner($this, $child);
    $outside = stockChild($this->user);
    $other = stockPartner($this, $outside);
    $data = stockEntry('REIMBURSEMENT', '30');
    $entry = $this->management->journal($this->admin, $this->tenant->id, $nested->id, $data);
    expect($this->management->journal($this->admin, $this->tenant->id, $nested->id, $data)->id)->toBe($entry->id);
    expect(fn () => $this->management->journal($this->admin, $this->tenant->id, $nested->id, array_replace($data, ['amount' => '31'])))->toThrow(HttpException::class);
    $this->management->journal($this->admin, $this->tenant->id, $nested->id, stockEntry('ADVANCE', '80'));
    $this->management->journal($this->admin, $this->tenant->id, $other->id, stockEntry('REIMBURSEMENT', '12'));
    $first = $this->report->read($this->tenant->id, $this->user->id);
    expect($first['stock'])->toBe('-42.00000000')->and($first['share'])->toBe('-16.80000000')->and($first['totals']['advances'])->toBe('80.00000000')->and($first['negative'])->toBeTrue();
    expect($this->report->read($this->tenant->id, $child->id)['stock'])->toBe('-30.00000000');
    $reversal = stockEntry('REIMBURSEMENT', '30') + ['reverses_id' => $entry->id];
    $this->management->journal($this->admin, $this->tenant->id, $nested->id, $reversal);
    $this->management->journal($this->admin, $this->tenant->id, $nested->id, stockEntry('REIMBURSEMENT', '20'));
    expect($this->report->read($this->tenant->id, $this->user->id)['stock'])->toBe('-32.00000000');
    stockPartner($this, $child, false);
    expect($this->report->read($this->tenant->id, $this->user->id)['stock'])->toBe('-32.00000000');
    $before = DB::table('ledger_entries')->count();
    for ($i = 0; $i < 21; $i++) {
        $this->management->journal($this->admin, $this->tenant->id, $parent->id, stockEntry('ADVANCE', '1'));
    }
    expect($this->report->read($this->tenant->id, $this->user->id)['journal']['hasMore'])->toBeTrue()->and(DB::table('ledger_entries')->count())->toBe($before);
    expect(DB::table('partner_journal_entries')->where('id', $entry->id)->value('actor_id'))->toBe($this->admin->id);
    expect(fn () => DB::transaction(fn () => DB::table('partner_journal_entries')->where('id', $entry->id)->update(['amount' => '40'])))->toThrow(QueryException::class);
    Http::assertNothingSent();
});

it('uses posted source commissions including outside ancestors and annual fees including deposit conversion', function () {
    stockFundWallet($this, $this->user);
    stockBuy($this, $this->user, 8);
    $partner = stockChild($this->user);
    stockPartner($this, $partner);
    stockFundWallet($this, $partner);
    $child = stockChild($partner);
    stockFundWallet($this, $child);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $r = $this->report->read($this->tenant->id, $partner->id);
    $cost = DB::table('paid_promotion_shares as s')->join('paid_promotion_events as e', 'e.id', '=', 's.event_id')->where('e.user_id', $child->id)->where('e.kind', 'ACTIVATION')->sum('s.amount');
    expect($r['totals']['deposits'])->toBe('50.00000000')->and($r['totals']['activation'])->toBe((string) BigDecimal::of((string) $cost)->toScale(8));
    expect((float) $cost)->toBeGreaterThan(20);
    stockBuy($this, $child, 1);
    $order = DB::table('paid_promotion_orders')->where('user_id', $child->id)->where('status', 'COMPLETED')->first();
    expect((float) $order->deposit_applied)->toBe(50.0);
    $before = DB::table('ledger_entries')->count();
    $r = $this->report->read($this->tenant->id, $partner->id);
    expect($r['totals']['annual'])->toBe($order->settlement_total)->and($r['totals']['deposits'])->toBe('0.00000000')->and($r['trends']['deposits']['today'])->toBe('50.00000000')->and(DB::table('ledger_entries')->count())->toBe($before);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

function stockWithdraw($test, string $asset, string $percent, bool $marketFails = false)
{
    $code = $asset.'_ETHEREUM';
    $rail = AssetRail::findOrFail($code);
    $address = '0x'.str_repeat('2', 40);
    $rail->update(['enabled' => true, 'deposit_address' => $address]);
    CompanyRail::updateOrCreate(['tenant_id' => $test->tenant->id, 'rail_code' => $code], ['withdrawal_enabled' => true, 'withdrawal_fee_percent' => $percent]);
    $access = app(AssetAccess::class);
    DB::transaction(function () use ($access, $test, $asset) {
        $wallet = $access->wallet($test->tenant, $test->user, $asset);
        $account = $access->account($wallet, 'USER_AVAILABLE');
        $clearing = $access->companyAccount($test->tenant->id, $asset, 'TENANT_TOPUP_CLEARING');
        app(LedgerWriter::class)->post(new LedgerPostingPlan($test->tenant->id, $asset, 'stock-test:'.Str::uuid(), 'TEST_DEPOSIT', null, null, null, [new LedgerPostingInstruction($account->id, Money::of('100', $asset)), new LedgerPostingInstruction($clearing->id, Money::of('-100', $asset))]));
    });
    $action = app(WithdrawAssetsAction::class);
    $fee = $percent === '0' ? '0' : '1';
    $order = $action->create($test->tenant->id, $test->user->id, $code, '10', $address, $fee, (string) Str::uuid(), true);
    $action->review($test->tenant->id, $order->id, $test->admin, true);
    $hash = '0x'.hash('sha256', $order->id);
    $blockHash = '0x'.str_repeat('a', 64);
    $net = $fee === '0' ? '10' : '9';
    $tx = ['hash' => $hash, 'from' => '0x'.str_repeat('3', 40), 'to' => $address, 'value' => '0x'.BigDecimal::of($asset === 'ETH' ? $net : '0')->withPointMovedRight(18)->toBigInteger()->toBase(16)];
    $block = ['number' => '0x64', 'hash' => $blockHash, 'timestamp' => '0x'.dechex(now()->timestamp), 'transactions' => [$tx]];
    $logs = $asset === 'ETH' ? [] : [['address' => $rail->contract, 'logIndex' => '0x0', 'topics' => ['0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef', '0x'.str_repeat('0', 64), '0x'.str_repeat('0', 24).substr($address, 2)], 'data' => '0x'.str_pad(BigDecimal::of($net)->withPointMovedRight(6)->toBigInteger()->toBase(16), 64, '0', STR_PAD_LEFT)]];
    Http::fake(function ($request) use ($marketFails, $block, $blockHash, $hash, $logs, $tx) {
        if (str_contains($request->url(), 'okx.com')) {
            if ($marketFails) {
                return Http::response([], 503);
            }

            return Http::response(['code' => '0', 'data' => array_map(fn ($a, $rate) => ['instType' => 'SPOT', 'instId' => $a.'-USDT', 'last' => $rate, 'ts' => (string) now()->getTimestampMs()], ['USDC', 'ETH', 'BTC'], ['1.01', '2000', '60000'])]);
        }

        return Http::response(['result' => match ($request['method']) {
            'eth_chainId' => '0x1','eth_getBlockByNumber' => $block,
            'debug_traceBlockByNumber' => [['txHash' => $hash, 'result' => ['type' => 'CALL', 'from' => $tx['from'], 'to' => $tx['to'], 'value' => $tx['value']]]],
            'eth_getTransactionReceipt' => ['status' => '0x1', 'blockNumber' => '0x64', 'blockHash' => $blockHash, 'transactionHash' => $hash, 'logs' => $logs],
            default => throw new RuntimeException('Unexpected RPC'),
        }]);
    });
    $test->travel(1)->seconds(); // Finalized block times have whole-second precision.
    $result = $action->verify($test->tenant->id, $order->id, $test->admin, $hash, (string) Str::uuid());

    return [$result, $hash];
}

it('fixes successful fee valuations and never changes them on report reads or withdrawal replays', function () {
    stockFundWallet($this, $this->user);
    stockPartner($this, $this->user);
    // Keep order creation and synthetic receipt times at exact seconds.
    $this->travelTo(now()->startOfSecond());
    [$order,$hash] = stockWithdraw($this, 'USDC', '10');
    expect($order->status)->toBe('COMPLETED');
    $v = DB::table('withdrawal_fee_valuations')->where('withdrawal_id', $order->id)->first();
    expect($v->usdt_amount)->toBe('1.01000000')->and($v->source)->toBe('OKX');
    $r = $this->report->read($this->tenant->id, $this->user->id);
    expect($r['totals']['fees'])->toBe('1.01000000')->and($r['stock'])->toBe('1.01000000');
    $sent = count(Http::recorded());
    $this->travel(1)->days();
    $this->report->read($this->tenant->id, $this->user->id);
    app(WithdrawAssetsAction::class)->verify($this->tenant->id, $order->id, $this->admin, $hash, (string) Str::uuid());
    expect(count(Http::recorded()))->toBe($sent)->and(DB::table('withdrawal_fee_valuations')->count())->toBe(1);
    expect(fn () => DB::transaction(fn () => DB::table('withdrawal_fee_valuations')->where('id', $v->id)->update(['rate' => '2'])))->toThrow(QueryException::class);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('settles even when rates fail and hides partial totals until an audited fixed rate is supplied', function () {
    stockFundWallet($this, $this->user);
    stockPartner($this, $this->user);
    $this->travelTo(now()->startOfSecond());
    [$order] = stockWithdraw($this, 'ETH', '10', true);
    expect($order->status)->toBe('COMPLETED');
    $r = $this->report->read($this->tenant->id, $this->user->id);
    expect($r['stock'])->toBeNull()->and($r['share'])->toBeNull()->and($r['missingRates'])->toBe(1);
    $v = DB::table('withdrawal_fee_valuations')->where('withdrawal_id', $order->id)->first();
    $data = ['rate' => '1900.12345678', 'observed_at' => now()->toIso8601String(), 'evidence' => 'Synthetic public quote reference', 'request_id' => (string) Str::uuid()];
    $count = DB::table('ledger_entries')->count();
    app(FeeValuation::class)->supplement($this->admin, $this->tenant->id, $v->id, $data);
    app(FeeValuation::class)->supplement($this->admin, $this->tenant->id, $v->id, $data);
    $r = $this->report->read($this->tenant->id, $this->user->id);
    expect($r['stock'])->toBe('1900.12345678')->and($r['share'])->toBe('760.04938271')->and(DB::table('ledger_entries')->count())->toBe($count);
    expect(DB::table('withdrawal_fee_valuations')->where('id', $v->id)->value('actor_id'))->toBe($this->admin->id);
    expect(fn () => app(FeeValuation::class)->supplement($this->admin, $this->tenant->id, $v->id, array_replace($data, ['rate' => '2000'])))->toThrow(HttpException::class);
});

it('needs no external rate for zero fees or USDT', function () {
    expect(app(FeeValuation::class)->quote('BTC', '0')['source'])->toBe('PARITY');
    expect(app(FeeValuation::class)->quote('USDT', '5')['rate'])->toBe('1');
    Http::assertNothingSent();
});

it('calculates natural day averages excluding today and counts zero days', function () {
    $this->tenant->update(['timezone' => 'Asia/Kuala_Lumpur']);
    stockPartner($this, $this->user);
    $this->travelTo(CarbonImmutable::parse('2026-09-24T15:59:00Z'));
    $child = stockChild($this->user);
    stockFundWallet($this, $child);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    stockBuy($this, $child, 1);
    $annual = DB::table('paid_promotion_orders')->where('user_id', $child->id)->where('status', 'COMPLETED')->value('settlement_total');
    $this->travelTo(CarbonImmutable::parse('2026-09-24T16:01:00Z'));
    $second = stockChild($this->user);
    stockFundWallet($this, $second);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $second->id, (string) Str::uuid(), '50');
    $r = $this->report->read($this->tenant->id, $this->user->id);
    expect($r['trends']['deposits']['today'])->toBe('50.00000000')->and($r['trends']['deposits'][3])->toBe('16.66666667')->and($r['trends']['deposits'][30])->toBe('1.66666667');
    expect($r['trends']['annual']['today'])->toBe('0.00000000')->and($r['trends']['annual'][7])->toBe((string) BigDecimal::of($annual)->dividedBy(7, 8, RoundingMode::HalfUp));
    Http::assertNothingSent();
});

it('uses weighted first activation snapshots at the 70 percent boundary and keeps expired pending obligations visible', function () {
    stockPartner($this, $this->user);
    stockFundWallet($this, $this->user);
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 1)->first();
    app(ConfigurePaidPromotion::class)->execute($this->tenant->id, $this->admin, $level->id, ['fee' => $level->fee, 'percent' => $level->percent, 'reward' => $level->reward, 'target' => 10, 'revision' => $level->revision, 'enabled' => true]);
    stockBuy($this, $this->user, 1);
    $cycle = DB::table('paid_promotion_cycles')->where('user_id', $this->user->id)->first();
    $children = [];
    for ($i = 0; $i < 6; $i++) {
        $c = stockChild($this->user);
        stockFundWallet($this, $c);
        $children[] = $c;
        app(FundSecurityDepositAction::class)->execute($this->tenant->id, $c->id, (string) Str::uuid(), '50');
    }
    expect($this->report->read($this->tenant->id, $this->user->id)['risks']['activeCount'])->toBe(0);
    for ($i = 0; $i < 2; $i++) {
        $c = stockChild($children[0]);
        stockFundWallet($this, $c);
        app(FundSecurityDepositAction::class)->execute($this->tenant->id, $c->id, (string) Str::uuid(), '50');
    }
    $r = $this->report->read($this->tenant->id, $this->user->id);
    expect($r['risks']['activeCount'])->toBe(1)->and((string) $r['risks']['active']['items'][0]->weighted)->toBe('7.0')->and($r['risks']['remaining'])->toBe($level->fee);
    DB::unprepared("CREATE OR REPLACE FUNCTION test_stock_rebate_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action='PROMOTION_REBATE_AUTO_COMPLETED' THEN RAISE EXCEPTION 'offline test failure'; END IF; RETURN NEW; END $$; CREATE TRIGGER test_stock_rebate_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION test_stock_rebate_failure()");
    for ($i = 0; $i < 3; $i++) {
        $c = stockChild($this->user);
        stockFundWallet($this, $c);
        app(FundSecurityDepositAction::class)->execute($this->tenant->id, $c->id, (string) Str::uuid(), '50');
    }
    $claim = DB::table('paid_promotion_rebates')->where('cycle_id', $cycle->id)->where('status', 'PENDING')->first();
    expect($claim)->not->toBeNull();
    $this->travelTo(CarbonImmutable::parse($cycle->ends_at)->addDay());
    $r = $this->report->read($this->tenant->id, $this->user->id);
    expect($r['risks']['activeCount'])->toBe(0)->and($r['risks']['expiredCount'])->toBe(1)->and($r['risks']['expiredAmount'])->toBe($level->fee);
    DB::unprepared('DROP TRIGGER test_stock_rebate_failure ON audit_logs; DROP FUNCTION test_stock_rebate_failure()');
    app(PaidPromotionRebate::class)->settleAutomatic($this->tenant->id, $claim->id);
    $r = $this->report->read($this->tenant->id, $this->user->id);
    expect($r['risks']['expiredCount'])->toBe(0)->and($r['totals']['rebates'])->toBe($level->fee);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

function raceStockOperations(array $operations): array
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

it('serializes concurrent journal submissions and preserves trusted actor and one audit record', function () {
    $partner = stockPartner($this, $this->user);
    $tenant = $this->tenant->id;
    $actor = $this->admin->id;
    $data = stockEntry('REIMBURSEMENT', '13.12345678');
    $write = fn () => app(PartnerManagement::class)->journal(AdminUser::findOrFail($actor), $tenant, $partner->id, $data);
    $results = raceStockOperations([$write, $write]);
    expect($results)->toBe(['completed', 'completed']);
    expect(DB::table('partner_journal_entries')->where('request_id', $data['request_id'])->count())->toBe(1);
    expect(DB::table('audit_logs')->where('action', 'PARTNER_JOURNAL_RECORDED')->where('request_id', $data['request_id'])->count())->toBe(1);
});

it('enforces the separate platform permission and tenant ownership on all partner administration', function () {
    $partner = stockPartner($this, $this->user);
    $company = $this->tenant->id;
    $tenantAdmin = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    $base = 'http://admin.localhost/platform';
    $this->actingAs($tenantAdmin, 'platform_admin')->get($base.'/partners')->assertForbidden();
    $this->postJson($base.'/tenants/'.$company.'/partners/'.$partner->id.'/journal', stockEntry('ADVANCE', '1'))->assertForbidden();
    $this->actingAs($this->admin, 'platform_admin')->get($base.'/partners?tenant='.$company.'&partner='.$partner->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('platform/Partners')->where('report.stock', '0.00000000'));
    $foreign = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $this->get($base.'/partners?tenant='.$foreign->id.'&partner='.$partner->id)->assertNotFound();
    $this->postJson($base.'/tenants/'.$foreign->id.'/partners/'.$partner->id.'/journal', stockEntry('ADVANCE', '1'))->assertNotFound();
    // A forged actor field cannot override the authenticated operator.
    $data = stockEntry('ADVANCE', '2');
    $this->postJson($base.'/tenants/'.$company.'/partners/'.$partner->id.'/journal', $data + ['actor_id' => $tenantAdmin->id])->assertRedirect();
    expect(DB::table('partner_journal_entries')->where('request_id', $data['request_id'])->value('actor_id'))->toBe($this->admin->id);
});

it('counts exact USDT fees and excludes uncompleted withdrawals without any pricing request', function () {
    stockFundWallet($this, $this->user);
    stockPartner($this, $this->user);
    $this->travelTo(now()->startOfSecond());
    [$order] = stockWithdraw($this, 'USDT', '10', true);
    expect($order->status)->toBe('COMPLETED')->and($this->report->read($this->tenant->id, $this->user->id)['totals']['fees'])->toBe('1.00000000');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'okx.com'));
    $action = app(WithdrawAssetsAction::class);
    $pending = $action->create($this->tenant->id, $this->user->id, 'USDT_ETHEREUM', '10', '0x'.str_repeat('2', 40), '1', (string) Str::uuid(), true);
    expect($this->report->read($this->tenant->id, $this->user->id)['totals']['fees'])->toBe('1.00000000');
    $action->cancel($this->tenant->id, $this->user->id, $pending->id);
    expect($this->report->read($this->tenant->id, $this->user->id)['totals']['fees'])->toBe('1.00000000');
});

it('searches selectable company members by account nickname or email with pagination and scoped permission', function () {
    stockPartner($this, $this->user);
    $candidates = [];
    for ($i = 0; $i < 22; $i++) {
        $candidates[] = stockChild($this->user);
    }
    $chosen = $candidates[21];
    UserProfile::updateOrCreate(['tenant_id' => $this->tenant->id, 'user_id' => $chosen->id], ['display_name' => 'North Star']);
    stockPartner($this, $candidates[0], false);
    $base = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/partner-candidates';
    $this->actingAs($this->admin, 'platform_admin')->getJson($base)->assertOk()->assertJsonCount(20, 'items')->assertJsonPath('hasMore', true)->assertHeader('Cache-Control', 'no-store, private');
    $second = $this->getJson($base.'?page=2')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('hasMore', false);
    $search = $this->getJson($base.'?search=north')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.account_id', $chosen->account_id)->assertJsonPath('items.0.display_name', 'North Star');
    expect(array_keys($search->json('items.0')))->toBe(['account_id', 'display_name']);
    $this->getJson($base.'?search='.$chosen->account_id)->assertJsonPath('items.0.account_id', $chosen->account_id);
    foreach ([$chosen->email, strtoupper($chosen->email), substr($chosen->email, 0, 12)] as $emailSearch) {
        $this->getJson($base.'?search='.rawurlencode($emailSearch))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.account_id', $chosen->account_id)->assertJsonMissingPath('items.0.email');
    }
    $this->getJson($base.'?search='.rawurlencode($candidates[0]->email))->assertJsonCount(0, 'items');
    $this->getJson($base.'?search=%25')->assertJsonCount(0, 'items');
    $this->getJson($base.'?search='.$this->user->account_id)->assertJsonCount(0, 'items');
    $this->getJson($base.'?search='.$candidates[0]->account_id)->assertJsonCount(0, 'items');
    $other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $this->getJson('http://admin.localhost/platform/tenants/'.$other->id.'/partner-candidates?search='.$chosen->account_id)->assertJsonCount(0, 'items');
    $this->getJson('http://admin.localhost/platform/tenants/'.$other->id.'/partner-candidates?search='.rawurlencode($chosen->email))->assertJsonCount(0, 'items');
    $this->actingAs(AdminUser::where('email', 'owner@a.localhost')->firstOrFail(), 'platform_admin')->getJson($base)->assertForbidden();
    Http::assertNothingSent();
});
