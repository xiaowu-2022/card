<?php

use App\Application\Admin\FinancialOperationQuery;
use App\Application\Assets\AssetAccess;
use App\Application\Assets\AssetOverviewQuery;
use App\Application\Assets\ConfigureAssetsAction;
use App\Application\Assets\DepositAssetsAction;
use App\Application\Assets\ExchangeAssetsAction;
use App\Application\Assets\MarketPrices;
use App\Application\Assets\ScanAssetNetwork;
use App\Application\Assets\WithdrawAssetsAction;
use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Application\Withdrawal\CreateWithdrawalAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetDepositOrder;
use App\Domain\Assets\AssetRail;
use App\Domain\Assets\ChainConnection;
use App\Domain\Assets\ChainObservation;
use App\Domain\Assets\CompanyRail;
use App\Domain\Assets\ExchangeOrder;
use App\Domain\Assets\ExchangePolicy;
use App\Domain\Assets\MarketSettings;
use App\Domain\Assets\MarketSnapshot;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerReconciliationService;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Infrastructure\Assets\ChainReader;
use App\Infrastructure\Assets\ChainRpc;
use App\Infrastructure\Assets\ExactJson;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $app = app(SubmitKycApplicationAction::class)->execute($this->tenant, $this->user, 'MY', 'ASSET-'.$this->user->id, kycTestImage(), kycTestImage());
    app(ApproveKycAction::class)->execute($this->tenant->id, $app->id, AdminUser::where('email', 'owner@a.localhost')->firstOrFail());
    app(ActivateUserWalletAction::class)->execute($this->tenant->id, $this->user->id);
    ChainConnection::where('network', 'ETHEREUM')->update(['enabled' => true, 'start_height' => 100, 'next_height' => 100]);
    foreach (['ETH_ETHEREUM', 'USDC_ETHEREUM', 'USDT_ETHEREUM'] as $code) {
        AssetRail::whereKey($code)->update(['enabled' => true, 'deposit_address' => '0x'.str_repeat('1', 40)]);
        CompanyRail::create(['tenant_id' => $this->tenant->id, 'rail_code' => $code, 'deposit_enabled' => true, 'withdrawal_enabled' => true, 'minimum_deposit' => '0.000001', 'withdrawal_fee' => '0.000001']);
    }
    MarketSettings::whereKey(1)->update(['enabled' => true]);
    MarketSnapshot::create(['provider' => 'COINGECKO', 'usd_prices' => ['USDT' => '0.999', 'USDC' => '0.998', 'ETH' => '2000', 'BTC' => '60000'], 'observed_at' => now()]);
    ExchangePolicy::create(['tenant_id' => $this->tenant->id, 'asset_code' => 'ETH', 'enabled' => true, 'fee_percent' => '0.1', 'single_limit' => '5000', 'daily_limit' => '10000']);
    $this->fund = function (string $asset, string $amount) {
        DB::transaction(function () use ($asset, $amount) {
            $access = app(AssetAccess::class);
            [$t,$u] = $access->operational($this->tenant->id, $this->user->id);
            $wallet = $access->wallet($t, $u, $asset);
            $account = $access->account($wallet, 'USER_AVAILABLE');
            $clearing = $access->companyAccount($t->id, $asset, 'TENANT_TOPUP_CLEARING');
            app(LedgerWriter::class)->post(new LedgerPostingPlan($t->id, $asset, 'test:'.Str::uuid(), 'TEST_DEPOSIT', null, null, null, [new LedgerPostingInstruction($account->id, Money::of($amount, $asset)), new LedgerPostingInstruction($clearing->id, Money::of('-'.$amount, $asset))]));
        });
    };
});
it('retains one wei without floating point or rounded postings', function () {
    ($this->fund)('ETH', '0.000000000000000001');
    expect(LedgerAccount::where('user_id', $this->user->id)->where('asset_code', 'ETH')->where('account_type', 'USER_AVAILABLE')->first()->balance)->toBe('0.000000000000000001');
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    expect(ExactJson::decode('{"price":0.000000000000000001,"n":123456789123456789,"s":"12"}'))->toBe(['price' => '0.000000000000000001', 'n' => '123456789123456789', 's' => '12']);
});
it('exchanges atomically using saved economics and replays the same pair', function () {
    ($this->fund)('ETH', '1');
    $action = app(ExchangeAssetsAction::class);
    $q = $action->quote($this->tenant->id, $this->user->id, 'ETH', '0.1', (string) Str::uuid());
    expect($q->receive_amount)->toBe('199.99999999');
    ExchangePolicy::where('asset_code', 'ETH')->update(['fee_percent' => '5']);
    $done = $action->confirm($this->tenant->id, $this->user->id, $q->id);
    expect($done->status)->toBe('COMPLETED')->and($done->fee_percent)->toBe('0.10000000');
    $count = LedgerEntry::count();
    $again = $action->confirm($this->tenant->id, $this->user->id, $q->id);
    expect($again->target_entry_id)->toBe($done->target_entry_id)->and(LedgerEntry::count())->toBe($count);
    expect(LedgerAccount::where('user_id', $this->user->id)->where('asset_code', 'ETH')->where('account_type', 'USER_AVAILABLE')->first()->balance)->toBe('0.900000000000000000');
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
});
it('rejects expired quotes without partial exchange', function () {
    ($this->fund)('ETH', '1');
    $action = app(ExchangeAssetsAction::class);
    $q = $action->quote($this->tenant->id, $this->user->id, 'ETH', '0.1', (string) Str::uuid());
    $before = LedgerEntry::count();
    $this->travel(31)->seconds();
    expect(fn () => $action->confirm($this->tenant->id, $this->user->id, $q->id))->toThrow(DomainException::class);
    expect(LedgerEntry::count())->toBe($before);
});
it('rolls back both legs when USDT cannot be credited', function () {
    ($this->fund)('ETH', '1');
    $action = app(ExchangeAssetsAction::class);
    $q = $action->quote($this->tenant->id, $this->user->id, 'ETH', '0.1', (string) Str::uuid());
    $before = LedgerEntry::count();
    LedgerAccount::where('user_id', $this->user->id)->where('asset_code', 'USDT')->where('account_type', 'USER_AVAILABLE')->update(['status' => 'CLOSED']);
    expect(fn () => $action->confirm($this->tenant->id, $this->user->id, $q->id))->toThrow(DomainException::class);
    expect(LedgerEntry::count())->toBe($before)->and($q->fresh()->status)->toBe('QUOTED');
    expect(LedgerAccount::where('user_id', $this->user->id)->where('asset_code', 'ETH')->where('account_type', 'USER_AVAILABLE')->first()->balance)->toBe('1.000000000000000000');
});
it('allocates immutable exact amounts and replays deposit orders', function () {
    $action = app(DepositAssetsAction::class);
    $request = (string) Str::uuid();
    $first = $action->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '1', $request);
    $second = $action->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '1', (string) Str::uuid());
    expect($second->amount)->toBe('1.000000000000000001')->and($action->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '1', $request)->id)->toBe($first->id);
});
it('holds the original asset and cancels only before approval', function () {
    ($this->fund)('ETH', '1');
    $action = app(WithdrawAssetsAction::class);
    $request = (string) Str::uuid();
    $o = $action->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '0.2', '0x'.str_repeat('2', 40), '0.000001', $request, true);
    expect($action->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '0.2', '0x'.str_repeat('2', 40), '0.000001', $request, true)->id)->toBe($o->id);
    $action->cancel($this->tenant->id, $this->user->id, $o->id);
    expect($o->fresh()->status)->toBe('CANCELLED');
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
});

