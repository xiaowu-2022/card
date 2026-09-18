<?php

use App\Application\Assets\AssetOverviewQuery;
use App\Application\Assets\FundsQuery;
use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Wealth\WealthConfiguration;
use App\Application\Wealth\WealthOverview;
use App\Application\Wealth\WealthQuery;
use App\Application\Wealth\WealthService;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\MarketSettings;
use App\Domain\Assets\MarketSnapshot;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Services\WalletProvisioner;
use App\Domain\Wealth\WealthOrder;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed();
    Http::preventStrayRequests();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->admin = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    Storage::fake('private');
    $application = app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'MY', 'WEALTH-'.$this->user->id, kycTestImage('front.png'), kycTestImage('back.png'));
    $reviewer = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    app(ApproveKycAction::class)->execute($this->tenant->id, $application->id, $reviewer);
    $this->wallet = DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $this->user, 'USDT'));
    $this->account = LedgerAccount::where('wallet_id', $this->wallet->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $this->clearing = LedgerAccount::where('tenant_id', $this->tenant->id)->where('asset_code', 'USDT')->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
    app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'USDT', 'wealth_test:'.Str::uuid(), 'TEST_TOPUP', null, null, null, [new LedgerPostingInstruction($this->clearing->id, Money::of('-2000', 'USDT')), new LedgerPostingInstruction($this->account->id, Money::of('2000', 'USDT'))]));
    $settings = app(WealthConfiguration::class)->get($this->tenant->id);
    foreach ($settings as &$setting) {
        $setting['minimum'] = '1';
        foreach ($setting['products'] as &$product) {
            $product['enabled'] = true;
        } unset($product);
    } unset($setting);
    app(WealthConfiguration::class)->save($this->admin, $this->tenant->id, ['settings' => $settings]);
    $this->revision = app(WealthConfiguration::class)->get($this->tenant->id)[0]['revision'];
    $this->service = app(WealthService::class);
    $this->deposit = fn ($months = 3, $id = null) => $this->service->deposit($this->tenant->id, $this->user->id, 'USDT', '1000', $months, $this->revision, $id ?? (string) Str::uuid());
});

