<?php

use App\Application\Admin\FinancialOperationQuery;
use App\Application\Assets\DepositAssetsAction;
use App\Application\Assets\PlatformFundsQuery;
use App\Application\Partners\LegacyStockReport;
use App\Application\Partners\PartnerManagement;
use App\Application\Payment\ChangeDepositReceiptTypeAction;
use App\Application\Payment\ConfirmPlatformTopupAction;
use App\Application\Payment\CreditWalletTopupAction;
use App\Application\Payment\PaymentLedgerReconciliationService;
use App\Application\Payment\ProcessIncomingTrc20TransferAction;
use App\Application\Tenant\ListTenantsQuery;
use App\Application\User\PlatformUserReceiptQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetDepositOrder;
use App\Domain\Assets\ChainObservation;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Services\WalletProvisioner;
use App\Domain\Withdrawal\DTOs\IncomingBlockchainTransfer;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed();
    Http::preventStrayRequests();
    Queue::fake();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->wallet = DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $this->user, 'USDT'));
    $this->partner = app(PartnerManagement::class)->configure($this->actor, $this->tenant->id, ['account_id' => $this->user->account_id, 'enabled' => true, 'share_percent' => '40']);
});

function receiptOrder($test, string $source, string $expectedAmount = '100.01'): object
{
    $id = (string) Str::uuid();
    $data = ['id' => $id, 'tenant_id' => $test->tenant->id, 'user_id' => $test->user->id, 'wallet_id' => $test->wallet->id,
        'request_id' => (string) Str::uuid(), 'request_hash' => hash('sha256', $id), 'asset_code' => 'USDT', 'amount' => $expectedAmount,
        'requested_amount' => '100', 'status' => 'PENDING', 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()];
    if ($source === 'primary') {
        DB::table('wallet_topup_orders')->insert($data + ['payment_provider' => 'trc20-shared', 'payment_rail' => 'TRC20_SHARED', 'expected_amount' => $expectedAmount,
            'identification_increment' => (string) BigDecimal::of($expectedAmount)->minus('100'), 'network_code' => 'TRON', 'deposit_address' => 'T111111111111111111111111111111111', 'token_contract' => 'T222222222222222222222222222222222']);

        return WalletTopupOrder::findOrFail($id);
    }
    DB::table('asset_deposit_orders')->insert($data + ['rail_code' => 'USDT_ETHEREUM', 'network' => 'ETHEREUM', 'address' => 'offline-test', 'address_hash' => hash('sha256', $id)]);

    return AssetDepositOrder::findOrFail($id);
}

function confirmReceipt($test, object $order, string $request, string $type = 'ACTUAL', ?string $amount = '100.01'): void
{
    if ($order instanceof WalletTopupOrder) {
        app(ConfirmPlatformTopupAction::class)->execute($test->tenant->id, $order->id, $request, $test->actor, true, $type, $amount);
    } else {
        app(DepositAssetsAction::class)->manual($test->tenant->id, $order->id, $test->actor, $request, true, $type, $amount);
    }
}

