<?php

use App\Application\Admin\FinancialOperationQuery;
use App\Application\Assets\DepositAssetsAction;
use App\Application\Partners\PartnerManagement;
use App\Application\Payment\ConfirmPlatformTopupAction;
use App\Application\Payment\CreditWalletTopupAction;
use App\Application\Payment\PaymentLedgerReconciliationService;
use App\Application\Payment\ProcessIncomingTrc20TransferAction;
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

function receiptOrder($test, string $source): object
{
    $id = (string) Str::uuid();
    $data = ['id' => $id, 'tenant_id' => $test->tenant->id, 'user_id' => $test->user->id, 'wallet_id' => $test->wallet->id,
        'request_id' => (string) Str::uuid(), 'request_hash' => hash('sha256', $id), 'asset_code' => 'USDT', 'amount' => '100.01',
        'requested_amount' => '100', 'status' => 'PENDING', 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()];
    if ($source === 'primary') {
        DB::table('wallet_topup_orders')->insert($data + ['payment_provider' => 'trc20-shared', 'payment_rail' => 'TRC20_SHARED', 'expected_amount' => '100.01',
            'identification_increment' => '0.01', 'network_code' => 'TRON', 'deposit_address' => 'T111111111111111111111111111111111', 'token_contract' => 'T222222222222222222222222222222222']);

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