it('rejects stale prices and quotes that exceed configured limits', function () {
    ($this->fund)('ETH', '10');
    $action = app(ExchangeAssetsAction::class);
    expect(fn () => $action->quote($this->tenant->id, $this->user->id, 'ETH', '3', (string) Str::uuid()))->toThrow(DomainException::class);
    $this->travel(121)->seconds();
    expect(fn () => $action->quote($this->tenant->id, $this->user->id, 'ETH', '0.1', (string) Str::uuid()))->toThrow(DomainException::class);
    expect(ExchangeOrder::count())->toBe(0);
});
it('prevents overspending with two separately quoted exchanges', function () {
    ($this->fund)('ETH', '1');
    $action = app(ExchangeAssetsAction::class);
    $a = $action->quote($this->tenant->id, $this->user->id, 'ETH', '0.8', (string) Str::uuid());
    $b = $action->quote($this->tenant->id, $this->user->id, 'ETH', '0.8', (string) Str::uuid());
    $action->confirm($this->tenant->id, $this->user->id, $a->id);
    $count = LedgerEntry::count();
    expect(fn () => $action->confirm($this->tenant->id, $this->user->id, $b->id))->toThrow(DomainException::class);
    expect(LedgerEntry::count())->toBe($count)->and($b->fresh()->status)->toBe('QUOTED');
});
it('uses a floating USDC cross price and six-decimal source amount', function () {
    ($this->fund)('USDC', '10.123456');
    ExchangePolicy::create(['tenant_id' => $this->tenant->id, 'asset_code' => 'USDC', 'enabled' => true, 'fee_percent' => '0', 'single_limit' => '5000', 'daily_limit' => '10000']);
    $a = app(ExchangeAssetsAction::class);
    $q = $a->quote($this->tenant->id, $this->user->id, 'USDC', '10', (string) Str::uuid());
    expect($q->receive_amount)->toBe('9.98998998')->and($q->fee_amount)->toBe('0.00000000');
    $a->confirm($this->tenant->id, $this->user->id, $q->id);
    expect(LedgerAccount::where('user_id', $this->user->id)->where('asset_code', 'USDC')->where('account_type', 'USER_AVAILABLE')->first()->balance)->toBe('0.123456');
    expect(fn () => Money::of('0.0000001', 'USDC'))->toThrow(InvalidArgumentException::class);
});
it('shares manual and verified credit paths without duplicate credit', function () {
    $deposits = app(DepositAssetsAction::class);
    $o = $deposits->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '1', (string) Str::uuid());
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $deposits->manual($this->tenant->id, $o->id, $actor, (string) Str::uuid(), true);
    $before = LedgerEntry::count();
    $obs = ChainObservation::create(['network' => 'ETHEREUM', 'event_id' => 'tx:trace:0', 'rail_code' => $o->rail_code, 'address' => $o->address, 'amount' => $o->amount, 'block_height' => 101, 'block_hash' => 'block', 'occurred_at' => now(), 'status' => 'MATCHED', 'order_id' => $o->id]);
    $deposits->verified($obs);
    $deposits->verified($obs);
    expect(LedgerEntry::count())->toBe($before)->and($obs->fresh()->status)->toBe('ALREADY_CREDITED');
    expect($o->fresh()->manual_confirmed_by)->toBe($actor->id);
    $next = $deposits->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '1', (string) Str::uuid());
    expect($next->amount)->toBe('1.000000000000000001');
});
it('does not allow company admins or expired orders to use manual confirmation', function () {
    $a = app(DepositAssetsAction::class);
    $o = $a->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '1', (string) Str::uuid());
    expect(fn () => $a->manual($this->tenant->id, $o->id, AdminUser::where('email', 'owner@a.localhost')->firstOrFail(), (string) Str::uuid(), true))->toThrow(HttpException::class);
    $this->travel(31)->minutes();
    expect(fn () => $a->manual($this->tenant->id, $o->id, AdminUser::where('email', 'owner@platform.local')->firstOrFail(), (string) Str::uuid(), true))->toThrow(DomainException::class);
});
it('keeps deposit and commission accounts untouched during exchange', function () {
    ($this->fund)('ETH', '1');
    $before = LedgerAccount::where('user_id', $this->user->id)->whereIn('account_type', ['USER_COMMISSION', 'USER_SECURITY_DEPOSIT'])->get()->map(fn ($a) => [$a->id, $a->balance])->all();
    $a = app(ExchangeAssetsAction::class);
    $q = $a->quote($this->tenant->id, $this->user->id, 'ETH', '0.1', (string) Str::uuid());
    $a->confirm($this->tenant->id, $this->user->id, $q->id);
    expect(LedgerAccount::where('user_id', $this->user->id)->whereIn('account_type', ['USER_COMMISSION', 'USER_SECURITY_DEPOSIT'])->get()->map(fn ($a) => [$a->id, $a->balance])->all())->toBe($before);
});
it('renders read-only currency balances and blocks cross-tenant order reads', function () {
    ($this->fund)('ETH', '0.000000000000000001');
    $count = Wallet::count();
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('assetOverview.assets.2.available', '0.000000000000000001'));
    expect(Wallet::count())->toBe($count);
    $o = app(DepositAssetsAction::class)->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '1', (string) Str::uuid());
    $b = User::where('tenant_id', Tenant::where('slug', 'tenant-b')->value('id'))->firstOrFail();
    $this->actingAs($b, 'tenant_user')->get('http://b.localhost/assets/operate?mode=deposit&order='.$o->id)->assertNotFound();
});