it('credits exactly once and atomically records the selected receipt type', function ($source, $type) {
    $order = receiptOrder($this, $source);
    $request = (string) Str::uuid();
    $before = DB::table('ledger_accounts')->where('wallet_id', $this->wallet->id)->where('account_type', 'USER_AVAILABLE')->value('balance');
    confirmReceipt($this, $order, $request, $type);
    confirmReceipt($this, $order, $request, $type);
    $order->refresh();
    expect($order->manual_receipt_type)->toBe($type)
        ->and(DB::table('partner_journal_entries')->where('partner_id', $this->partner->id)->count())->toBe($type === 'ADVANCE' ? 1 : 0)
        ->and(array_key_exists('manual_receipt_type', $order->toArray()))->toBeFalse()
        ->and(array_key_exists('advance_journal_id', $order->toArray()))->toBeFalse();
    $balance = DB::table('ledger_accounts')->where('wallet_id', $this->wallet->id)->where('account_type', 'USER_AVAILABLE')->value('balance');
    expect(BigDecimal::of($balance)->minus($before)->isEqualTo('100.01'))->toBeTrue();
    $operations = app(FinancialOperationQuery::class)->forOrder($order->tenant_id, $source === 'primary' ? 'wallet_topup_order' : 'asset_deposit_order', $order->id);
    expect($operations[0]['receiptType'])->toBe($type);
    if ($type === 'ADVANCE') {
        $journal = DB::table('partner_journal_entries')->find($order->advance_journal_id);
        expect($journal->kind)->toBe('ADVANCE')->and($journal->amount)->toBe('100.01000000');
    }
    expect(fn () => confirmReceipt($this, $order, $request, $type === 'ACTUAL' ? 'ADVANCE' : 'ACTUAL'))->toThrow(DomainException::class);
    confirmReceipt($this, $order, (string) Str::uuid(), 'ADVANCE');
    expect($order->fresh()->manual_receipt_type)->toBe($type);
})->with(['primary', 'asset'])->with(['ACTUAL', 'ADVANCE']);

it('rejects disabled partners without crediting or recording an advance', function ($source) {
    $order = receiptOrder($this, $source);
    DB::table('partner_configurations')->where('id', $this->partner->id)->update(['enabled' => false]);
    expect(fn () => confirmReceipt($this, $order, (string) Str::uuid(), 'ADVANCE'))->toThrow(DomainException::class);
    expect($order->fresh()->ledger_entry_id)->toBeNull()->and(DB::table('partner_journal_entries')->count())->toBe(0);
})->with(['primary', 'asset']);

it('rolls back the advance when ledger settlement fails', function ($source) {
    $order = receiptOrder($this, $source);
    DB::table('ledger_accounts')->where('wallet_id', $this->wallet->id)->where('account_type', 'USER_AVAILABLE')->update(['status' => 'CLOSED']);
    expect(fn () => confirmReceipt($this, $order, (string) Str::uuid(), 'ADVANCE'))->toThrow(DomainException::class);
    expect($order->fresh()->manual_receipt_type)->toBeNull()->and(DB::table('partner_journal_entries')->count())->toBe(0);
})->with(['primary', 'asset']);

it('requires partner permission in addition to confirmation permission', function ($source) {
    $order = receiptOrder($this, $source);
    $permission = DB::table('permissions')->where('name', 'partners.manage')->value('id');
    DB::table('role_permissions')->where('permission_id', $permission)->delete();
    expect(fn () => confirmReceipt($this, $order, (string) Str::uuid(), 'ADVANCE'))->toThrow(HttpException::class);
    expect(DB::table('partner_journal_entries')->count())->toBe(0);
    confirmReceipt($this, $order, (string) Str::uuid());
    expect($order->fresh()->manual_receipt_type)->toBe('ACTUAL');
})->with(['primary', 'asset']);

it('rejects cross company confirmation', function ($source) {
    $order = receiptOrder($this, $source);
    $this->tenant = Tenant::where('slug', 'tenant-b')->firstOrFail();
    expect(fn () => confirmReceipt($this, $order, (string) Str::uuid(), 'ADVANCE'))->toThrow(ModelNotFoundException::class);
    expect($order->fresh()->ledger_entry_id)->toBeNull();
})->with(['primary', 'asset']);

it('rejects a non USDT advance but permits actual receipt in its native currency', function () {
    $this->wallet = DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $this->user, 'USDC'));
    $id = (string) Str::uuid();
    $order = AssetDepositOrder::create(['id' => $id, 'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'wallet_id' => $this->wallet->id,
        'request_id' => (string) Str::uuid(), 'request_hash' => hash('sha256', $id), 'asset_code' => 'USDC', 'amount' => '100.01', 'requested_amount' => '100',
        'expires_at' => now()->addMinutes(30), 'rail_code' => 'USDC_ETHEREUM', 'network' => 'ETHEREUM', 'address' => 'offline-test', 'address_hash' => hash('sha256', $id)]);
    expect(fn () => confirmReceipt($this, $order, (string) Str::uuid(), 'ADVANCE'))->toThrow(DomainException::class);
    confirmReceipt($this, $order, (string) Str::uuid());
    expect($order->fresh()->manual_receipt_type)->toBe('ACTUAL')->and(DB::table('partner_journal_entries')->count())->toBe(0);
});