it('settles months and returns all principal once at maturity', function () {
    $order = ($this->deposit)();
    expect($this->account->fresh()->balance)->toBe('1000.00000000');
    $this->travelTo($order->matures_at);
    $this->service->settle($this->tenant->id, $order->id);
    $this->service->settle($this->tenant->id, $order->id);
    expect($order->fresh()->status)->toBe('MATURED')->and($this->account->fresh()->balance)->toBe('2020.00000000');
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('recovers paid interest from principal even after that interest is spent', function () {
    $order = ($this->deposit)();
    $this->travelTo(CarbonImmutable::parse($order->schedule[0]['due_at']));
    $this->service->settle($this->tenant->id, $order->id);
    $paid = $this->service->paid($order);
    expect($paid)->toBe('6.66666666');
    $available = $this->account->fresh()->balance;
    app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'USDT', 'wealth_spend:'.Str::uuid(), 'TEST_SPEND', null, null, null, [new LedgerPostingInstruction($this->account->id, Money::of('-'.$available, 'USDT')), new LedgerPostingInstruction($this->clearing->id, Money::of($available, 'USDT'))]));
    $request = (string) Str::uuid();
    $this->service->cancel($this->tenant->id, $this->user->id, $order->id, $request, 'local-password', $paid);
    $this->service->cancel($this->tenant->id, $this->user->id, $order->id, $request, 'local-password', $paid);
    expect($this->account->fresh()->balance)->toBe('993.33333334')->and($order->fresh()->status)->toBe('CANCELLED');
    $this->travelTo($order->matures_at);
    $this->service->settle($this->tenant->id, $order->id);
    expect($this->service->paid($order))->toBe($paid);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('locks configuration snapshots and scopes reads and rejects mismatched replays', function () {
    $request = (string) Str::uuid();
    $order = ($this->deposit)(3, $request);
    expect(($this->deposit)(3, $request)->id)->toBe($order->id);
    expect(fn () => ($this->deposit)(6, $request))->toThrow(DomainException::class);
    $settings = app(WealthConfiguration::class)->get($this->tenant->id);
    $settings[0]['products'][1]['enabled'] = false;
    app(WealthConfiguration::class)->save($this->admin, $this->tenant->id, ['settings' => $settings]);
    expect(fn () => ($this->deposit)())->toThrow(DomainException::class);
    expect(fn () => app(WealthQuery::class)->detail($this->tenant->id, (string) Str::uuid(), $order->id))->toThrow(ModelNotFoundException::class);
    $this->travelTo($order->matures_at);
    $this->service->settle($this->tenant->id, $order->id);
    expect($this->account->fresh()->balance)->toBe('2020.00000000');
});

it('rejects insufficient funds and stale cancellation preview without moving funds', function () {
    ($this->deposit)();
    ($this->deposit)();
    expect(fn () => ($this->deposit)())->toThrow(DomainException::class);
    expect(WealthOrder::count())->toBe(2);
    $order = WealthOrder::first();
    $this->travelTo(CarbonImmutable::parse($order->schedule[0]['due_at']));
    $this->service->settle($this->tenant->id, $order->id);
    expect(fn () => $this->service->cancel($this->tenant->id, $this->user->id, $order->id, (string) Str::uuid(), 'local-password', '0'))->toThrow(DomainException::class);
    expect($order->fresh()->status)->toBe('ACTIVE');
});

it('supports each currency without activation or promotion side effects', function (string $asset) {
    $wallet = DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $this->user, $asset));
    $account = LedgerAccount::where('wallet_id', $wallet->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $clearing = LedgerAccount::where('tenant_id', $this->tenant->id)->where('asset_code', $asset)->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
    if ($asset !== 'USDT') {
        app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, $asset, 'wealth_currency:'.Str::uuid(), 'TEST_TOPUP', null, null, null, [new LedgerPostingInstruction($clearing->id, Money::of('-1000', $asset)), new LedgerPostingInstruction($account->id, Money::of('1000', $asset))]));
    }
    $setting = collect(app(WealthConfiguration::class)->get($this->tenant->id))->firstWhere('asset', $asset);
    $before = $account->fresh()->balance;
    $order = $this->service->deposit($this->tenant->id, $this->user->id, $asset, '1000', 1, $setting['revision'], (string) Str::uuid());
    $this->travelTo($order->matures_at);
    $this->service->settle($this->tenant->id, $order->id);
    expect($account->fresh()->balance)->toBe(Money::of($before, $asset)->add(Money::of('5', $asset))->amount());
    expect(DB::table('ledger_entries')->whereIn('event_type', ['COMMISSION_EARN', 'PROMOTION_ANNUAL_COMMISSION'])->count())->toBe(0);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
})->with(['USDT', 'USDC', 'ETH', 'BTC']);

it('keeps automatic obligations after suspension but retries a disabled wallet', function () {
    $order = ($this->deposit)();
    $this->user->update(['status' => 'SUSPENDED']);
    $this->wallet->update(['status' => 'SUSPENDED']);
    $this->travelTo($order->matures_at);
    expect(fn () => $this->service->settle($this->tenant->id, $order->id))->toThrow(DomainException::class);
    expect($order->fresh()->status)->toBe('ACTIVE')->and($this->service->paid($order))->toBe('0.00000000');
    $this->wallet->update(['status' => 'ACTIVE']);
    $this->service->settle($this->tenant->id, $order->id);
    expect($order->fresh()->status)->toBe('MATURED');
});

it('rejects company admin writes and total interest reaching the principal', function () {
    $settings = app(WealthConfiguration::class)->get($this->tenant->id);
    $companyAdmin = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    expect(fn () => app(WealthConfiguration::class)->save($companyAdmin, $this->tenant->id, ['settings' => $settings]))->toThrow(HttpException::class);
    $settings[0]['products'][6]['rate'] = '20';
    expect(fn () => app(WealthConfiguration::class)->save($this->admin, $this->tenant->id, ['settings' => $settings]))->toThrow(ValidationException::class);
});

