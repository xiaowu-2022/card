<?php

use App\Application\Assets\AssetAccess;
use App\Application\Assets\WithdrawAssetsAction;
use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Partners\FeeValuation;
use App\Application\Partners\LegacyStockReport;
use App\Application\Partners\PartnerManagement;
use App\Application\Partners\PartnerReport;
use App\Application\Promotion\AdjustManualCommission;
use App\Application\Promotion\ConfigurePaidPromotion;
use App\Application\Promotion\ManualPromotion;
use App\Application\Promotion\PaidPromotionPurchase;
use App\Application\Promotion\PaidPromotionQuery;
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
    $this->report = app(LegacyStockReport::class);
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

it('selects the stock version from current company partner status and rejects caller identities', function () {
    $url = 'http://a.localhost/promotion/stock';
    $this->actingAs($this->user, 'tenant_user')->getJson($url)->assertOk()->assertJsonPath('version', 'standard');
    $this->get('http://a.localhost/promotion/daily')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canViewStock', true)->missing('stock'));
    $partner = stockPartner($this, $this->user);
    $this->getJson($url)->assertOk()->assertJsonPath('stock', '0.00000000')->assertJsonPath('version', 'partner')->assertJsonPath('totals.inflow', '0.00000000')->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson($url.'?user_id='.Str::uuid())->assertUnprocessable();
    stockPartner($this, $this->user, false);
    $this->getJson($url)->assertOk()->assertJsonPath('version', 'standard');
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
    // Rank 8 receives 100% of the wallet-paid fee, including this outside ancestor.
    expect($r['totals']['annualCommission'])->toBe($order->amount);
    $expected = BigDecimal::of($order->settlement_total)->minus((string) $cost)->minus($order->amount)->toScale(8);
    expect($r['stock'])->toBe((string) $expected)
        ->and($r['share'])->toBe((string) $expected->multipliedBy('0.4')->toScale(8, RoundingMode::HalfUp));
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
    $this->get($base.'/partners?partner='.$partner->id.'&page=2&report_page=3')
        ->assertOk()->assertInertia(fn (Assert $p) => $p->where('companyId', null)
        ->where('partners.current_page', 2)->where('report.journal.page', 3));
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
    expect(array_keys($search->json('items.0')))->toBe(['account_id', 'display_name', 'email']);
    $this->getJson($base.'?search='.$chosen->account_id)->assertJsonPath('items.0.account_id', $chosen->account_id);
    foreach ([$chosen->email, strtoupper($chosen->email), substr($chosen->email, 0, 12)] as $emailSearch) {
        $this->getJson($base.'?search='.rawurlencode($emailSearch))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.account_id', $chosen->account_id)->assertJsonPath('items.0.email', $chosen->email);
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

it('deducts each posted annual commission once by source team including outside beneficiaries', function () {
    stockFundWallet($this, $this->user);
    stockBuy($this, $this->user, 8);
    $partner = stockChild($this->user);
    stockFundWallet($this, $partner);
    stockPartner($this, $partner);
    stockBuy($this, $partner, 1);
    $child = stockChild($partner);
    stockFundWallet($this, $child);
    stockBuy($this, $child, 1);
    $paid = DB::table('paid_promotion_orders')->whereIn('user_id', [$partner->id, $child->id])->where('status', 'COMPLETED')->sum('amount');
    $before = $this->report->read($this->tenant->id, $partner->id);
    expect($before['totals']['annualCommission'])->toBe((string) BigDecimal::of((string) $paid)->toScale(8))
        ->and($before['stock'])->toBe('0.00000000')->and($before['share'])->toBe('0.00000000');
    $outside = stockChild($this->user);
    stockFundWallet($this, $outside);
    stockBuy($this, $outside, 1);
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 2)->value('id');
    app(PaidPromotionPurchase::class)->quote($this->tenant->id, $child->id, $level, (string) Str::uuid());
    $order = DB::table('paid_promotion_orders')->where('user_id', $child->id)->where('status', 'COMPLETED')->first();
    app(PaidPromotionPurchase::class)->confirm($this->tenant->id, $child->id, $order->id);
    $entries = DB::table('ledger_entries')->count();
    $after = $this->report->read($this->tenant->id, $partner->id);
    expect($after['totals'])->toBe($before['totals'])->and($after['stock'])->toBe($before['stock'])
        ->and(DB::table('ledger_entries')->count())->toBe($entries);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('reconciles only personal journal net amounts and available USDT without creating wallets', function () {
    $partner = stockPartner($this, $this->user);
    $child = stockChild($this->user);
    $nested = stockPartner($this, $child);
    $this->management->journal($this->admin, $this->tenant->id, $nested->id, stockEntry('ADVANCE', '900'));
    $this->management->journal($this->admin, $this->tenant->id, $nested->id, stockEntry('REIMBURSEMENT', '800'));
    $advance = $this->management->journal($this->admin, $this->tenant->id, $partner->id, stockEntry('ADVANCE', '100.00000001'));
    $expense = $this->management->journal($this->admin, $this->tenant->id, $partner->id, stockEntry('REIMBURSEMENT', '10'));
    $wallets = DB::table('wallets')->count();
    $ledger = DB::table('ledger_entries')->count();
    $r = $this->report->read($this->tenant->id, $this->user->id);
    expect($r['accountBalance'])->toBe([
        'advances' => '100.00000001', 'activationCommission' => '0.00000000',
        'annualCommission' => '0.00000000', 'unclassifiedCommission' => '0.00000000', 'reimbursements' => '10.00000000',
        'theoretical' => '90.00000001', 'actual' => '0.00000000', 'difference' => '90.00000001',
    ])->and($r['totals']['advances'])->toBe('1000.00000001')
        ->and($r['totals']['reimbursements'])->toBe('810.00000000')
        ->and(DB::table('wallets')->count())->toBe($wallets)
        ->and(DB::table('ledger_entries')->count())->toBe($ledger);
    $this->management->journal($this->admin, $this->tenant->id, $partner->id, stockEntry('ADVANCE', '100.00000001') + ['reverses_id' => $advance->id]);
    $this->management->journal($this->admin, $this->tenant->id, $partner->id, stockEntry('REIMBURSEMENT', '10') + ['reverses_id' => $expense->id]);
    expect($this->report->read($this->tenant->id, $this->user->id)['accountBalance']['theoretical'])->toBe('0.00000000');
    stockFundWallet($this, $this->user);
    stockFundWallet($this, $child);
    $before = DB::table('ledger_entries')->count();
    $r = $this->report->read($this->tenant->id, $this->user->id);
    expect($r['accountBalance']['actual'])->toBe('500000.00000000')
        ->and($r['accountBalance']['difference'])->toBe('-500000.00000000')
        ->and(DB::table('ledger_entries')->count())->toBe($before);
    Http::assertNothingSent();
});

it('adds only posted commissions received by the report owner to theoretical balance', function () {
    stockFundWallet($this, $this->user);
    stockBuy($this, $this->user, 8);
    stockPartner($this, $this->user);
    $child = stockChild($this->user);
    stockFundWallet($this, $child);
    stockPartner($this, $child);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    stockBuy($this, $child, 1);
    $r = $this->report->read($this->tenant->id, $this->user->id);
    $a = $r['accountBalance'];
    expect(BigDecimal::of($a['activationCommission'])->isPositive())->toBeTrue()
        ->and(BigDecimal::of($a['annualCommission'])->isPositive())->toBeTrue()
        ->and($a['theoretical'])->toBe((string) BigDecimal::of($a['activationCommission'])->plus($a['annualCommission'])->toScale(8));
    $childReport = $this->report->read($this->tenant->id, $child->id);
    // The child's team generated payments to its ancestor; they are not the child's income.
    expect(BigDecimal::of($childReport['totals']['annualCommission'])->isPositive())->toBeTrue()
        ->and($childReport['accountBalance']['annualCommission'])->toBe('0.00000000')
        ->and($childReport['accountBalance']['activationCommission'])->toBe('0.00000000');
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('returns the agreed 140 theoretical 125 actual and 15 difference example', function () {
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 1)->first();
    app(ConfigurePaidPromotion::class)->execute($this->tenant->id, $this->admin, $level->id, [
        'fee' => '1000', 'percent' => 3, 'reward' => 20, 'target' => $level->target,
        'revision' => $level->revision, 'enabled' => true,
    ]);
    stockFundWallet($this, $this->user);
    stockBuy($this, $this->user, 1);
    $partner = stockPartner($this, $this->user);
    $this->management->journal($this->admin, $this->tenant->id, $partner->id, stockEntry('ADVANCE', '100'));
    $this->management->journal($this->admin, $this->tenant->id, $partner->id, stockEntry('REIMBURSEMENT', '10'));
    $depositChild = stockChild($this->user);
    stockFundWallet($this, $depositChild);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $depositChild->id, (string) Str::uuid(), '50');
    $annualChild = stockChild($this->user);
    stockFundWallet($this, $annualChild);
    stockBuy($this, $annualChild, 1);
    $available = LedgerAccount::where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->where('asset_code', 'USDT')->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $clearing = LedgerAccount::where('tenant_id', $this->tenant->id)->where('asset_code', 'USDT')->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
    $delta = BigDecimal::of('125')->minus(DB::table('ledger_accounts')->where('id', $available->id)->value('balance'));
    app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'USDT', 'stock-example:'.Str::uuid(), 'TEST_TOPUP', null, null, null, [
        new LedgerPostingInstruction($available->id, Money::of((string) $delta, 'USDT')),
        new LedgerPostingInstruction($clearing->id, Money::of((string) $delta->negated(), 'USDT')),
    ]));
    $before = DB::table('ledger_entries')->count();
    expect($this->report->read($this->tenant->id, $this->user->id)['accountBalance'])->toBe([
        'advances' => '100.00000000', 'activationCommission' => '20.00000000',
        'annualCommission' => '30.00000000', 'unclassifiedCommission' => '0.00000000', 'reimbursements' => '10.00000000',
        'theoretical' => '140.00000000', 'actual' => '125.00000000', 'difference' => '15.00000000',
    ])->and(DB::table('ledger_entries')->count())->toBe($before);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('adds personal manual commission to reconciliation without attributing it to team stock', function () {
    stockPartner($this, $this->user);
    stockFundWallet($this, $this->user);
    $before = $this->report->read($this->tenant->id, $this->user->id);
    app(AdjustManualCommission::class)->execute($this->tenant->id, $this->user->id, $this->admin, [
        'commission_type' => 'activation', 'direction' => 'INCREASE', 'amount' => '12.12345678', 'reason' => 'Offline reconciliation fixture', 'request_id' => (string) Str::uuid(),
    ]);
    $after = $this->report->read($this->tenant->id, $this->user->id);
    expect($after['accountBalance']['activationCommission'])->toBe('12.12345678')
        ->and($after['accountBalance']['theoretical'])->toBe('12.12345678')
        ->and($after['accountBalance']['difference'])->toBe($before['accountBalance']['difference'])
        ->and($after['totals'])->toBe($before['totals'])->and($after['stock'])->toBe($before['stock']);
});

// Synthetic archived external-order evidence; no provider calls or real funds.
function stockExternal(User $user, string $asset, string $amount, bool $incoming = true, bool $complete = true, bool $legacy = false): void
{
    $tenant = Tenant::findOrFail($user->tenant_id);
    $access = app(AssetAccess::class);
    $wallet = $access->wallet($tenant, $user, $asset);
    $entry = app(LedgerWriter::class)->post(new LedgerPostingPlan($tenant->id, $asset, 'stock-fixture:'.Str::uuid(), 'TEST_EXTERNAL_EVIDENCE', null, null, null, [
        new LedgerPostingInstruction($access->account($wallet, 'USER_AVAILABLE')->id, Money::of($amount, $asset)),
        new LedgerPostingInstruction($access->companyAccount($tenant->id, $asset, 'TENANT_TOPUP_CLEARING')->id, Money::of('-'.$amount, $asset)),
    ]));
    $id = (string) Str::uuid();
    $base = ['id' => $id, 'tenant_id' => $tenant->id, 'user_id' => $user->id, 'wallet_id' => $wallet->id,
        'asset_code' => $asset, 'amount' => $amount, 'request_id' => (string) Str::uuid(), 'request_hash' => hash('sha256', $id),
        'created_at' => now(), 'updated_at' => now()];
    if ($legacy && $incoming) {
        DB::table('wallet_topup_orders')->insert($base + ['status' => $complete ? 'CREDITED' : 'CREATED', 'payment_provider' => 'mock', 'ledger_entry_id' => $complete ? $entry->id : null, 'credited_at' => $complete ? now() : null, 'paid_at' => $complete ? now() : null]);

        return;
    }
    if ($legacy && ! $incoming) {
        $destination = (string) Str::uuid();
        DB::table('withdrawal_destinations')->insert(['id' => $destination, 'tenant_id' => $tenant->id, 'user_id' => $user->id, 'asset_code' => 'USDT', 'network_code' => 'TRON', 'address_ciphertext' => 'synthetic', 'address_hash' => hash('sha256', $id), 'masked_address' => 'synthetic', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('withdrawal_orders')->insert($base + ['withdrawal_destination_id' => $destination, 'network_code' => 'TRON', 'status' => $complete ? 'SUCCEEDED' : 'PENDING', 'requested_at' => now(), 'settlement_ledger_entry_id' => $complete ? $entry->id : null, 'fee_amount' => '0.1']);

        return;
    }
    $rail = AssetRail::where('asset_code', $asset)->where('network', $asset === 'BTC' ? 'BITCOIN' : 'ETHEREUM')->firstOrFail();
    $base += ['rail_code' => $rail->code, 'network' => $rail->network, 'address' => 'synthetic-report-only', 'address_hash' => hash('sha256', $id), 'chain_event_id' => $complete ? $id : null, 'ledger_entry_id' => $complete ? $entry->id : null];
    DB::table($incoming ? 'asset_deposit_orders' : 'asset_withdrawal_orders')->insert($base + ($incoming
        ? ['status' => $complete ? 'CREDITED' : 'PENDING', 'requested_amount' => $amount, 'expires_at' => now()->addHour()]
        : ['status' => $complete ? 'COMPLETED' : 'PENDING', 'fee_amount' => '0.1']));
}

// Exact synthetic guarantee evidence for report-only scope/precision tests.
function stockGuarantee(User $user, string $delta): void
{
    $tenant = Tenant::findOrFail($user->tenant_id);
    $access = app(AssetAccess::class);
    $wallet = $access->wallet($tenant, $user, 'USDT');
    $deposit = LedgerAccount::firstOrCreate(['tenant_id' => $tenant->id, 'user_id' => $user->id,
        'wallet_id' => $wallet->id, 'asset_code' => 'USDT', 'account_type' => 'USER_SECURITY_DEPOSIT'], ['balance' => '0', 'status' => 'ACTIVE']);
    app(LedgerWriter::class)->post(new LedgerPostingPlan($tenant->id, 'USDT', 'stock-guarantee-fixture:'.Str::uuid(), 'TEST_GUARANTEE_EVIDENCE', null, null, null, [
        new LedgerPostingInstruction($deposit->id, Money::of($delta, 'USDT')),
        new LedgerPostingInstruction($access->companyAccount($tenant->id, 'USDT', 'TENANT_TOPUP_CLEARING')->id, Money::of((string) BigDecimal::of($delta)->negated(), 'USDT')),
    ]));
}

it('counts partner business contributions only and never values wallet topups withdrawals or withdrawal fees', function () {
    stockPartner($this, $this->user);
    $child = stockChild($this->user);
    foreach (['USDT', 'USDC', 'ETH', 'BTC'] as $asset) {
        stockExternal($child, $asset, '10');
        stockExternal($child, $asset, '2', false);
    }
    stockExternal($child, 'USDT', '50', true, true, true);
    stockExternal($child, 'USDT', '1', false, true, true);
    $query = app(PartnerReport::class);
    $zero = $query->read($this->tenant->id, $this->user->id, true, 1, 'inflow');
    expect($zero['stock'])->toBe('0.00000000')->and($zero['missingRates'])->toBe(0)->and($zero['cashFlow'])->toBeNull()
        ->and($zero['flowDetails']['total'])->toBe(0);
    stockGuarantee($child, '50.12345678');
    stockGuarantee($this->user, '900');
    $outside = User::where('tenant_id', '<>', $this->tenant->id)->firstOrFail();
    stockGuarantee($outside, '800');
    $balances = DB::table('ledger_accounts')->orderBy('id')->pluck('balance', 'id')->all();
    $entries = DB::table('ledger_entries')->count();
    $r = $query->read($this->tenant->id, $this->user->id, true, 1, 'inflow');
    expect($r['stock'])->toBe('50.12345678')->and($r['totals']['deposits'])->toBe('50.12345678')
        ->and($r['totals']['annual'])->toBe('0.00000000')->and($r['totals']['outflow'])->toBe('0.00000000')
        ->and($r['flowDetails']['items'][0]['posted_at'])->toBeNull()
        ->and($r['flowDetails']['items'][0]['account_id'])->toBe($child->account_id)
        ->and(DB::table('ledger_accounts')->orderBy('id')->pluck('balance', 'id')->all())->toBe($balances)
        ->and(DB::table('ledger_entries')->count())->toBe($entries);
    Http::assertNothingSent();
    expect(fn () => $query->read($outside->tenant_id, $this->user->id))->toThrow(HttpException::class);
});

it('converts guarantees into annual business stock once and deducts posted source commissions including partner and outside recipients', function () {
    stockFundWallet($this, $this->user);
    stockBuy($this, $this->user, 8);
    $owner = stockChild($this->user);
    stockPartner($this, $owner);
    stockFundWallet($this, $owner);
    stockBuy($this, $owner, 1); // Own fee and its rewards must not enter this report.
    $child = stockChild($owner);
    stockFundWallet($this, $child);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $query = app(PartnerReport::class);
    $r = $query->read($this->tenant->id, $owner->id);
    expect($r['totals']['deposits'])->toBe('50.00000000');
    $cost = $r['totals']['activation'];
    expect(BigDecimal::of($cost)->isPositive())->toBeTrue();
    stockBuy($this, $child, 1);
    $order = DB::table('paid_promotion_orders')->where('user_id', $child->id)->where('status', 'COMPLETED')->first();
    expect($order->deposit_applied)->toBe('50.00000000');
    $r = $query->read($this->tenant->id, $owner->id, true, 1, 'outflow');
    expect($r['totals']['deposits'])->toBe('0.00000000')->and($r['totals']['annual'])->toBe($order->settlement_total)
        ->and($r['totals']['annualCommission'])->toBe($order->amount)->and($r['totals']['activation'])->toBe($cost)
        ->and($r['stock'])->toBe((string) BigDecimal::of($order->settlement_total)->minus($order->amount)->minus($cost)->toScale(8));
    $sum = collect($r['flowDetails']['items'])->reduce(fn ($sum, $row) => $sum->plus($row['amount']), BigDecimal::zero());
    expect((string) $sum->toScale(8))->toBe($r['totals']['outflow']);
    stockPartner($this, $child);
    $r = $query->read($this->tenant->id, $owner->id);
    expect($r['stock'])->toBe('0.00000000')->and($r['totals']['annualCommission'])->toBe('0.00000000');
    Http::assertNothingSent();
});

it('deducts net owner and descendant partner reimbursements with reversals but not advances', function () {
    $owner = stockPartner($this, $this->user);
    $direct = stockChild($this->user);
    $nested = stockPartner($this, $direct);
    $child = stockChild($direct);
    stockGuarantee($child, '100.12345678');
    $first = $this->management->journal($this->admin, $this->tenant->id, $owner->id, stockEntry('REIMBURSEMENT', '120.12345679'));
    $this->management->journal($this->admin, $this->tenant->id, $nested->id, stockEntry('REIMBURSEMENT', '5'));
    $this->management->journal($this->admin, $this->tenant->id, $owner->id, stockEntry('ADVANCE', '900'));
    $query = app(PartnerReport::class);
    $r = $query->read($this->tenant->id, $this->user->id, true, 1, 'outflow');
    expect($r['stock'])->toBe('-25.00000001')->and($r['negative'])->toBeTrue()->and($r['totals']['reimbursements'])->toBe('125.12345679')
        ->and($r['flowDetails']['total'])->toBe(2);
    $this->management->journal($this->admin, $this->tenant->id, $owner->id, stockEntry('REIMBURSEMENT', '120.12345679') + ['reverses_id' => $first->id]);
    $r = $query->read($this->tenant->id, $this->user->id, true, 1, 'outflow');
    expect($r['stock'])->toBe('95.12345678')->and($r['totals']['reimbursements'])->toBe('5.00000000')->and($r['flowDetails']['total'])->toBe(3)
        ->and(collect($r['flowDetails']['items'])->where('amount', '<', 0))->toHaveCount(1);
    stockGuarantee($child, '-50'); // Completed refund leaves held principal once.
    expect($query->read($this->tenant->id, $this->user->id)['stock'])->toBe('45.12345678');
    Http::assertNothingSent();
});

it('paginates business contribution details and retains current direct branches without counting partner accounts', function () {
    stockPartner($this, $this->user);
    $direct = stockChild($this->user);
    $nested = stockChild($direct);
    $leaf = stockChild($nested);
    foreach ([$direct, $nested, $leaf] as $member) {
        stockGuarantee($member, '10.00000001');
    }
    $query = app(PartnerReport::class);
    $standard = $query->read($this->tenant->id, $leaf->id);
    $check = function (string $total, array $members) use ($query, $direct) {
        $r = $query->read($this->tenant->id, $this->user->id, true, 1, 'inflow');
        expect($r['stock'])->toBe($total)->and($r['flowDetails']['total'])->toBe(count($members))
            ->and(array_column($r['flowDetails']['items'], 'account_id'))->toEqualCanonicalizing(array_map(fn ($m) => $m->account_id, $members));
        foreach ($r['flowDetails']['items'] as $row) {
            expect($row['direct_account_id'])->toBe($direct->account_id)->and($row['direct_email'])->toBe($direct->email);
        }
    };
    $check('30.00000003', [$direct, $nested, $leaf]);
    stockPartner($this, $direct);
    $check('20.00000002', [$nested, $leaf]);
    stockPartner($this, $nested);
    $check('10.00000001', [$leaf]);
    stockPartner($this, $direct, false);
    $check('20.00000002', [$direct, $leaf]);
    stockPartner($this, $nested, false);
    $check('30.00000003', [$direct, $nested, $leaf]);
    expect($query->read($this->tenant->id, $leaf->id)['totals'])->toBe($standard['totals']);
    foreach (range(1, 18) as $_) {
        stockGuarantee(stockChild($nested), '1.00000001');
    }
    $one = $query->read($this->tenant->id, $this->user->id, true, 1, 'inflow');
    $two = $query->read($this->tenant->id, $this->user->id, true, 1, 'inflow', 2);
    expect($one['flowDetails']['total'])->toBe(21)->and($one['flowDetails']['items'])->toHaveCount(20)->and($one['flowDetails']['hasMore'])->toBeTrue()
        ->and($two['flowDetails']['items'])->toHaveCount(1)->and($two['flowDetails']['hasMore'])->toBeFalse()
        ->and(array_intersect(array_column($one['flowDetails']['items'], 'id'), array_column($two['flowDetails']['items'], 'id')))->toBe([])
        ->and($one['totals']['inflow'])->toBe('48.00000021');
    Http::assertNothingSent();
});

it('protects partner business drilldown identity and rejects ordinary users', function () {
    stockPartner($this, $this->user);
    $direct = stockChild($this->user);
    stockGuarantee($direct, '5');
    $this->actingAs($this->user, 'tenant_user');
    $url = 'http://a.localhost/promotion/stock';
    $this->getJson($url.'?flow=inflow')->assertOk()->assertJsonPath('flowDetails.total', 1)->assertHeader('Cache-Control', 'no-store, private');
    foreach (['flow=invalid', 'flow=inflow&flow_page=0', 'flow=inflow&user_id='.$direct->id, 'flow=inflow&tenant_id='.$this->tenant->id] as $query) {
        $this->getJson($url.'?'.$query)->assertUnprocessable();
    }
    $this->actingAs($direct, 'tenant_user')->getJson($url.'?flow=inflow')->assertForbidden();
    $this->actingAs($this->admin, 'platform_admin')->get('http://admin.localhost/platform/partners?tenant='.$this->tenant->id.'&partner='.DB::table('partner_configurations')->where('user_id', $this->user->id)->value('id').'&flow=inflow')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.flowDetails.total', 1));
    Http::assertNothingSent();
});

it('opens partner invitation tables without creating membership wallets rewards or external requests', function () {
    $partner = stockPartner($this, $this->user);
    $direct = stockChild($this->user);
    stockChild($direct);
    $before = collect(['promotion_members', 'wallets', 'ledger_accounts', 'ledger_entries', 'account_activations', 'paid_promotion_orders', 'audit_logs'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
    $paid = app(PaidPromotionQuery::class)->execute($this->tenant->id, $this->user->id);
    $response = $this->actingAs($this->admin, 'platform_admin')->getJson('http://admin.localhost/platform/partners/'.$partner->id.'/invitations');
    $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('account.accountId', $this->user->account_id)->assertJsonPath('account.companyId', $this->tenant->id)
        ->assertJsonPath('summary.directPeople', 1)->assertJsonPath('summary.indirectPeople', 1)
        ->assertJsonPath('commission', '0.00000000')->assertJsonPath('details', null)
        ->assertJsonMissingPath('summary.paymentAccess')->assertJsonMissingPath('summary.availableBalance');
    expect($response->json('summary.tables'))->toBe($paid['tables'])
        ->and($response->json('summary.teamByLevel'))->toBe($paid['teamByLevel']);
    foreach ($before as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('scopes partner invitation reads and rejects forged targets missing permissions and invalid drilldowns', function () {
    $partner = stockPartner($this, $this->user, false);
    $url = 'http://admin.localhost/platform/partners/'.$partner->id.'/invitations';
    $this->actingAs($this->admin, 'platform_admin')->getJson($url)->assertOk();
    $other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $this->getJson($url.'?company='.$other->id)->assertNotFound();
    $this->getJson('http://admin.localhost/platform/partners/'.Str::uuid().'/invitations')->assertNotFound();
    foreach (['kind=ANNUAL', 'rank=1', 'kind=INVALID&rank=1', 'kind=ANNUAL&rank=999999', 'page=0', 'page=100001', 'company=invalid', 'user_id='.$this->user->id, 'tenant_id='.$other->id, 'subject='.$this->user->id] as $query) {
        $this->getJson($url.'?'.$query)->assertUnprocessable();
    }
    $this->actingAs(AdminUser::where('email', 'owner@a.localhost')->firstOrFail(), 'platform_admin')->getJson($url)->assertForbidden();
    $permission = DB::table('permissions')->where('name', 'partners.manage')->value('id');
    DB::table('role_permissions')->where('permission_id', $permission)->delete();
    $this->actingAs($this->admin, 'platform_admin')->getJson($url)->assertForbidden();
});

it('matches partner invitation reward records to the consumer table across pagination and current level changes', function () {
    $partner = stockPartner($this, $this->user);
    stockFundWallet($this, $this->user);
    stockBuy($this, $this->user, 8);
    $first = null;
    foreach (range(1, 31) as $_) {
        $child = stockChild($this->user);
        stockFundWallet($this, $child);
        stockBuy($this, $child, 1);
        $first ??= $child;
    }
    // A later level adjustment must not regroup the existing rank-one commission.
    app(ManualPromotion::class)->adjust($this->tenant->id, $first->id, $this->admin, 'ordinary', 'Offline comparison', (string) Str::uuid(), null);
    $query = app(PaidPromotionQuery::class);
    $canonical = $query->execute($this->tenant->id, $this->user->id);
    $before = DB::table('ledger_accounts')->pluck('balance', 'id')->all();
    $counts = collect(['ledger_entries', 'paid_promotion_shares', 'paid_promotion_orders', 'audit_logs'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
    $url = 'http://admin.localhost/platform/partners/'.$partner->id.'/invitations?kind=ANNUAL&rank=1';
    $one = $this->actingAs($this->admin, 'platform_admin')->getJson($url)->assertOk()->assertJsonPath('details.hasMore', true)->assertJsonCount(30, 'details.items');
    $two = $this->getJson($url.'&page=2')->assertOk()->assertJsonPath('details.hasMore', false)->assertJsonCount(1, 'details.items');
    $this->getJson(str_replace('ANNUAL', 'ACTIVATION', $url))->assertOk()->assertJsonCount(0, 'details.items');
    expect($one->json('summary.tables'))->toBe($canonical['tables'])
        ->and($one->json('summary.teamByLevel'))->toBe($canonical['teamByLevel'])
        ->and(array_column($one->json('details.items'), 'id'))->toBe(array_column($query->details($this->tenant->id, $this->user->id, 'ANNUAL', 1, 1)['items'], 'id'));
    $rows = [...$one->json('details.items'), ...$two->json('details.items')];
    expect(array_unique(array_column($rows, 'id')))->toHaveCount(31);
    foreach ($rows as $row) {
        expect($row['direct'])->toBeTrue()->and($row['email'])->not->toBeNull();
    }
    $sum = collect($rows)->reduce(fn ($sum, $row) => $sum->plus($row['amount']), BigDecimal::zero());
    $cell = collect($canonical['tables']['ANNUAL'])->firstWhere('rank', 1);
    expect((string) $sum->toScale(8))->toBe((string) BigDecimal::of($cell['direct']['amount'])->toScale(8))
        ->and(DB::table('ledger_accounts')->pluck('balance', 'id')->all())->toBe($before);
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
    Http::assertNothingSent();
});

it('deducts only completed annual returns from eligible partner business contributors', function () {
    stockPartner($this, $this->user);
    $child = stockChild($this->user);
    stockFundWallet($this, $child);
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 1)->first();
    app(ConfigurePaidPromotion::class)->execute($this->tenant->id, $this->admin, $level->id, [
        'fee' => $level->fee, 'percent' => $level->percent, 'reward' => $level->reward,
        'target' => 1, 'revision' => $level->revision, 'enabled' => true,
    ]);
    stockBuy($this, $child, 1);
    $grandchild = stockChild($child);
    stockFundWallet($this, $grandchild);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $grandchild->id, (string) Str::uuid(), '50');
    $returned = DB::table('paid_promotion_rebates')->where('user_id', $child->id)->where('status', 'APPROVED')->whereNotNull('ledger_entry_id')->sum('amount');
    expect(BigDecimal::of((string) $returned)->isPositive())->toBeTrue();
    $query = app(PartnerReport::class);
    $r = $query->read($this->tenant->id, $this->user->id, true, 1, 'outflow');
    expect($r['totals']['rebates'])->toBe((string) BigDecimal::of((string) $returned)->toScale(8))
        ->and(collect($r['flowDetails']['items'])->where('source', 'rebates'))->toHaveCount(1);
    stockPartner($this, $child);
    expect($query->read($this->tenant->id, $this->user->id)['totals']['rebates'])->toBe('0.00000000');
    Http::assertNothingSent();
});