/** Deterministic node responses, never a real RPC request. */
function assetEthereumNode(array $calls, array $logs = []): void
{
    config(['assets.rpc_allowed_hosts' => ['node.example.test'], 'assets.scan_batch_blocks' => 1]);
    ChainConnection::where('network', 'ETHEREUM')->update(['rpc_url' => 'https://node.example.test']);
    $hash = '0x'.str_repeat('a', 64);
    $tx = '0x'.str_repeat('b', 64);
    $block = ['number' => '0x64', 'hash' => $hash, 'timestamp' => '0x'.dechex(now()->timestamp), 'transactions' => [['hash' => $tx]]];
    Http::fake(function ($request) use ($calls, $logs, $hash, $tx, $block) {
        $value = match ($request['method']) {
            'eth_chainId' => '0x1','eth_getBlockByNumber' => $block,
            'debug_traceBlockByNumber' => [['txHash' => $tx, 'result' => ['type' => 'CALL', 'from' => '0x'.str_repeat('3', 40), 'to' => '0x'.str_repeat('4', 40), 'value' => '0x0', 'calls' => $calls]]],
            'eth_getTransactionReceipt' => ['status' => '0x1', 'blockNumber' => '0x64', 'blockHash' => $hash, 'transactionHash' => $tx, 'logs' => $logs],
            default => throw new RuntimeException('Unexpected RPC method'),
        };

        return Http::response(json_encode(['jsonrpc' => '2.0', 'id' => 'assets', 'result' => $value], JSON_THROW_ON_ERROR));
    });
}
it('scans finalized internal ETH transfers once and preserves the cursor', function () {
    $o = app(DepositAssetsAction::class)->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '1', (string) Str::uuid());
    assetEthereumNode([['type' => 'CALL', 'from' => '0x'.str_repeat('4', 40), 'to' => $o->address, 'value' => '0xde0b6b3a7640000']]);
    $scan = app(ScanAssetNetwork::class);
    expect($scan->execute('ETHEREUM'))->toBe(1);
    expect($o->fresh()->status)->toBe('CREDITED');
    $count = LedgerEntry::count();
    $scan->execute('ETHEREUM');
    expect(LedgerEntry::count())->toBe($count);
    expect(ChainConnection::find('ETHEREUM')->next_height)->toBe(101);
});
it('sends ambiguous duplicate transfers to review without guessing an order', function () {
    $o = app(DepositAssetsAction::class)->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '1', (string) Str::uuid());
    $call = ['type' => 'CALL', 'to' => $o->address, 'value' => '0xde0b6b3a7640000'];
    assetEthereumNode([$call, $call]);
    app(ScanAssetNetwork::class)->execute('ETHEREUM');
    expect($o->fresh()->status)->toBe('PENDING')->and(ChainObservation::where('status', 'REQUIRES_REVIEW')->count())->toBe(2);
});
it('ignores reverted native calls and wrong token contracts', function () {
    $o = app(DepositAssetsAction::class)->create($this->tenant->id, $this->user->id, 'USDC_ETHEREUM', '1', (string) Str::uuid());
    assetEthereumNode([['type' => 'CALL', 'to' => $o->address, 'value' => '0xde0b6b3a7640000', 'error' => 'execution reverted']], [[
        'address' => '0x'.str_repeat('f', 40), 'topics' => ['0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef', '0x'.str_repeat('0', 64), '0x'.str_repeat('0', 24).substr($o->address, 2)], 'data' => '0x'.str_pad('f4240', 64, '0', STR_PAD_LEFT), 'logIndex' => '0x0',
    ]]);
    app(ScanAssetNetwork::class)->execute('ETHEREUM');
    expect($o->fresh()->status)->toBe('PENDING')->and(ChainObservation::count())->toBe(1);
});
it('stops on a changed checkpoint without rewriting money', function () {
    ChainConnection::where('network', 'ETHEREUM')->update(['next_height' => 101, 'checkpoint_hash' => 'old-block']);
    assetEthereumNode([]);
    expect(fn () => app(ScanAssetNetwork::class)->execute('ETHEREUM'))->toThrow(DomainException::class);
    expect(ChainConnection::find('ETHEREUM')->enabled)->toBeFalse()->and(ChainConnection::find('ETHEREUM')->next_height)->toBe(101);
});
it('keeps payout UNKNOWN holds and prevents cancellation or a different transaction', function () {
    ($this->fund)('ETH', '1');
    $a = app(WithdrawAssetsAction::class);
    $o = $a->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '0.2', '0x'.str_repeat('2', 40), '0.000001', (string) Str::uuid(), true);
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $a->review($this->tenant->id, $o->id, $actor, true);
    assetEthereumNode([]);
    $a->verify($this->tenant->id, $o->id, $actor, '0x'.str_repeat('b', 64), (string) Str::uuid());
    expect($o->fresh()->status)->toBe('UNKNOWN');
    expect(fn () => $a->cancel($this->tenant->id, $this->user->id, $o->id))->toThrow(DomainException::class);
    expect(fn () => $a->verify($this->tenant->id, $o->id, $actor, '0x'.str_repeat('c', 64), (string) Str::uuid()))->toThrow(DomainException::class);
    expect(LedgerAccount::where('user_id', $this->user->id)->where('asset_code', 'ETH')->where('account_type', 'USER_WITHDRAWAL_HOLD')->first()->balance)->toBe('0.200000000000000000');
});