it('preserves snapshots at the database boundary', function () {
    $order = ($this->deposit)();
    expect(fn () => DB::transaction(fn () => DB::table('wealth_orders')->where('id', $order->id)->update(['annual_rate' => '99'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('wealth_installments')->where('order_id', $order->id)->delete()))->toThrow(QueryException::class);
});

it('shows scoped pages and enforces consumer confirmation without creating orders on reads', function () {
    $this->actingAs($this->user, 'tenant_user');
    $this->get('http://a.localhost/wealth')->assertOk();
    expect(WealthOrder::count())->toBe(0);
    $this->post('http://a.localhost/wealth/orders', ['asset' => 'USDT', 'amount' => '1000', 'months' => 3, 'revision' => $this->revision, 'request_id' => (string) Str::uuid()])->assertSessionHasErrors('confirmed');
    $order = ($this->deposit)();
    $this->get('http://a.localhost/wealth/orders/'.$order->id)->assertOk();
    $other = User::where('tenant_id', '!=', $this->tenant->id)->firstOrFail();
    expect(fn () => app(WealthQuery::class)->detail($other->tenant_id, $other->id, $order->id))->toThrow(ModelNotFoundException::class);
});

function raceWealthOperations(array $operations): array
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

it('serializes cancellation against monthly interest without overpaying', function () {
    $order = ($this->deposit)();
    $this->travelTo(CarbonImmutable::parse($order->schedule[0]['due_at']));
    $results = raceWealthOperations([
        fn () => $this->service->settle($this->tenant->id, $order->id),
        fn () => $this->service->cancel($this->tenant->id, $this->user->id, $order->id, (string) Str::uuid(), 'local-password', '0'),
    ]);
    foreach ($results as $result) {
        expect($result)->toBeIn(['completed', 'rejected']);
    }
    if ($order->fresh()->status === 'ACTIVE') {
        $this->service->cancel($this->tenant->id, $this->user->id, $order->id, (string) Str::uuid(), 'local-password', $this->service->paid($order));
    }
    expect($this->account->fresh()->balance)->toBe('2000.00000000');
    expect(DB::table('ledger_entries')->where('reference_id', $order->id)->where('event_type', 'WEALTH_CANCEL')->count())->toBe(1);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('serializes duplicate maturity recovery and cancellation at the deadline', function () {
    $order = ($this->deposit)();
    $this->travelTo($order->matures_at);
    $results = raceWealthOperations([
        fn () => $this->service->settle($this->tenant->id, $order->id),
        fn () => $this->service->settle($this->tenant->id, $order->id),
        fn () => $this->service->cancel($this->tenant->id, $this->user->id, $order->id, (string) Str::uuid(), 'local-password', '0'),
    ]);
    foreach ($results as $result) {
        expect($result)->toBeIn(['completed', 'rejected']);
    }
    expect($this->account->fresh()->balance)->toBe('2020.00000000')->and($order->fresh()->status)->toBe('MATURED');
    expect(DB::table('ledger_entries')->where('reference_id', $order->id)->where('event_type', 'WEALTH_MATURITY')->count())->toBe(1);
});

it('counts wealth principal once in total assets and adds only paid interest', function () {
    $query = app(AssetOverviewQuery::class);
    $before = $query->get($this->tenant->id, $this->user->id, []);
    $order = ($this->deposit)();
    expect($query->get($this->tenant->id, $this->user->id, [])['estimate'])->toBe($before['estimate']);
    $this->travelTo(CarbonImmutable::parse($order->schedule[0]['due_at']));
    $this->service->settle($this->tenant->id, $order->id);
    $after = $query->get($this->tenant->id, $this->user->id, []);
    expect($after['estimate'])->toBe(Money::of($before['estimate'], 'USDT')->add(Money::of('6.66666666', 'USDT'))->amount());
});

it('rejects unlinked financial entries and rolls failed settlement back for recovery', function () {
    $order = ($this->deposit)();
    $this->travelTo($order->matures_at);
    DB::unprepared("CREATE OR REPLACE FUNCTION test_wealth_audit_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action='WEALTH_MATURITY' THEN RAISE EXCEPTION 'test rollback'; END IF; RETURN NEW; END $$; CREATE TRIGGER test_wealth_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION test_wealth_audit_failure()");
    expect(fn () => $this->service->settle($this->tenant->id, $order->id))->toThrow(QueryException::class);
    expect($this->service->paid($order))->toBe('0.00000000')->and($this->account->fresh()->balance)->toBe('1000.00000000');
    DB::unprepared('DROP TRIGGER test_wealth_audit_failure ON audit_logs; DROP FUNCTION test_wealth_audit_failure()');
    $this->artisan('wealth:recover')->assertSuccessful();
    expect($order->fresh()->status)->toBe('MATURED');
    expect(fn () => DB::transaction(function () use ($order) {
        app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'USDT', 'wealth_unlinked:'.Str::uuid(), 'WEALTH_INTEREST', 'WEALTH_ORDER', $order->id, null, [new LedgerPostingInstruction($this->clearing->id, Money::of('-1', 'USDT')), new LedgerPostingInstruction($this->account->id, Money::of('1', 'USDT'))]));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }))->toThrow(QueryException::class);
});

it('exposes platform configuration and read-only company settings', function () {
    $this->actingAs($this->admin, 'platform_admin')->get("http://admin.localhost/platform/tenants/{$this->tenant->id}/configuration/wealth")->assertOk();
    $otherAdmin = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($otherAdmin, 'tenant_admin')->get('http://a.localhost/admin/wealth')->assertOk();
    $this->post('http://a.localhost/admin/wealth', ['settings' => []])->assertStatus(405);
    $settings = app(WealthConfiguration::class)->get($this->tenant->id);
    $this->actingAs($this->admin, 'platform_admin')->post("http://admin.localhost/platform/tenants/{$this->tenant->id}/configuration/wealth", ['settings' => $settings])->assertRedirect();
    expect(app(WealthConfiguration::class)->get($this->tenant->id)[0]['revision'])->not->toBe($settings[0]['revision']);
});

it('settles tiny zero-interest installments without creating zero ledger postings', function () {
    $settings = app(WealthConfiguration::class)->get($this->tenant->id);
    $settings[1]['minimum'] = '0.000001';
    app(WealthConfiguration::class)->save($this->admin, $this->tenant->id, ['settings' => $settings]);
    $wallet = DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $this->user, 'USDC'));
    $account = LedgerAccount::where('wallet_id', $wallet->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $clearing = LedgerAccount::where('tenant_id', $this->tenant->id)->where('asset_code', 'USDC')->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
    app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'USDC', 'wealth_tiny:'.Str::uuid(), 'TEST_TOPUP', null, null, null, [new LedgerPostingInstruction($clearing->id, Money::of('-0.000001', 'USDC')), new LedgerPostingInstruction($account->id, Money::of('0.000001', 'USDC'))]));
    $revision = app(WealthConfiguration::class)->get($this->tenant->id)[1]['revision'];
    $order = $this->service->deposit($this->tenant->id, $this->user->id, 'USDC', '0.000001', 60, $revision, (string) Str::uuid());
    $this->travelTo($order->matures_at);
    $this->service->settle($this->tenant->id, $order->id);
    expect($order->fresh()->status)->toBe('MATURED')->and($account->fresh()->balance)->toBe('0.000001');
    expect(DB::table('wealth_installments')->where('order_id', $order->id)->whereNotNull('settled_at')->count())->toBe(60);
    expect(DB::table('ledger_entries')->where('reference_id', $order->id)->count())->toBe(2);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('summarizes actual monthly receipts and clawbacks without creating money on reads', function () {
    $this->travelTo(CarbonImmutable::parse('2025-12-31 20:00:00', 'Asia/Kuala_Lumpur'));
    $order = ($this->deposit)();
    $this->travelTo(CarbonImmutable::parse('2026-02-01 00:10:00', 'Asia/Kuala_Lumpur'));
    $this->service->settle($this->tenant->id, $order->id);
    $overview = app(WealthOverview::class);
    $before = $overview->get($this->tenant->id, $this->user->id);
    expect(BigDecimalForWealth($before['principalEstimate']))->toBe('1000');
    $usdt = $before['assets'][0];
    expect($usdt['count'])->toBe(1)->and(count($usdt['months']))->toBe(12);
    $feb = collect($usdt['months'])->firstWhere('month', '2026-02');
    expect(BigDecimalForWealth($feb['paid']))->toBe('6.66666666');
    $this->travelTo(CarbonImmutable::parse('2026-03-01 00:10:00', 'Asia/Kuala_Lumpur'));
    $this->service->cancel($this->tenant->id, $this->user->id, $order->id, (string) Str::uuid(), 'local-password', '6.66666666');
    $after = $overview->get($this->tenant->id, $this->user->id);
    expect(BigDecimalForWealth($after['principalEstimate']))->toBe('0')
        ->and(BigDecimalForWealth($after['netEstimate']))->toBe('0')
        ->and($after['assets'][0]['count'])->toBe(0);
    $march = collect($after['assets'][0]['months'])->firstWhere('month', '2026-03');
    expect(BigDecimalForWealth($march['net']))->toBe('-6.66666666');
    $counts = [DB::table('ledger_entries')->count(), DB::table('wallets')->count(), DB::table('ledger_accounts')->count()];
    $overview->get($this->tenant->id, $this->user->id);
    expect([DB::table('ledger_entries')->count(), DB::table('wallets')->count(), DB::table('ledger_accounts')->count()])->toBe($counts);
    $other = User::where('tenant_id', '!=', $this->tenant->id)->firstOrFail();
    $empty = $overview->get($this->tenant->id, $other->id);
    expect(BigDecimalForWealth($empty['netEstimate']))->toBe('0')->and($empty['assets'][0]['count'])->toBe(0);
});

function BigDecimalForWealth(string $amount): string
{
    return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
}

it('serves the overview and only supported scoped currency pages', function () {
    $this->actingAs($this->user, 'tenant_user');
    $this->get('http://a.localhost/wealth')->assertOk()->assertInertia(fn ($page) => $page->component('user/WealthOverview')->has('assets', 4)->has('assets.0.months', 12));
    $this->get('http://a.localhost/wealth/assets/ETH')->assertOk()->assertInertia(fn ($page) => $page->component('user/Wealth')->where('selectedAsset', 'ETH'));
    $this->get('http://a.localhost/wealth/assets/USD')->assertNotFound();
});

it('values mixed principals with one fresh snapshot and preserves tiny holdings when prices expire', function () {
    ($this->deposit)();
    $settings = app(WealthConfiguration::class)->get($this->tenant->id);
    foreach ($settings as &$setting) {
        $setting['minimum'] = '0.000001';
    }
    unset($setting);
    app(WealthConfiguration::class)->save($this->admin, $this->tenant->id, ['settings' => $settings]);
    foreach (['USDC', 'ETH', 'BTC'] as $asset) {
        $wallet = DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $this->user, $asset));
        $available = LedgerAccount::where('wallet_id', $wallet->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
        $clearing = LedgerAccount::where('tenant_id', $this->tenant->id)->where('asset_code', $asset)->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
        app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, $asset, 'overview:'.Str::uuid(), 'TEST_TOPUP', null, null, null, [new LedgerPostingInstruction($clearing->id, Money::of('-1', $asset)), new LedgerPostingInstruction($available->id, Money::of('1', $asset))]));
        $config = collect(app(WealthConfiguration::class)->get($this->tenant->id))->firstWhere('asset', $asset);
        $this->service->deposit($this->tenant->id, $this->user->id, $asset, '0.000001', 1, $config['revision'], (string) Str::uuid());
    }
    MarketSettings::findOrFail(1)->update(['enabled' => true]);
    MarketSnapshot::create(['provider' => 'COINGECKO', 'usd_prices' => ['USDT' => '1', 'USDC' => '1', 'ETH' => '2000', 'BTC' => '60000'], 'observed_at' => now()]);
    $query = app(WealthOverview::class);
    $result = $query->get($this->tenant->id, $this->user->id);
    expect(BigDecimalForWealth($result['principalEstimate']))->toBe('1000.062001');
    expect($result['assets'][2]['share'])->not->toBe('0.00000000');
    $this->travel(121)->seconds();
    $result = $query->get($this->tenant->id, $this->user->id);
    expect($result['principalEstimate'])->toBeNull()->and($result['netEstimate'])->toBeNull()->and($result['assets'][2]['share'])->toBeNull()->and(BigDecimalForWealth($result['assets'][2]['principal']))->toBe('0.000001');
});