it('does not append an advance when chain credit won first', function ($source) {
    $order = receiptOrder($this, $source);
    if ($source === 'primary') {
        $order->status = WalletTopupStatus::Paid;
        $order->matched_tx_hash = str_repeat('a', 64);
        $order->matched_transfer_index = 0;
        $order->blockchain_detected_at = now();
        $order->blockchain_confirmed_at = now();
        $order->paid_at = now();
        $order->save();
        app(CreditWalletTopupAction::class)->execute($order->tenant_id, $order->id);
    } else {
        $observation = ChainObservation::create(['network' => 'ETHEREUM', 'event_id' => 'offline-chain-event', 'block_height' => 100, 'block_hash' => str_repeat('b', 64), 'rail_code' => 'USDT_ETHEREUM', 'address' => $order->address, 'amount' => $order->amount, 'status' => 'MATCHED', 'order_id' => $order->id, 'occurred_at' => now()]);
        app(DepositAssetsAction::class)->verified($observation);
    }
    confirmReceipt($this, $order, (string) Str::uuid(), 'ADVANCE');
    expect($order->fresh()->manual_receipt_type)->toBeNull()->and(DB::table('partner_journal_entries')->count())->toBe(0);
})->with(['primary', 'asset']);

it('keeps classification immutable and reversal informational', function ($source) {
    $order = receiptOrder($this, $source);
    confirmReceipt($this, $order, (string) Str::uuid(), 'ADVANCE');
    $order->refresh();
    $balance = DB::table('ledger_accounts')->where('wallet_id', $this->wallet->id)->where('account_type', 'USER_AVAILABLE')->value('balance');
    app(PartnerManagement::class)->journal($this->actor, $this->tenant->id, $this->partner->id, [
        'kind' => 'ADVANCE', 'amount' => '100.01', 'business_date' => now()->format('Y-m-d'), 'note' => 'Offline correction',
        'request_id' => (string) Str::uuid(), 'reverses_id' => $order->advance_journal_id,
    ]);
    expect(DB::table('ledger_accounts')->where('wallet_id', $this->wallet->id)->where('account_type', 'USER_AVAILABLE')->value('balance'))->toBe($balance);
    expect(fn () => DB::transaction(fn () => DB::table($order->getTable())->where('id', $order->id)->update(['manual_receipt_type' => 'ACTUAL', 'advance_journal_id' => null])))->toThrow(QueryException::class);
})->with(['primary', 'asset']);

it('accepts omitted type on both HTTP endpoints and rejects unsupported types', function ($source) {
    $order = receiptOrder($this, $source);
    $url = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/'.($source === 'primary' ? 'topups' : 'asset-orders').'/'.$order->id.'/confirm';
    $this->actingAs($this->actor, 'platform_admin')->post($url, ['request_id' => (string) Str::uuid(), 'confirmed' => true, 'receipt_type' => 'INVALID'])->assertSessionHasErrors('receipt_type');
    $this->actingAs($this->actor, 'platform_admin')->post($url, ['request_id' => (string) Str::uuid(), 'confirmed' => true, 'actual_received_amount' => '100.01'])->assertRedirect();
    expect($order->fresh()->manual_receipt_type)->toBe('ACTUAL');
})->with(['primary', 'asset']);

function raceReceiptOperations(array $operations): array
{
    if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'card_ui_test' || DB::transactionLevel() !== 1) {
        throw new RuntimeException('Concurrent fixtures require isolated card_ui_test.');
    }
    DB::commit();
    RefreshDatabaseState::$migrated = false;
    DB::disconnect();
    $directory = sys_get_temp_dir().'/receipt-race-'.Str::uuid();
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