it('reads Bitcoin outputs at six confirmations with exact satoshis', function () {
    config(['assets.rpc_allowed_hosts' => ['btc.example.test']]);
    $c = ChainConnection::findOrFail('BITCOIN');
    $c->update(['enabled' => true, 'rpc_url' => 'https://btc.example.test', 'start_height' => 100, 'next_height' => 100]);
    Http::fake(function ($request) {
        $result = match ($request['method']) {
            'getblockchaininfo' => ['chain' => 'main', 'blocks' => 105, 'initialblockdownload' => false],
            'getblockhash' => 'block100',
            'getblock' => ['hash' => 'block100', 'height' => 100, 'time' => now()->timestamp, 'tx' => [['txid' => 'reward', 'vin' => [['coinbase' => 'reward']], 'vout' => [['n' => 0, 'value' => '3.125', 'scriptPubKey' => ['address' => 'recipient']]]], ['txid' => 'one', 'vout' => [['n' => 0, 'value' => '0.00000001', 'scriptPubKey' => ['address' => 'recipient']], ['n' => 1, 'value' => '0.12345678', 'scriptPubKey' => ['address' => 'recipient2']]]]]],
            default => throw new RuntimeException('Unexpected RPC method'),
        };

        return Http::response(json_encode(['result' => $result], JSON_THROW_ON_ERROR));
    });
    $reader = app(ChainReader::class);
    expect($reader->finalHeight($c))->toBe(100);
    $proof = $reader->block($c, 100);
    expect($proof['transfers'])->toHaveCount(2);
    expect($proof['transfers'][0]['amount'])->toBe('0.00000001')->and($proof['transfers'][1]['event_id'])->toBe('one:vout:1');
});
it('rejects SSRF endpoints and fails closed before requesting arbitrary hosts', function () {
    $c = ChainConnection::find('ETHEREUM');
    $c->update(['rpc_url' => 'https://127.0.0.1/internal']);
    Http::fake();
    expect(fn () => app(ChainRpc::class)->call($c, 'eth_chainId'))->toThrow(DomainException::class);
    Http::assertNothingSent();
});