it('keeps disabled product history and excludes returned principal at maturity', function () {
    $order = ($this->deposit)(1);
    $settings = app(WealthConfiguration::class)->get($this->tenant->id);
    foreach ($settings as &$setting) {
        foreach ($setting['products'] as &$product) {
            $product['enabled'] = false;
        }
        unset($product);
    }
    unset($setting);
    app(WealthConfiguration::class)->save($this->admin, $this->tenant->id, ['settings' => $settings]);
    $query = app(WealthOverview::class);
    expect(BigDecimalForWealth($query->get($this->tenant->id, $this->user->id)['principalEstimate']))->toBe('1000');
    $this->travelTo($order->matures_at);
    $this->service->settle($this->tenant->id, $order->id);
    $result = $query->get($this->tenant->id, $this->user->id);
    expect(BigDecimalForWealth($result['principalEstimate']))->toBe('0')->and(BigDecimalForWealth($result['netEstimate']))->toBe('5')->and($result['assets'][0]['count'])->toBe(0)->and(count($result['assets']))->toBe(4);
    $page = app(WealthQuery::class)->page($this->tenant->id, $this->user->id);
    expect($page['settings'][0]['products'][0]['enabled'])->toBeFalse()->and($page['orders']->total())->toBe(1);
});

it('exposes only enabled tenant annual rates and range-specific exact chart scales', function () {
    $settings = app(WealthConfiguration::class)->get($this->tenant->id);
    foreach ($settings[0]['products'] as &$product) {
        $product['enabled'] = in_array($product['months'], [1, 3]);
    }
    unset($product);
    $settings[0]['products'][0]['rate'] = '2.5';
    $settings[0]['products'][1]['rate'] = '10';
    foreach ($settings[3]['products'] as &$product) {
        $product['enabled'] = $product['months'] === 1;
    }
    unset($product);
    foreach ($settings[1]['products'] as &$product) {
        $product['enabled'] = false;
    }
    unset($product);
    app(WealthConfiguration::class)->save($this->admin, $this->tenant->id, ['settings' => $settings]);
    $this->revision = app(WealthConfiguration::class)->get($this->tenant->id)[0]['revision'];
    $this->travelTo(CarbonImmutable::parse('2025-12-01 00:00:00', 'Asia/Kuala_Lumpur'));
    $order = ($this->deposit)(1);
    $this->travelTo($order->matures_at);
    $this->service->settle($this->tenant->id, $order->id);
    $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00:00', 'Asia/Kuala_Lumpur'));
    $result = app(WealthOverview::class)->get($this->tenant->id, $this->user->id);
    expect($result['assets'][0]['annualRateMin'])->toBe('2.5')->and($result['assets'][0]['annualRateMax'])->toBe('10')
        ->and($result['assets'][1]['annualRateMin'])->toBeNull()->and($result['assets'][1]['annualRateMax'])->toBeNull()
        ->and(BigDecimalForWealth($result['assets'][0]['charts'][6]['maximum']))->toBe('0')
        ->and(BigDecimalForWealth($result['assets'][0]['charts'][12]['maximum']))->toBe('2.08333333')
        ->and(count($result['assets'][0]['charts'][6]['ratios']))->toBe(6)
        ->and($result['assets'][3]['annualRateMin'])->toBe('6')->and($result['assets'][3]['annualRateMax'])->toBe('6');
    $other = Tenant::where('id', '!=', $this->tenant->id)->firstOrFail();
    $otherResult = app(WealthOverview::class)->get($other->id, $this->user->id);
    expect($otherResult['assets'][0]['annualRateMin'])->toBeNull();
});