it('serializes simultaneous advance confirmation and chain settlement', function ($source) {
    $order = receiptOrder($this, $source);
    $request = (string) Str::uuid();
    if ($source === 'primary') {
        config(['payment.trc20_token_contract' => $order->token_contract, 'payment.trc20_required_confirmations' => 20]);
        $transfer = new IncomingBlockchainTransfer('TRON', hash('sha256', $order->id), 0, $order->token_contract, $order->deposit_address, $order->expected_amount, 30, CarbonImmutable::now());
        $chain = fn () => app(ProcessIncomingTrc20TransferAction::class)->execute($transfer);
    } else {
        $observation = ChainObservation::create(['network' => 'ETHEREUM', 'event_id' => 'offline-race', 'block_height' => 100, 'block_hash' => 'offline-block', 'rail_code' => $order->rail_code, 'address' => $order->address, 'amount' => $order->amount, 'status' => 'MATCHED', 'order_id' => $order->id, 'occurred_at' => now()]);
        $chain = fn () => app(DepositAssetsAction::class)->verified($observation);
    }
    $result = raceReceiptOperations([fn () => confirmReceipt($this, $order, $request, 'ADVANCE'), $chain]);
    expect($result)->toBe(['completed', 'completed']);
    $order->refresh();
    expect(DB::table('ledger_entries')->where('event_key', ($source === 'primary' ? 'wallet_topup:' : 'asset_deposit:').$order->id.':credit')->count())->toBe(1);
    expect(DB::table('partner_journal_entries')->where('partner_id', $this->partner->id)->count())->toBe($order->manual_receipt_type === 'ADVANCE' ? 1 : 0);
    expect(BigDecimal::of(DB::table('ledger_accounts')->where('wallet_id', $this->wallet->id)->where('account_type', 'USER_AVAILABLE')->value('balance'))->isEqualTo('100.01'))->toBeTrue();
})->with(['primary', 'asset']);

it('credits the actual amount and preserves the original amount and retry identity', function ($source, $amount) {
    $order = receiptOrder($this, $source);
    $request = (string) Str::uuid();
    confirmReceipt($this, $order, $request, 'ADVANCE', $amount);
    confirmReceipt($this, $order, $request, 'ADVANCE', $amount);
    $order->refresh();
    expect(BigDecimal::of($order->amount)->isEqualTo('100.01'))->toBeTrue()
        ->and(BigDecimal::of($order->actual_received_amount)->isEqualTo($amount))->toBeTrue()
        ->and(BigDecimal::of(DB::table('ledger_accounts')->where('wallet_id', $this->wallet->id)->where('account_type', 'USER_AVAILABLE')->value('balance'))->isEqualTo($amount))->toBeTrue()
        ->and(BigDecimal::of(DB::table('partner_journal_entries')->find($order->advance_journal_id)->amount)->isEqualTo($amount))->toBeTrue();
    expect(fn () => confirmReceipt($this, $order, $request, 'ADVANCE', '102'))->toThrow(DomainException::class);
    expect(fn () => DB::transaction(fn () => DB::table($order->getTable())->where('id', $order->id)->update(['actual_received_amount' => '102'])))->toThrow(QueryException::class);
    if ($source === 'primary') {
        expect(app(PaymentLedgerReconciliationService::class)->mismatches($this->tenant->id))->toBe([]);
    }
})->with(['primary', 'asset'])->with(['99.12345678', '101.01']);

it('rejects missing or invalid actual amounts without financial writes', function ($source, $amount) {
    $order = receiptOrder($this, $source);
    expect(fn () => confirmReceipt($this, $order, (string) Str::uuid(), 'ACTUAL', $amount))->toThrow(DomainException::class);
    expect($order->fresh()->ledger_entry_id)->toBeNull();
})->with(['primary', 'asset'])->with([null, '', '0', '-1', '1e2', '1.000000001', '1000000000000']);