it('permits platform-only configuration, encrypts keys and never returns them', function () {
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($actor, 'platform_admin')->post('http://admin.localhost/platform/settings/assets', ['kind' => 'market', 'enabled' => true, 'api_key' => 'isolated-price-key', 'password' => 'local-password'])->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('asset_market_settings')->where('id', 1)->value('api_key'))->not->toContain('isolated-price-key');
    $this->get('http://admin.localhost/platform/settings/assets')->assertOk()->assertDontSee('isolated-price-key')->assertInertia(fn ($page) => $page->where('market.configured', true));
    $this->post('http://admin.localhost/platform/tenants/'.$this->tenant->id.'/assets/settings', ['kind' => 'exchange', 'asset' => 'BTC', 'enabled' => true, 'fee' => '0', 'single' => '100', 'daily' => '200', 'password' => 'local-password'])->assertRedirect();
    expect(ExchangePolicy::where('tenant_id', $this->tenant->id)->where('asset_code', 'BTC')->first()->fee_percent)->toBe('0.00000000');
    $company = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    expect(fn () => app(ConfigureAssetsAction::class)->execute($company, ['kind' => 'market', 'enabled' => true, 'api_key' => 'forbidden', 'password' => 'local-password']))->toThrow(HttpException::class);
});
it('submits scoped deposit and exchange HTTP requests with stable identifiers', function () {
    $request = (string) Str::uuid();
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/assets/orders', ['mode' => 'deposit', 'asset' => 'ETH', 'rail' => 'ETH_ETHEREUM', 'amount' => '0.5', 'request_id' => $request])->assertRedirect();
    expect(AssetDepositOrder::where('request_id', $request)->count())->toBe(1);
    $this->post('http://a.localhost/assets/orders', ['mode' => 'deposit', 'asset' => 'BTC', 'rail' => 'ETH_ETHEREUM', 'amount' => '0.5', 'request_id' => (string) Str::uuid()])->assertStatus(422);
    ($this->fund)('ETH', '1');
    $this->post('http://a.localhost/assets/orders', ['mode' => 'exchange', 'asset' => 'ETH', 'amount' => '0.1', 'request_id' => (string) Str::uuid()])->assertRedirect();
});
it('settles a uniquely proven ETH payout once with fee accounting', function () {
    ($this->fund)('ETH', '1');
    $a = app(WithdrawAssetsAction::class);
    $o = $a->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '0.2', '0x'.str_repeat('2', 40), '0.000001', (string) Str::uuid(), true);
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $a->review($this->tenant->id, $o->id, $actor, true);
    $wei = BigDecimal::of('0.199999')->withPointMovedRight(18)->toBigInteger()->toBase(16);
    assetEthereumNode([['type' => 'CALL', 'to' => $o->address, 'value' => '0x'.$wei]]);
    $result = $a->verify($this->tenant->id, $o->id, $actor, '0x'.str_repeat('b', 64), (string) Str::uuid());
    expect($result->status)->toBe('COMPLETED');
    $history = app(FinancialOperationQuery::class)->forOrder($this->tenant->id, 'asset_withdrawal_order', $o->id);
    expect($history[0]['action'])->toBe('ASSET_WITHDRAWAL_COMPLETED')->and($history[0]['operatorId'])->toBe($actor->id);
    $count = LedgerEntry::count();
    $a->verify($this->tenant->id, $o->id, $actor, '0x'.str_repeat('b', 64), (string) Str::uuid());
    expect(LedgerEntry::count())->toBe($count);
    expect(LedgerAccount::where('user_id', $this->user->id)->where('asset_code', 'ETH')->where('account_type', 'USER_WITHDRAWAL_HOLD')->first()->balance)->toBe('0.000000000000000000');
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
});

it('rechecks funds after a withdrawal consumes a quoted balance and enforces the daily cap', function () {
    ($this->fund)('ETH', '1');
    $exchange = app(ExchangeAssetsAction::class);
    $quote = $exchange->quote($this->tenant->id, $this->user->id, 'ETH', '0.8', (string) Str::uuid());
    $withdraw = app(WithdrawAssetsAction::class)->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '0.4', '0x'.str_repeat('2', 40), '0.000001', (string) Str::uuid(), true);
    $entries = LedgerEntry::count();
    expect(fn () => $exchange->confirm($this->tenant->id, $this->user->id, $quote->id))->toThrow(DomainException::class);
    expect(LedgerEntry::count())->toBe($entries)->and($quote->fresh()->status)->toBe('QUOTED');
    app(WithdrawAssetsAction::class)->cancel($this->tenant->id, $this->user->id, $withdraw->id);
    ExchangePolicy::where('tenant_id', $this->tenant->id)->where('asset_code', 'ETH')->update(['single_limit' => '2000', 'daily_limit' => '2000']);
    $exchange->confirm($this->tenant->id, $this->user->id, $quote->id);
    $next = $exchange->quote($this->tenant->id, $this->user->id, 'ETH', '0.2', (string) Str::uuid());
    expect(fn () => $exchange->confirm($this->tenant->id, $this->user->id, $next->id))->toThrow(DomainException::class);
});

