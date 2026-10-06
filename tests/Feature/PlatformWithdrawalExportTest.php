<?php

use App\Application\Assets\AssetAccess;
use App\Application\Assets\WithdrawAssetsAction;
use App\Application\Partners\PartnerManagement;
use App\Application\Promotion\ManualPromotion;
use App\Application\User\CreatePlatformUserAction;
use App\Application\Withdrawal\ApproveWithdrawalAction;
use App\Application\Withdrawal\CreateWithdrawalAction;
use App\Application\Withdrawal\VerifyWithdrawalTransactionAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetRail;
use App\Domain\Assets\ChainConnection;
use App\Domain\Assets\CompanyRail;
use App\Domain\Assets\ExchangePolicy;
use App\Domain\Assets\MarketSettings;
use App\Domain\Assets\MarketSnapshot;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Domain\Withdrawal\DTOs\BlockchainTransferVerification;
use App\Domain\Withdrawal\Enums\BlockchainVerificationOutcome;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function exportTestRates(): array
{
    return ['code' => '0', 'data' => array_map(fn ($asset, $rate) => [
        'instType' => 'SPOT', 'instId' => $asset.'-USDT', 'last' => $rate,
        'ts' => (string) now()->getTimestampMs(),
    ], ['USDC', 'ETH', 'BTC'], ['0.998998998998998998', '2002.002002002002002002', '60060.060060060060060060'])];
}

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    Http::fake(['www.okx.com/*' => Http::response(exportTestRates())]);
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->withoutVite();
    $this->user = app(CreatePlatformUserAction::class)->execute($this->tenant->id, AdminUser::where('email', 'owner@platform.local')->firstOrFail(), [
        'email' => 'export-fixture@example.test', 'display_name' => 'Export fixture', 'password' => 'secret123', 'request_id' => (string) Str::uuid(),
    ]);
    ChainConnection::where('network', 'ETHEREUM')->update(['enabled' => true, 'start_height' => 100, 'next_height' => 100]);
    foreach (['ETH_ETHEREUM', 'USDC_ETHEREUM', 'USDT_ETHEREUM'] as $code) {
        AssetRail::whereKey($code)->update(['enabled' => true, 'deposit_address' => '0x'.str_repeat('1', 40)]);
        CompanyRail::create(['tenant_id' => $this->tenant->id, 'rail_code' => $code, 'deposit_enabled' => true, 'withdrawal_enabled' => true, 'minimum_deposit' => '0.000001', 'withdrawal_fee' => '0.000001', 'withdrawal_fee_percent' => '0.0001']);
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
function exportEthereumNode(array $calls, array $logs = [], array $direct = []): void
{
    config(['assets.rpc_allowed_hosts' => ['node.example.test'], 'assets.scan_batch_blocks' => 1]);
    ChainConnection::where('network', 'ETHEREUM')->update(['rpc_url' => 'https://node.example.test']);
    $hash = '0x'.str_repeat('a', 64);
    $tx = '0x'.str_repeat('b', 64);
    $block = ['number' => '0x64', 'hash' => $hash, 'timestamp' => '0x'.dechex(now()->timestamp), 'transactions' => [['hash' => $tx] + $direct]];
    Http::fake(function ($request) use ($calls, $logs, $hash, $tx, $block) {
        $value = match ($request['method']) {
            'eth_chainId' => '0x1', 'eth_blockNumber' => '0x100','eth_getBlockByNumber' => $block,
            'debug_traceBlockByNumber' => [['txHash' => $tx, 'result' => ['type' => 'CALL', 'from' => '0x'.str_repeat('3', 40), 'to' => '0x'.str_repeat('4', 40), 'value' => '0x0', 'calls' => $calls]]],
            'eth_getTransactionReceipt' => ['status' => '0x1', 'blockNumber' => '0x64', 'blockHash' => $hash, 'transactionHash' => $tx, 'logs' => $logs],
            default => throw new RuntimeException('Unexpected RPC method'),
        };

        return Http::response(json_encode(['jsonrpc' => '2.0', 'id' => 'assets', 'result' => $value], JSON_THROW_ON_ERROR));
    });
}
function withdrawalExportRows($response): array
{
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, substr($response->getContent(), 3));
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }
    fclose($stream);

    return $rows;
}