it('requires an actual amount through both HTTP endpoints', function ($source) {
    $order = receiptOrder($this, $source);
    $url = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/'.($source === 'primary' ? 'topups' : 'asset-orders').'/'.$order->id.'/confirm';
    $this->actingAs($this->actor, 'platform_admin')->post($url, ['request_id' => (string) Str::uuid(), 'confirmed' => true])->assertSessionHasErrors('actual_received_amount');
    expect($order->fresh()->ledger_entry_id)->toBeNull();
})->with(['primary', 'asset']);

it('splits company inflow into actual and advance receipts using credited native amounts', function () {
    $advance = receiptOrder($this, 'primary');
    confirmReceipt($this, $advance, (string) Str::uuid(), 'ADVANCE', '123.12345678');
    $actual = receiptOrder($this, 'primary', '100.02');
    confirmReceipt($this, $actual, (string) Str::uuid(), 'ACTUAL', '89.87654321');
    receiptOrder($this, 'primary', '100.03'); // Uncredited orders must not contribute.
    $query = app(ListTenantsQuery::class);
    $access = ['inflow' => true, 'outflow' => true];
    $balances = DB::table('ledger_accounts')->orderBy('id')->pluck('balance', 'id')->all();
    $totals = $query->totals(null, null, $access, $this->tenant->id);
    expect($totals['actualInflow'])->toBe('89.87654321')
        ->and($totals['advanceInflow'])->toBe('123.12345678')
        ->and($totals['inflow'])->toBe('212.99999999');
    $row = $query->execute(null, null, $access, $this->tenant->id)->items()[0];
    expect($row['actualInflow'])->toBe($totals['actualInflow'])
        ->and($row['advanceInflow'])->toBe($totals['advanceInflow']);
    $other = Tenant::where('id', '!=', $this->tenant->id)->firstOrFail();
    expect($query->totals(null, null, $access, $other->id)['advanceInflow'])->toBe('0.00000000')
        ->and($query->totals('nonexistent', null, $access)['actualInflow'])->toBe('0.00000000')
        ->and($query->totals(null, null, []))->toBe([]);
    $hidden = $query->execute(null, null, [], $this->tenant->id)->items()[0];
    expect($hidden)->not->toHaveKeys(['inflow', 'actualInflow', 'advanceInflow', 'outflow'])
        ->and(DB::table('ledger_accounts')->orderBy('id')->pluck('balance', 'id')->all())->toBe($balances);
    Http::assertNothingSent();
});

it('splits user receipts across deposit sources without counting pending orders or rewriting funds', function () {
    $actual = receiptOrder($this, 'primary', '100.01');
    $advance = receiptOrder($this, 'primary', '100.02');
    $asset = receiptOrder($this, 'asset');
    confirmReceipt($this, $actual, (string) Str::uuid(), 'ACTUAL', '89.87654321');
    confirmReceipt($this, $advance, (string) Str::uuid(), 'ADVANCE', '123.12345678');
    confirmReceipt($this, $asset, (string) Str::uuid(), 'ACTUAL', '20.12345679');
    receiptOrder($this, 'primary', '100.03');
    $before = DB::table('ledger_accounts')->orderBy('id')->get()->toJson();
    $this->actingAs($this->actor, 'platform_admin');
    $this->getJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/users/{$this->user->id}/details")
        ->assertOk()->assertJsonPath('financialAccess.receipts', true)
        ->assertJsonPath('user.receiptTotals.0.asset', 'USDT')
        ->assertJsonPath('user.receiptTotals.0.actual', fn ($value) => BigDecimal::of($value)->isEqualTo('110'))
        ->assertJsonPath('user.receiptTotals.0.advance', fn ($value) => BigDecimal::of($value)->isEqualTo('123.12345678'));
    $other = User::where('tenant_id', '<>', $this->tenant->id)->firstOrFail();
    $this->getJson("http://admin.localhost/platform/tenants/{$other->tenant_id}/users/{$other->id}/details")
        ->assertOk()->assertJsonPath('user.receiptTotals.0.actual', '0')->assertJsonPath('user.receiptTotals.0.advance', '0');
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'wallet_topups.read')->value('id'))->delete();
    $this->actingAs($this->actor->fresh(), 'platform_admin');
    $this->getJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/users/{$this->user->id}/details")
        ->assertOk()->assertJsonPath('financialAccess.receipts', false)->assertJsonMissingPath('user.receiptTotals');
    expect(DB::table('ledger_accounts')->orderBy('id')->get()->toJson())->toBe($before);
    Http::assertNothingSent();
});