it('exposes existing TRON withdrawals to SaaS with scoped review and no alternate settlement', function () {
    ($this->fund)('USDT', '100');
    $order = app(CreateWithdrawalAction::class)->executeWithAddress($this->tenant->id, $this->user->id, (string) Str::uuid(), 'T'.str_repeat('A', 33), '10', null, '0');
    $platform = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($platform, 'platform_admin')->get('http://admin.localhost/platform/asset-tron-withdrawals')->assertOk()->assertInertia(fn ($page) => $page->where('orders.data.0.id', $order->id)->where('orders.data.0.legacy', true));
    $other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $this->post("http://admin.localhost/platform/tenants/{$other->id}/asset-tron-withdrawals/{$order->id}/review", ['approve' => true, 'confirmed' => true])->assertNotFound();
    $this->post("http://admin.localhost/platform/tenants/{$this->tenant->id}/asset-tron-withdrawals/{$order->id}/review", ['approve' => true, 'confirmed' => true])->assertRedirect();
    expect($order->fresh()->status->value)->toBe('APPROVED')->and($order->fresh()->reviewed_by_admin_user_id)->toBe($platform->id);
    $this->post("http://admin.localhost/platform/tenants/{$this->tenant->id}/asset-tron-withdrawals/{$order->id}/reveal", ['password' => 'wrong'])->assertForbidden();
});

it('rejects company network amounts outside the exact currency precision', function () {
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    foreach (['0', '0.0000001'] as $minimum) {
        expect(fn () => app(ConfigureAssetsAction::class)->execute($actor, ['kind' => 'company-rail', 'code' => 'USDC_ETHEREUM', 'deposit_enabled' => true, 'withdrawal_enabled' => false, 'minimum' => $minimum, 'password' => 'local-password'], $this->tenant))->toThrow(DomainException::class);
    }
});

/** Real independent PostgreSQL sessions; never permitted against the development/live database. */
function raceAssetOperations(array $operations): array
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

it('serializes actual simultaneous exchange and withdrawal requests without overspending', function () {
    ($this->fund)('ETH', '1');
    $tenant = $this->tenant->id;
    $user = $this->user->id;
    $quote = app(ExchangeAssetsAction::class)->quote($tenant, $user, 'ETH', '0.8', (string) Str::uuid());
    $results = raceAssetOperations([
        fn () => app(ExchangeAssetsAction::class)->confirm($tenant, $user, $quote->id),
        fn () => app(WithdrawAssetsAction::class)->create($tenant, $user, 'ETH_ETHEREUM', '0.4', '0x'.str_repeat('2', 40), '0.000001', (string) Str::uuid(), true),
    ]);
    sort($results);
    expect($results)->toBe(['completed', 'rejected']);
    expect(LedgerAccount::where('user_id', $user)->where('balance', '<', 0)->exists())->toBeFalse();
    expect(app(LedgerReconciliationService::class)->mismatches())->toBe([]);
});

it('credits exactly once when manual receipt confirmation races automatic verification', function () {
    $order = app(DepositAssetsAction::class)->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '0.1', (string) Str::uuid());
    $observation = ChainObservation::create(['network' => 'ETHEREUM', 'event_id' => 'concurrent:trace:0', 'rail_code' => $order->rail_code, 'address' => $order->address, 'amount' => $order->amount, 'block_height' => 100, 'block_hash' => 'block100', 'occurred_at' => now(), 'status' => 'MATCHED', 'order_id' => $order->id]);
    $tenant = $this->tenant->id;
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $results = raceAssetOperations([
        fn () => app(DepositAssetsAction::class)->manual($tenant, $order->id, $actor, (string) Str::uuid(), true),
        fn () => app(DepositAssetsAction::class)->verified($observation),
    ]);
    expect($results)->toBe(['completed', 'completed']);
    expect(LedgerEntry::where('event_key', 'asset_deposit:'.$order->id.':credit')->count())->toBe(1);
    expect(LedgerAccount::where('user_id', $order->user_id)->where('asset_code', 'ETH')->where('account_type', 'USER_AVAILABLE')->first()->balance)->toBe('0.100000000000000000');
});

it('values USDT without external prices and ignores empty foreign accounts', function (string $marketState) {
    if ($marketState === 'disabled') {
        MarketSettings::whereKey(1)->update(['enabled' => false]);
    } else {
        $this->travel(121)->seconds();
    }
    $query = app(AssetOverviewQuery::class);
    expect($query->get($this->tenant->id, $this->user->id, [])['estimate'])->toBe('0.00000000');
    ($this->fund)('USDT', '1234.56789012');
    $entries = LedgerEntry::count();
    $overview = $query->get($this->tenant->id, $this->user->id, []);
    expect($overview['estimate'])->toBe('1234.56789012')
        ->and($overview['updatedAt'])->toBeNull()
        ->and(LedgerEntry::count())->toBe($entries);
})->with(['disabled', 'stale']);

it('values nonzero foreign holdings at the USDT cross rate and hides incomplete totals', function () {
    ($this->fund)('USDT', '100');
    ($this->fund)('USDC', '1');
    ($this->fund)('ETH', '0.001');
    ($this->fund)('BTC', '0.00001');
    $query = app(AssetOverviewQuery::class);
    $overview = $query->get($this->tenant->id, $this->user->id, []);
    expect($overview['estimate'])->toBe('103.60160160')
        ->and($overview['updatedAt'])->not->toBeNull();
    $this->travel(121)->seconds();
    $stale = $query->get($this->tenant->id, $this->user->id, []);
    expect($stale['estimate'])->toBeNull()
        ->and($stale['updatedAt'])->toBeNull()
        ->and($stale['assets'])->toBe($overview['assets']);
});

