<?php

use App\Application\Assets\AssetActivityLabel;
use App\Application\Wallet\AdjustWalletBalance;
use App\Application\Wallet\WalletActivityQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerReconciliationService;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Services\WalletProvisioner;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false]);
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $this->user, 'USDT'));
    $this->action = app(AdjustWalletBalance::class);
    $this->data = ['asset' => 'USDT', 'direction' => 'INCREASE', 'amount' => '12.34', 'reason' => 'Isolated adjustment test', 'request_id' => (string) Str::uuid(), 'confirmed' => true];
    $this->url = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/users/'.$this->user->id.'/wallet-adjustments';
});

function walletAdjust($test, array $changes = []): object
{
    return $test->action->execute($test->tenant->id, $test->user->id, $test->owner, array_replace($test->data, $changes));
}

it('records exact increases and decreases with immutable evidence and no activation effects', function () {
    $row = walletAdjust($this);
    walletAdjust($this, ['direction' => 'DECREASE', 'amount' => '2.34', 'request_id' => (string) Str::uuid()]);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    expect(LedgerAccount::where('user_id', $this->user->id)->where('account_type', 'USER_AVAILABLE')->value('balance'))->toBe('10.00000000');
    expect(DB::table('wallet_adjustments')->count())->toBe(2);
    expect(DB::table('audit_logs')->where('action', 'WALLET_ADJUSTMENT')->count())->toBe(2);
    expect(DB::table('account_activations')->count())->toBe(0);
    expect(DB::table('commission_awards')->count())->toBe(0);
    expect(collect(app(WalletActivityQuery::class)->get($this->tenant->id, $this->user->id))->pluck('eventType')->unique()->all())->toBe(['WALLET_ADJUSTMENT']);
    expect(fn () => DB::transaction(fn () => DB::table('wallet_adjustments')->where('id', $row->id)->update(['reason' => 'Changed'])))->toThrow(QueryException::class);
});

it('deduplicates retries and rejects changed intent', function () {
    $first = walletAdjust($this);
    expect(walletAdjust($this)->id)->toBe($first->id);
    expect(fn () => walletAdjust($this, ['amount' => '15']))->toThrow(DomainException::class);
    expect(DB::table('wallet_adjustments')->count())->toBe(1);
});

it('rejects insufficient funds and invalid amounts atomically', function ($changes) {
    expect(fn () => walletAdjust($this, $changes))->toThrow(DomainException::class);
    expect(DB::table('wallet_adjustments')->count())->toBe(0);
    expect(DB::table('ledger_entries')->count())->toBe(0);
})->with([[['direction' => 'DECREASE']], [['amount' => '0']], [['amount' => '-1']], [['amount' => '1e2']], [['amount' => '1.000000001']], [['reason' => '  ']]]);

it('requires permission confirmation and exact company ownership on HTTP requests', function () {
    $this->actingAs($this->owner, 'platform_admin')->get($this->url)->assertOk();
    $this->post($this->url, array_replace($this->data, ['confirmed' => false]))->assertSessionHasErrors('confirmed');
    $this->post($this->url, $this->data)->assertRedirect();
    $other = User::where('tenant_id', '<>', $this->tenant->id)->firstOrFail();
    $this->get(str_replace($this->user->id, $other->id, $this->url))->assertNotFound();
    $tenantAdmin = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($tenantAdmin, 'platform_admin')->post($this->url, $this->data)->assertForbidden();
    expect(DB::table('wallet_adjustments')->count())->toBe(1);
});

it('preserves native precision and isolates currencies', function () {
    DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $this->user, 'ETH'));
    walletAdjust($this, ['asset' => 'ETH', 'amount' => '0.000000000000000001']);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    expect(LedgerAccount::where('user_id', $this->user->id)->where('asset_code', 'ETH')->where('account_type', 'USER_AVAILABLE')->first()->balance)->toBe('0.000000000000000001');
    expect(LedgerAccount::where('user_id', $this->user->id)->where('asset_code', 'USDT')->where('account_type', 'USER_AVAILABLE')->first()->balance)->toBe('0.00000000');
});

it('rejects inactive and missing wallets without creating wallets', function () {
    expect(fn () => walletAdjust($this, ['asset' => 'BTC']))->toThrow(DomainException::class);
    DB::table('wallets')->where('user_id', $this->user->id)->update(['status' => 'SUSPENDED']);
    expect(fn () => walletAdjust($this))->toThrow(DomainException::class);
    expect(DB::table('wallet_adjustments')->count())->toBe(0);
});

it('rolls back accounting and history together and forbids deleting history', function () {
    try {
        DB::transaction(function () {
            walletAdjust($this);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect(DB::table('ledger_entries')->count())->toBe(0)->and(DB::table('wallet_adjustments')->count())->toBe(0);
    $row = walletAdjust($this);
    expect(fn () => DB::transaction(fn () => DB::table('wallet_adjustments')->where('id', $row->id)->delete()))->toThrow(QueryException::class);
});

it('blocks revoked permissions and cross-company posts', function () {
    $other = User::where('tenant_id', '<>', $this->tenant->id)->firstOrFail();
    $this->actingAs($this->owner, 'platform_admin')->post(str_replace($this->user->id, $other->id, $this->url), $this->data)->assertNotFound();
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'wallet.adjust')->value('id'))->delete();
    $this->post($this->url, $this->data)->assertForbidden();
    expect(DB::table('ledger_entries')->count())->toBe(0);
});

it('rejects a fabricated adjustment ledger entry without business evidence', function () {
    walletAdjust($this);
    $available = LedgerAccount::where('user_id', $this->user->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $clearing = LedgerAccount::where('tenant_id', $this->tenant->id)->where('account_type', 'TENANT_ADJUSTMENT_CLEARING')->firstOrFail();
    expect(fn () => DB::transaction(function () use ($available, $clearing) {
        app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'USDT', 'fake:'.Str::uuid(), 'WALLET_ADJUSTMENT', 'WALLET_ADJUSTMENT', (string) Str::uuid(), null, [
            new LedgerPostingInstruction($available->id, Money::of('1', 'USDT')),
            new LedgerPostingInstruction($clearing->id, Money::of('-1', 'USDT')),
        ]));
        DB::statement('SET CONSTRAINTS wallet_adjustment_entry_evidence IMMEDIATE');
    }))->toThrow(QueryException::class);
});

it('allows an exact full deduction and rejects a subsequent deduction', function () {
    walletAdjust($this);
    walletAdjust($this, ['direction' => 'DECREASE', 'request_id' => (string) Str::uuid()]);
    expect(fn () => walletAdjust($this, ['direction' => 'DECREASE', 'amount' => '0.01', 'request_id' => (string) Str::uuid()]))->toThrow(DomainException::class);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    expect(app(LedgerReconciliationService::class)->mismatches($this->tenant->id))->toHaveCount(0);
    expect(DB::table('wallet_adjustments')->count())->toBe(2);
    expect(AssetActivityLabel::for('WALLET_ADJUSTMENT', '-1'))->toBe('Admin adjustment');
});