function changeReceiptType($test, object $order, string $type, int $revision, ?string $request = null): void
{
    app(ChangeDepositReceiptTypeAction::class)->execute($test->actor, $test->tenant->id,
        $order instanceof WalletTopupOrder ? 'primary' : 'asset', $order->id, $type, $revision, $request ?? (string) Str::uuid(), true);
}

it('switches settled manual receipt types and refreshes all projections without another credit', function ($source): void {
    $order = receiptOrder($this, $source);
    confirmReceipt($this, $order, (string) Str::uuid(), 'ACTUAL', '89.12345678');
    $order->refresh();
    $original = $order->getAttributes();
    $balances = DB::table('ledger_accounts')->orderBy('id')->get()->toJson();
    $ledger = DB::table('ledger_entries')->orderBy('id')->get()->toJson();
    $request = (string) Str::uuid();
    foreach ([1 => 'ADVANCE', 2 => 'ACTUAL', 3 => 'ADVANCE'] as $revision => $type) {
        changeReceiptType($this, $order, $type, $revision - 1, $revision === 1 ? $request : null);
        if ($revision === 1) {
            changeReceiptType($this, $order, $type, 0, $request);
        }
        $row = app(PlatformFundsQuery::class)->paginate('deposit', ['company' => $this->tenant->id])->items()[0];
        expect($row['receiptType'])->toBe($type)->and($row['arrivalType'])->toBe($type === 'ADVANCE' ? 'ADVANCE' : 'MANUAL')
            ->and($row['receiptTypeRevision'])->toBe($revision);
        $totals = app(ListTenantsQuery::class)->totals(null, null, ['inflow' => true], $this->tenant->id);
        expect($totals['inflow'])->toBe('89.12345678')->and($totals['advanceInflow'])->toBe($type === 'ADVANCE' ? '89.12345678' : '0.00000000');
        $companyRow = app(ListTenantsQuery::class)->execute(null, null, ['inflow' => true], $this->tenant->id)->items()[0];
        expect($companyRow['advanceInflow'])->toBe($totals['advanceInflow']);
        $userTotals = app(PlatformUserReceiptQuery::class)->forUsers([$this->user])[$this->tenant->id.':'.$this->user->id][0];
        expect(BigDecimal::of($userTotals['advance'])->isEqualTo($type === 'ADVANCE' ? '89.12345678' : '0'))->toBeTrue()
            ->and(BigDecimal::of($userTotals['actual'])->isEqualTo($type === 'ACTUAL' ? '89.12345678' : '0'))->toBeTrue();
        $report = app(LegacyStockReport::class)->read($this->tenant->id, $this->user->id);
        expect($report['accountBalance']['advances'])->toBe($type === 'ADVANCE' ? '89.12345678' : '0.00000000');
    }
    // A successful request replay stays a no-op even after later changes.
    changeReceiptType($this, $order, 'ADVANCE', 0, $request);
    expect(DB::table('deposit_receipt_type_changes')->count())->toBe(3)
        ->and(DB::table('partner_journal_entries')->count())->toBe(3)
        ->and($order->fresh()->getAttributes())->toBe($original)
        ->and(DB::table('ledger_accounts')->orderBy('id')->get()->toJson())->toBe($balances)
        ->and(DB::table('ledger_entries')->orderBy('id')->get()->toJson())->toBe($ledger);
    $operations = app(FinancialOperationQuery::class)->forOrder($this->tenant->id, $source === 'primary' ? 'wallet_topup_order' : 'asset_deposit_order', $order->id);
    expect(collect($operations)->where('action', 'DEPOSIT_RECEIPT_TYPE_CHANGED')->count())->toBe(3);
    Http::assertNothingSent();
})->with(['primary', 'asset']);