it('reports exchange readiness without enabling unconfigured currencies', function () {
    $query = app(AssetOverviewQuery::class);
    $read = fn () => collect($query->get($this->tenant->id, $this->user->id, ['transferAvailable' => true])['assets'])->keyBy('asset');
    $assets = $read();
    expect($assets['ETH']['exchange'])->toBeTrue()
        ->and($assets['ETH']['exchangeUnavailableReason'])->toBeNull()
        ->and($assets['USDC']['exchange'])->toBeFalse()
        ->and($assets['USDC']['exchangeUnavailableReason'])->toBe('Exchange is not enabled for this currency.')
        ->and($assets['USDT']['exchange'])->toBeFalse();
    $this->travel(121)->seconds();
    expect($read()['ETH']['exchangeUnavailableReason'])->toBe('Market prices are unavailable.');
    $restricted = collect($query->get($this->tenant->id, $this->user->id, ['transferAvailable' => false])['assets'])->keyBy('asset');
    expect($restricted['ETH']['exchange'])->toBeFalse()
        ->and($restricted['ETH']['exchangeUnavailableReason'])->toBe('Exchange is unavailable for this account.');
});

it('keeps internal exchange and user valuations independent of every blockchain connection', function () {
    Http::preventStrayRequests();
    ChainConnection::query()->update(['enabled' => false]);
    ($this->fund)('ETH', '1');
    for ($i = 0; $i < 5; $i++) {
        $view = app(AssetOverviewQuery::class)->get($this->tenant->id, $this->user->id, ['transferAvailable' => true]);
        expect(collect($view['assets'])->firstWhere('asset', 'ETH')['exchange'])->toBeTrue();
    }
    $action = app(ExchangeAssetsAction::class);
    $quote = $action->quote($this->tenant->id, $this->user->id, 'ETH', '0.1', (string) Str::uuid());
    expect($action->confirm($this->tenant->id, $this->user->id, $quote->id)->status)->toBe('COMPLETED');
    Http::assertNothingSent();
});

it('allows public market configuration without a key and explicitly removes an old key', function () {
    Http::fake();
    $settings = MarketSettings::findOrFail(1);
    $settings->update(['api_key' => 'old-secret']);
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    app(ConfigureAssetsAction::class)->execute($actor, ['kind' => 'market', 'enabled' => true, 'use_public' => true, 'password' => 'local-password']);
    expect($settings->fresh()->api_key)->toBeNull()->and($settings->fresh()->enabled)->toBeTrue();
    Http::assertNothingSent();
});

it('fetches one exact public snapshot for repeated platform updates', function () {
    $this->travel(121)->seconds();
    $stamp = now()->timestamp;
    Http::fake(['api.coingecko.com/*' => Http::response('{"tether":{"usd":0.999,"last_updated_at":'.$stamp.'},"usd-coin":{"usd":0.998,"last_updated_at":'.$stamp.'},"ethereum":{"usd":2000.123456789123456789,"last_updated_at":'.$stamp.'},"bitcoin":{"usd":60000,"last_updated_at":'.$stamp.'}}')]);
    $prices = app(MarketPrices::class);
    $one = $prices->refresh();
    $two = $prices->refresh();
    expect($one->id)->toBe($two->id)->and($one->usd_prices['ETH'])->toBe('2000.123456789123456789');
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => ! $r->hasHeader('x-cg-pro-api-key'));
});