it('shows the earliest unpaid interest and aggregates matching installments without settling on reads', function () {
    $query = app(WealthOverview::class);
    expect($query->get($this->tenant->id, $this->user->id)['nextInterest'])->toBeNull();
    $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00', 'Asia/Kuala_Lumpur'));
    $first = ($this->deposit)();
    $second = ($this->deposit)();
    $next = $query->get($this->tenant->id, $this->user->id)['nextInterest'];
    expect(BigDecimalForWealth($next['amounts'][0]['amount']))->toBe('13.33333332')->and($next['overdue'])->toBeFalse();
    $this->travelTo(CarbonImmutable::parse($first->schedule[0]['due_at']));
    expect($query->get($this->tenant->id, $this->user->id)['nextInterest']['overdue'])->toBeTrue();
    $this->service->settle($this->tenant->id, $first->id);
    $next = $query->get($this->tenant->id, $this->user->id)['nextInterest'];
    expect(BigDecimalForWealth($next['amounts'][0]['amount']))->toBe('6.66666666');
    $this->service->cancel($this->tenant->id, $this->user->id, $second->id, (string) Str::uuid(), 'local-password', '0');
    $next = $query->get($this->tenant->id, $this->user->id)['nextInterest'];
    expect($next['dueAt'])->toBe(CarbonImmutable::parse($first->schedule[1]['due_at'])->toIso8601String());
});