it('reverses initial advances even for disabled partners and reuses an existing reversal', function ($source, bool $alreadyReversed): void {
    $order = receiptOrder($this, $source);
    confirmReceipt($this, $order, (string) Str::uuid(), 'ADVANCE', '91.00000001');
    $order->refresh();
    if ($alreadyReversed) {
        app(PartnerManagement::class)->journal($this->actor, $this->tenant->id, $this->partner->id, [
            'kind' => 'ADVANCE', 'amount' => '91.00000001', 'business_date' => now()->format('Y-m-d'), 'note' => 'Offline reversal',
            'request_id' => (string) Str::uuid(), 'reverses_id' => $order->advance_journal_id,
        ]);
    }
    DB::table('partner_configurations')->where('id', $this->partner->id)->update(['enabled' => false]);
    changeReceiptType($this, $order, 'ACTUAL', 0);
    expect(DB::table('partner_journal_entries')->count())->toBe(2)
        ->and(DB::table('deposit_receipt_type_changes')->value('before_journal_id'))->toBe($order->advance_journal_id)
        ->and(DB::table('deposit_receipt_type_changes')->value('after_journal_id'))->toBeNull();
    expect(fn () => changeReceiptType($this, $order, 'ADVANCE', 1))->toThrow(DomainException::class);
    expect(DB::table('deposit_receipt_type_changes')->count())->toBe(1);
})->with(['primary', 'asset'])->with([true, false]);

it('rejects stale revisions conflicting retries company overrides and unconfirmed type changes', function ($source): void {
    $order = receiptOrder($this, $source);
    confirmReceipt($this, $order, (string) Str::uuid());
    $request = (string) Str::uuid();
    changeReceiptType($this, $order, 'ADVANCE', 0, $request);
    expect(fn () => changeReceiptType($this, $order, 'ACTUAL', 0))->toThrow(DomainException::class)
        ->and(fn () => changeReceiptType($this, $order, 'ACTUAL', 1, $request))->toThrow(DomainException::class);
    $url = "http://admin.localhost/platform/tenants/{$this->tenant->id}/deposit-orders/{$source}/{$order->id}/receipt-type";
    $this->actingAs($this->actor, 'platform_admin');
    $data = ['receipt_type' => 'ACTUAL', 'revision' => 1, 'request_id' => (string) Str::uuid(), 'confirmed' => true];
    $this->post($url, [...$data, 'confirmed' => false])->assertSessionHasErrors('confirmed');
    $this->post($url, [...$data, 'amount' => '5', 'actual_received_amount' => '7', 'tenant_id' => $this->tenant->id])->assertSessionHasErrors(['amount', 'actual_received_amount', 'tenant_id']);
    $other = Tenant::where('slug', 'tenant-b')->sole();
    $this->post(str_replace($this->tenant->id, $other->id, $url), $data)->assertNotFound();
    $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
    $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('deposit_receipt_type_changes')->count())->toBe(2)->and(DB::table('partner_journal_entries')->count())->toBe(2);
})->with(['primary', 'asset']);