it('exports all filtered withdrawals with exact payouts full addresses and no money writes', function () {
    ($this->fund)('ETH', '10');
    ($this->fund)('USDT', '100');
    $address = '0x'.str_repeat('3', 40);
    for ($i = 0; $i < 26; $i++) {
        app(WithdrawAssetsAction::class)->create($this->tenant->id, $this->user->id, 'ETH_ETHEREUM', '0.123456789123456789', $address, '0.000000123456789124', (string) Str::uuid(), true);
    }
    app(CreateWithdrawalAction::class)->executeWithAddress($this->tenant->id, $this->user->id, (string) Str::uuid(), 'T'.str_repeat('A', 33), '10', null, '0');
    $this->tenant->update(['name' => '=HYPERLINK("example")']);
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $entries = LedgerEntry::count();
    $balances = LedgerAccount::orderBy('id')->pluck('balance', 'id')->all();
    $url = 'http://admin.localhost/platform/asset-withdrawals/export';
    $input = ['password' => 'local-password', 'confirmed' => true, 'asset' => 'ETH', 'network' => 'ETHEREUM', 'company' => $this->tenant->id, 'status' => 'PENDING', 'search' => $this->user->account_id, 'page' => 2];
    $this->actingAs($actor, 'platform_admin')->get('http://admin.localhost/platform/asset-withdrawals?asset=ETH&page=2')->assertOk()->assertInertia(fn ($p) => $p->where('orders.total', 26)->has('orders.data', 1)->where('orders.data.0.address', fn ($value) => $value !== $address));
    $response = $this->actingAs($actor, 'platform_admin')->postJson($url, $input)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $rows = withdrawalExportRows($response);
    expect($rows)->toHaveCount(27)->and($rows[1][0])->toBe('\'=HYPERLINK("example")')
        ->and($rows[1][7])->toBe('0.123456789123456789')->and($rows[1][8])->toBe('0.000000123456789124')
        ->and($rows[1][9])->toBe('0.123456665666667665')->and($rows[1][10])->toBe($address)
        ->and(array_slice($rows[1], 16, 5))->toBe(['0', '0', '0', '0', '否']);
    $audit = DB::table('audit_logs')->where('action', 'WITHDRAWALS_EXPORTED')->sole();
    expect($audit->tenant_id)->toBe($this->tenant->id)->and($audit->after_data)->not->toContain($address)->not->toContain('local-password');
    $other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    expect(withdrawalExportRows($this->postJson($url, array_replace($input, ['company' => $other->id]))->assertOk()))->toHaveCount(1);
    expect(LedgerEntry::count())->toBe($entries)->and(LedgerAccount::orderBy('id')->pluck('balance', 'id')->all())->toBe($balances);
    Http::assertNothingSent();
});