it('separates scoped wallet views and excludes cancelled or due deposits from withdrawal', function () {
    $first = ($this->deposit)(1);
    $second = ($this->deposit)(3);
    $query = app(WealthQuery::class);
    expect($query->page($this->tenant->id, $this->user->id, 'USDT', 'withdraw')['orders']->total())->toBe(2);
    $this->travelTo($first->matures_at);
    $withdraw = $query->page($this->tenant->id, $this->user->id, 'USDT', 'withdraw');
    expect($withdraw['orders']->total())->toBe(1)->and($withdraw['orders']->items()[0]['id'])->toBe($second->id)
        ->and($withdraw['orders']->url(2))->toContain('view=withdraw');
    $this->service->cancel($this->tenant->id, $this->user->id, $second->id, (string) Str::uuid(), 'local-password', '0');
    expect($query->page($this->tenant->id, $this->user->id, 'USDT', 'withdraw')['orders']->total())->toBe(0)
        ->and($query->page($this->tenant->id, $this->user->id, 'USDT', 'details')['orders']->total())->toBe(2)
        ->and($query->page($this->tenant->id, $this->user->id, 'ETH', 'details')['orders']->total())->toBe(0);
    $other = User::where('tenant_id', '!=', $this->tenant->id)->firstOrFail();
    expect($query->page($other->tenant_id, $other->id, 'USDT', 'details')['orders']->total())->toBe(0);
    $this->actingAs($this->user, 'tenant_user');
    foreach (['deposit', 'details', 'withdraw'] as $view) {
        $this->get('http://a.localhost/wealth/assets/USDT?view='.$view)->assertOk()->assertInertia(fn ($page) => $page->where('view', $view));
    }
    $this->get('http://a.localhost/wealth/assets/USDT?view=invalid')->assertSessionHasErrors('view');
    $this->get('http://a.localhost/wealth/orders/'.$first->id.'?view=withdraw')->assertOk()->assertInertia(fn ($page) => $page->where('startWithdrawal', true)->where('order.canCancel', false));
});