it('rejects changes to pending and automatic receipts and labels automatic arrival distinctly', function ($source): void {
    $order = receiptOrder($this, $source);
    expect(fn () => changeReceiptType($this, $order, 'ADVANCE', 0))->toThrow(DomainException::class);
    if ($source === 'primary') {
        $order->status = WalletTopupStatus::Paid;
        $order->matched_tx_hash = str_repeat('a', 64);
        $order->matched_transfer_index = 0;
        $order->blockchain_detected_at = now();
        $order->blockchain_confirmed_at = now();
        $order->paid_at = now();
        $order->save();
        app(CreditWalletTopupAction::class)->execute($order->tenant_id, $order->id);
    } else {
        $observation = ChainObservation::create(['network' => 'ETHEREUM', 'event_id' => 'offline-classification', 'block_height' => 100, 'block_hash' => str_repeat('b', 64), 'rail_code' => 'USDT_ETHEREUM', 'address' => $order->address, 'amount' => $order->amount, 'status' => 'MATCHED', 'order_id' => $order->id, 'occurred_at' => now()]);
        app(DepositAssetsAction::class)->verified($observation);
    }
    expect(fn () => changeReceiptType($this, $order, 'ADVANCE', 0))->toThrow(DomainException::class);
    $row = app(PlatformFundsQuery::class)->paginate('deposit', ['company' => $this->tenant->id])->items()[0];
    expect($row['arrivalType'])->toBe('AUTO')->and($row['canChangeReceiptType'])->toBeFalse()
        ->and(DB::table('deposit_receipt_type_changes')->count())->toBe(0)->and(DB::table('partner_journal_entries')->count())->toBe(0);
})->with(['primary', 'asset']);

it('keeps receipt changes immutable and rolls back classification when its audit fails', function ($source): void {
    $order = receiptOrder($this, $source);
    confirmReceipt($this, $order, (string) Str::uuid());
    changeReceiptType($this, $order, 'ADVANCE', 0);
    expect(fn () => DB::transaction(fn () => DB::table('deposit_receipt_type_changes')->update(['after_type' => 'ACTUAL'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('deposit_receipt_type_changes')->delete()))->toThrow(QueryException::class);
    $before = DB::table('partner_journal_entries')->count();
    if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'card_ui_test') {
        throw new RuntimeException('Audit failure injection requires isolated card_ui_test.');
    }
    DB::unprepared("CREATE OR REPLACE FUNCTION test_receipt_audit_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
        IF NEW.action='DEPOSIT_RECEIPT_TYPE_CHANGED' THEN RAISE EXCEPTION 'Offline audit failure'; END IF; RETURN NEW; END \$\$;
        CREATE TRIGGER test_receipt_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION test_receipt_audit_failure();");
    expect(fn () => changeReceiptType($this, $order, 'ACTUAL', 1))->toThrow(QueryException::class);
    expect(DB::table('partner_journal_entries')->count())->toBe($before)->and(DB::table('deposit_receipt_type_changes')->count())->toBe(1);
    DB::unprepared('DROP TRIGGER test_receipt_audit_failure ON audit_logs; DROP FUNCTION test_receipt_audit_failure();');
})->with(['primary', 'asset']);

it('serializes competing receipt type changes without duplicate advances', function ($source): void {
    $order = receiptOrder($this, $source);
    confirmReceipt($this, $order, (string) Str::uuid());
    $result = raceReceiptOperations([fn () => changeReceiptType($this, $order, 'ADVANCE', 0), fn () => changeReceiptType($this, $order, 'ADVANCE', 0)]);
    sort($result);
    expect($result)->toBe(['completed', 'rejected'])->and(DB::table('deposit_receipt_type_changes')->count())->toBe(1)
        ->and(DB::table('partner_journal_entries')->count())->toBe(1);
})->with(['primary', 'asset']);

it('rechecks administrator scope status and both permissions before changing receipt type', function ($restriction): void {
    $order = receiptOrder($this, 'primary');
    confirmReceipt($this, $order, (string) Str::uuid());
    if ($restriction === 'account') {
        $this->actor->update(['status' => 'SUSPENDED']);
    } elseif ($restriction === 'membership') {
        $this->actor->memberships()->update(['status' => 'SUSPENDED']);
    } elseif ($restriction === 'company') {
        $this->actor = AdminUser::where('email', 'owner@a.localhost')->sole();
    } else {
        DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', $restriction)->value('id'))->delete();
    }
    expect(fn () => changeReceiptType($this, $order, 'ADVANCE', 0))->toThrow(HttpException::class)
        ->and(DB::table('deposit_receipt_type_changes')->count())->toBe(0)->and(DB::table('partner_journal_entries')->count())->toBe(0);
})->with(['account', 'membership', 'company', 'wallet_topups.confirm', 'partners.manage']);