it('exports lifetime successful totals across withdrawal sources and current membership', function () {
    ($this->fund)('USDT', '100');
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $primary = app(CreateWithdrawalAction::class)->executeWithAddress($this->tenant->id, $this->user->id, (string) Str::uuid(), 'T'.str_repeat('A', 33), '10', null, '0');
    app(ApproveWithdrawalAction::class)->execute($this->tenant->id, $primary->id, $actor);
    $gateway = Mockery::mock(BlockchainGatewayInterface::class);
    $gateway->shouldReceive('available')->andReturn(true);
    $gateway->shouldReceive('verifyUsdtTrc20Transfer')->once()->andReturn(new BlockchainTransferVerification(BlockchainVerificationOutcome::Confirmed, 100));
    app()->instance(BlockchainGatewayInterface::class, $gateway);
    app(VerifyWithdrawalTransactionAction::class)->execute($this->tenant->id, $primary->id, $actor, str_repeat('e', 64));
    CompanyRail::where('tenant_id', $this->tenant->id)->where('rail_code', 'USDT_ETHEREUM')->update(['withdrawal_fee_percent' => '10']);
    $withdraw = app(WithdrawAssetsAction::class);
    $address = '0x'.str_repeat('2', 40);
    $asset = $withdraw->create($this->tenant->id, $this->user->id, 'USDT_ETHEREUM', '10', $address, '1', (string) Str::uuid(), true);
    $withdraw->review($this->tenant->id, $asset->id, $actor, true);
    exportEthereumNode([], [['address' => AssetRail::findOrFail('USDT_ETHEREUM')->contract, 'logIndex' => '0x0', 'topics' => ['0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef', '0x'.str_repeat('0', 64), '0x'.str_repeat('0', 24).substr($address, 2)], 'data' => '0x'.str_pad(dechex(9000000), 64, '0', STR_PAD_LEFT)]]);
    expect($withdraw->verify($this->tenant->id, $asset->id, $actor, '0x'.str_repeat('b', 64), (string) Str::uuid())->status)->toBe('COMPLETED');
    $pending = app(CreateWithdrawalAction::class)->executeWithAddress($this->tenant->id, $this->user->id, (string) Str::uuid(), 'T'.str_repeat('A', 33), '30', null, '0');
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 3)->first();
    $manual = app(ManualPromotion::class);
    $adjustment = $manual->adjust($this->tenant->id, $this->user->id, $actor, $level->id, 'Offline export fixture', (string) Str::uuid(), null);
    app(PartnerManagement::class)->configure($actor, $this->tenant->id, ['account_id' => $this->user->account_id, 'enabled' => true, 'share_percent' => '40']);
    $entries = LedgerEntry::count();
    $requestCount = Http::recorded()->count();
    $url = 'http://admin.localhost/platform/asset-withdrawals/export';
    $input = ['password' => 'local-password', 'confirmed' => true, 'network' => 'TRON', 'status' => 'PENDING', 'search' => $pending->id];
    $rows = withdrawalExportRows($this->actingAs($actor, 'platform_admin')->postJson($url, $input)->assertOk());
    expect($rows)->toHaveCount(2)->and($rows[1][3])->toBe($pending->id)->and(array_slice($rows[1], 16, 5))->toBe(['20', '19', '2', '3', '是']);
    $manual->adjust($this->tenant->id, $this->user->id, $actor, 'ordinary', 'Offline downgrade', (string) Str::uuid(), $adjustment->id);
    DB::table('partner_configurations')->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->update(['enabled' => false]);
    $rows = withdrawalExportRows($this->postJson($url, $input)->assertOk());
    expect(array_slice($rows[1], 16, 5))->toBe(['20', '19', '2', '0', '否'])
        ->and(LedgerEntry::count())->toBe($entries)->and(Http::recorded()->count())->toBe($requestCount);
});

it('protects withdrawal exports with platform permissions password confirmation and valid filters', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $url = 'http://admin.localhost/platform/asset-withdrawals/export';
    $input = ['password' => 'local-password', 'confirmed' => true];
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs(AdminUser::where('email', 'owner@a.localhost')->firstOrFail(), 'platform_admin')->postJson($url, $input)->assertForbidden();
    $this->actingAs($actor, 'platform_admin')->postJson($url, array_replace($input, ['password' => 'incorrect']))->assertForbidden();
    foreach ([['confirmed' => false], ['company' => (string) Str::uuid()], ['network' => 'INVALID'], ['asset' => 'INVALID'], ['status' => 'SUCCEEDED'], ['search' => str_repeat('x', 121)]] as $invalid) {
        $this->postJson($url, array_replace($input, $invalid))->assertUnprocessable();
    }
    foreach (['withdrawals.read', 'withdrawals.review'] as $permission) {
        $id = DB::table('permissions')->where('name', $permission)->value('id');
        $grants = DB::table('role_permissions')->where('permission_id', $id)->get()->map(fn ($row) => (array) $row)->all();
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        $this->postJson($url, $input)->assertForbidden();
        DB::table('role_permissions')->insert($grants);
    }
    expect(DB::table('audit_logs')->where('action', 'WITHDRAWALS_EXPORTED')->count())->toBe(0);
    Http::assertNothingSent();
});