it('reports maturity display states and net income consistently with the overview', function () {
    $first = ($this->deposit)(1);
    $second = ($this->deposit)(3);
    $query = app(WealthQuery::class);
    expect($query->detail($this->tenant->id, $this->user->id, $first->id)['displayStatus'])->toBe('ACTIVE');
    $this->travelTo($first->matures_at);
    $due = $query->detail($this->tenant->id, $this->user->id, $first->id);
    expect($due['displayStatus'])->toBe('AWAITING_SETTLEMENT')->and($due['status'])->toBe('ACTIVE')->and($due['canCancel'])->toBeFalse();
    $this->service->settle($this->tenant->id, $first->id);
    $this->service->settle($this->tenant->id, $second->id);
    $paid = $this->service->paid($second);
    $this->service->cancel($this->tenant->id, $this->user->id, $second->id, (string) Str::uuid(), 'local-password', $paid);
    expect($query->detail($this->tenant->id, $this->user->id, $first->id)['displayStatus'])->toBe('MATURED')
        ->and($query->detail($this->tenant->id, $this->user->id, $second->id)['displayStatus'])->toBe('CANCELLED');
    $page = $query->page($this->tenant->id, $this->user->id, 'USDT', 'details');
    $overview = app(WealthOverview::class)->get($this->tenant->id, $this->user->id);
    expect(BigDecimalForWealth($page['settings'][0]['net']))->toBe('5')
        ->and(BigDecimalForWealth($page['settings'][0]['net']))->toBe(BigDecimalForWealth($overview['assets'][0]['net']));
});