it('serializes platform refreshes with the shared cache lock', function () {
    $prices = app(MarketPrices::class);
    Http::fake();
    $lock = Cache::store()->lock('assets:market-refresh', 60);
    expect($lock->get())->toBeTrue();
    try {
        expect($prices->refresh()->id)->toBe($prices->latest()->id);
        $this->travel(121)->seconds();
        // Refresh lock lease so the stale-price request overlaps another updater.
        $lock->release();
        expect($lock->get())->toBeTrue();
        expect(fn () => $prices->refresh())->toThrow(DomainException::class);
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

it('backs off failed price updates and never calls a public fallback with a pro secret', function () {
    $this->travel(121)->seconds();
    MarketSettings::findOrFail(1)->update(['api_key' => 'pro-secret']);
    Http::fake(['pro-api.coingecko.com/*' => Http::response([], 503)]);
    $prices = app(MarketPrices::class);
    expect(fn () => $prices->refresh())->toThrow(DomainException::class);
    expect(fn () => $prices->refresh())->toThrow(DomainException::class);
    expect($prices->latest())->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://pro-api.coingecko.com/') && $r->hasHeader('x-cg-pro-api-key', 'pro-secret'));
});

it('does not publish stale public market data', function () {
    $this->travel(121)->seconds();
    $stamp = now()->subMinutes(10)->timestamp;
    $data = array_fill_keys(['tether', 'usd-coin', 'ethereum', 'bitcoin'], ['usd' => '1', 'last_updated_at' => (string) $stamp]);
    Http::fake(['api.coingecko.com/*' => Http::response($data)]);
    $count = MarketSnapshot::count();
    expect(fn () => app(MarketPrices::class)->refresh())->toThrow(DomainException::class);
    expect(MarketSnapshot::count())->toBe($count);
});

it('uses built in public endpoints without leaking stored custom credentials', function () {
    config(['assets.rpc_allowed_hosts' => []]);
    $c = ChainConnection::findOrFail('ETHEREUM');
    $c->rpc_url = null;
    $c->credential = ['api_key' => 'custom-secret'];
    Http::fake(['ethereum-rpc.publicnode.com' => Http::response(['result' => '0x1'])]);
    expect(app(ChainRpc::class)->call($c, 'eth_chainId'))->toBe('0x1');
    Http::assertSent(fn ($r) => ! $r->hasHeader('X-API-Key') && ! $r->hasHeader('Authorization'));
    $c->rpc_url = 'https://ethereum-rpc.publicnode.com.evil.test';
    expect(fn () => app(ChainRpc::class)->call($c, 'eth_chainId'))->toThrow(DomainException::class);
    Http::assertSentCount(1);
});

it('tests a public network without writing configuration or money then starts only after the current block', function () {
    $c = ChainConnection::findOrFail('BITCOIN');
    $before = $c->getRawOriginal();
    $entries = LedgerEntry::count();
    Http::fake(['bitcoin-rpc.publicnode.com' => function ($request) {
        $result = match ($request['method']) {
            'getblockchaininfo' => ['chain' => 'main', 'initialblockdownload' => false, 'blocks' => 200],
            'getblockhash' => 'block195',
            'getblock' => ['hash' => 'block195', 'height' => 195, 'tx' => []],
            default => throw new RuntimeException('Unexpected RPC'),
        };

        return Http::response(['result' => $result]);
    }]);
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $input = ['kind' => 'network-test', 'network' => 'BITCOIN', 'enabled' => true, 'use_public' => true, 'start_from_current' => true, 'confirmations' => 6, 'password' => 'local-password'];
    app(ConfigureAssetsAction::class)->execute($actor, $input);
    expect($c->fresh()->getRawOriginal())->toBe($before)->and(LedgerEntry::count())->toBe($entries);
    $input['kind'] = 'network';
    app(ConfigureAssetsAction::class)->execute($actor, $input);
    expect($c->fresh()->start_height)->toBe(196)->and($c->fresh()->next_height)->toBe(196);
    $c->refresh()->update(['next_height' => 198]);
    app(ConfigureAssetsAction::class)->execute($actor, $input);
    expect($c->fresh()->next_height)->toBe(198);
});

it('does not enable a public Ethereum node when complete traces are unavailable', function () {
    $c = ChainConnection::findOrFail('ETHEREUM');
    $c->update(['enabled' => false]);
    Http::fake(['ethereum-rpc.publicnode.com' => function ($r) {
        return match ($r['method']) {
            'eth_chainId' => Http::response(['result' => '0x1']),
            'eth_getBlockByNumber' => Http::response(['result' => ['number' => '0x64', 'timestamp' => '0x64', 'transactions' => [['hash' => 'tx']]]]),
            default => Http::response(['error' => ['code' => -32601]]),
        };
    }]);
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    expect(fn () => app(ConfigureAssetsAction::class)->execute($actor, ['kind' => 'network', 'network' => 'ETHEREUM', 'enabled' => true, 'use_public' => true, 'confirmations' => 6, 'password' => 'local-password']))->toThrow(DomainException::class);
    expect($c->fresh()->enabled)->toBeFalse()->and($c->fresh()->next_height)->toBe(100);
});

it('requires the platform password for manual receipt and credits once even with unavailable nodes', function () {
    $o = app(DepositAssetsAction::class)->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '1', (string) Str::uuid());
    ChainConnection::query()->update(['enabled' => false]);
    Http::fake();
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $url = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/asset-orders/'.$o->id.'/confirm';
    $input = ['request_id' => (string) Str::uuid(), 'confirmed' => true];
    $this->actingAs($actor, 'platform_admin')->post($url, $input)->assertSessionHasErrors('password');
    expect($o->fresh()->status)->toBe('PENDING');
    $this->post($url, $input + ['password' => 'incorrect'])->assertSessionHasErrors();
    expect($o->fresh()->status)->toBe('PENDING');
    $input['password'] = 'local-password';
    $this->post($url, $input)->assertRedirect()->assertSessionHasNoErrors();
    $count = LedgerEntry::count();
    $this->post($url, $input)->assertRedirect()->assertSessionHasNoErrors();
    expect($o->fresh()->status)->toBe('CREDITED')->and(LedgerEntry::count())->toBe($count)->and($o->fresh()->manual_confirmed_by)->toBe($actor->id);
    Http::assertNothingSent();
});

it('requires credentials or endpoint when explicitly selecting private services', function () {
    Http::fake();
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $action = app(ConfigureAssetsAction::class);
    expect(fn () => $action->execute($actor, ['kind' => 'market', 'enabled' => true, 'use_public' => false, 'password' => 'local-password']))->toThrow(DomainException::class);
    expect(fn () => $action->execute($actor, ['kind' => 'network', 'network' => 'ETHEREUM', 'enabled' => false, 'use_public' => false, 'rpc_url' => '', 'confirmations' => 6, 'password' => 'local-password']))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});