it('labels wealth movements on asset history without altering ledger', function () {
    $this->travel(1)->seconds();
    ($this->deposit)();
    $before = DB::table('ledger_entries')->count();
    $this->actingAs($this->user, 'tenant_user');
    $this->get('http://a.localhost/assets/USDT/activity')->assertOk()->assertInertia(fn ($page) => $page->component('user/AssetHistory')->where('rows.data.0.kind', 'Wealth deposit debit')->where('rows.data.0.amount', '-1000.00000000'));
    expect(DB::table('ledger_entries')->count())->toBe($before);
    $overview = app(AssetOverviewQuery::class)->get($this->tenant->id, $this->user->id, []);
    expect($overview['assets'][0]['activity'][0]['kind'])->toBe('Wealth deposit debit');
});

it('opens all funds by default and filters balances and rows without provisioning wallets', function () {
    $ethWallet = DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $this->user, 'ETH'));
    $ethAvailable = LedgerAccount::where('wallet_id', $ethWallet->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $ethClearing = LedgerAccount::where('tenant_id', $this->tenant->id)->where('asset_code', 'ETH')->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
    app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'ETH', 'funds_test:'.Str::uuid(), 'ASSET_DEPOSIT', null, null, null, [new LedgerPostingInstruction($ethClearing->id, Money::of('-0.5', 'ETH')), new LedgerPostingInstruction($ethAvailable->id, Money::of('0.5', 'ETH'))]));

    $this->travel(1)->seconds();
    ($this->deposit)();
    $this->actingAs($this->user, 'tenant_user');
    $before = [DB::table('wallets')->count(), DB::table('ledger_entries')->count()];
    $this->get('http://a.localhost/funds')->assertOk()->assertInertia(fn ($page) => $page->where('selectedAsset', 'ALL')->has('balances', 4)->where('balances.0.available', '1000.00000000')->where('rows.data.0.asset', 'USDT')->where('rows.data.0.kind', 'Wealth deposit debit'));
    $this->get('http://a.localhost/funds?asset=ETH')->assertOk()->assertInertia(fn ($page) => $page->where('selectedAsset', 'ETH')->has('rows.data', 1)->where('rows.data.0.asset', 'ETH')->where('rows.data.0.amount', '0.500000000000000000'));
    $this->get('http://a.localhost/funds?asset=BTC')->assertOk()->assertInertia(fn ($page) => $page->has('rows.data', 0));
    $this->get('http://a.localhost/funds?asset=invalid')->assertSessionHasErrors('asset');
    $result = app(FundsQuery::class)->get($this->tenant->id, $this->user->id, 'USDT');
    expect($result['rows']->url(2))->toContain('/funds?')->toContain('asset=USDT');
    $other = User::where('tenant_id', '!=', $this->tenant->id)->firstOrFail();
    $empty = app(FundsQuery::class)->get($other->tenant_id, $this->user->id);
    expect($empty['rows']->total())->toBe(0);
    expect([DB::table('wallets')->count(), DB::table('ledger_entries')->count()])->toBe($before);
});
