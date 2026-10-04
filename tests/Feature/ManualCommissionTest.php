<?php

require_once __DIR__.'/../Support/PromotionAdjustmentRace.php';

use App\Application\Assets\AssetActivityLabel;
use App\Application\Promotion\AdjustManualCommission;
use App\Application\Promotion\PromotionReportQuery;
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
    $this->action = app(AdjustManualCommission::class);
    $this->data = ['asset' => 'USDT', 'direction' => 'INCREASE', 'amount' => '12.34', 'reason' => 'Isolated adjustment test', 'request_id' => (string) Str::uuid(), 'confirmed' => true];
    $this->url = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/users/'.$this->user->id.'/manual-commissions';
});

function manualCommissionAdjust($test, array $changes = []): object
{
    return $test->action->execute($test->tenant->id, $test->user->id, $test->owner, array_replace($test->data, $changes));
}

it('records exact increases and decreases with immutable evidence and no activation effects', function () {
    $row = manualCommissionAdjust($this);
    manualCommissionAdjust($this, ['direction' => 'DECREASE', 'amount' => '2.34', 'request_id' => (string) Str::uuid()]);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    expect(LedgerAccount::where('user_id', $this->user->id)->where('account_type', 'USER_AVAILABLE')->value('balance'))->toBe('10.00000000');
    expect(DB::table('manual_commission_adjustments')->count())->toBe(2);
    expect(DB::table('audit_logs')->where('action', 'MANUAL_COMMISSION')->count())->toBe(2);
    expect(DB::table('account_activations')->count())->toBe(0);
    expect(DB::table('commission_awards')->count())->toBe(0);
    expect(collect(app(WalletActivityQuery::class)->get($this->tenant->id, $this->user->id))->pluck('eventType')->unique()->all())->toBe(['MANUAL_COMMISSION']);
    expect(fn () => DB::transaction(fn () => DB::table('manual_commission_adjustments')->where('id', $row->id)->update(['reason' => 'Changed'])))->toThrow(QueryException::class);
});

it('deduplicates retries and rejects changed intent', function () {
    $first = manualCommissionAdjust($this);
    expect(manualCommissionAdjust($this)->id)->toBe($first->id);
    expect(fn () => manualCommissionAdjust($this, ['amount' => '15']))->toThrow(DomainException::class);
    expect(DB::table('manual_commission_adjustments')->count())->toBe(1);
});

it('rejects insufficient funds and invalid amounts atomically', function ($changes) {
    expect(fn () => manualCommissionAdjust($this, $changes))->toThrow(DomainException::class);
    expect(DB::table('manual_commission_adjustments')->count())->toBe(0);
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
    expect(DB::table('manual_commission_adjustments')->count())->toBe(1);
});

it('reports manual income once without a fabricated source and rejects deductions above commission even with wallet funds', function () {
    manualCommissionAdjust($this, ['amount' => '12.12345678']);
    $report = app(PromotionReportQuery::class);
    expect($report->cumulative($this->tenant->id, $this->user->id))->toBe('12.12345678');
    $daily = $report->daily($this->tenant->id, $this->user->id, []);
    expect($daily['items'])->toHaveCount(1)->and($daily['items'][0]['kind'])->toBe('manual')->and($daily['items'][0]['sourceAccountId'])->toBeNull();
    expect(json_encode($daily))->not->toContain('Isolated adjustment test', 'actor_name');
    app(AdjustWalletBalance::class)->execute($this->tenant->id, $this->user->id, $this->owner, array_replace($this->data, ['amount' => '100']));
    expect(fn () => manualCommissionAdjust($this, ['direction' => 'DECREASE', 'amount' => '13', 'request_id' => (string) Str::uuid()]))->toThrow(DomainException::class);
    expect($report->cumulative($this->tenant->id, $this->user->id))->toBe('12.12345678');
});

it('requires an active existing wallet and current adjustment permission', function () {
    DB::table('wallets')->where('user_id', $this->user->id)->update(['status' => 'SUSPENDED']);
    expect(fn () => manualCommissionAdjust($this))->toThrow(DomainException::class);
    expect(DB::table('manual_commission_adjustments')->count())->toBe(0);
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'commissions.adjust')->value('id'))->delete();
    $this->actingAs($this->owner, 'platform_admin')->postJson($this->url, $this->data)->assertForbidden();
});

it('rejects a fabricated adjustment ledger entry without business evidence', function () {
    manualCommissionAdjust($this);
    $available = LedgerAccount::where('user_id', $this->user->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $clearing = LedgerAccount::where('tenant_id', $this->tenant->id)->where('account_type', 'TENANT_COMMISSION_CLEARING')->firstOrFail();
    expect(fn () => DB::transaction(function () use ($available, $clearing) {
        app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'USDT', 'fake:'.Str::uuid(), 'MANUAL_COMMISSION', 'MANUAL_COMMISSION', (string) Str::uuid(), null, [
            new LedgerPostingInstruction($available->id, Money::of('1', 'USDT')),
            new LedgerPostingInstruction($clearing->id, Money::of('-1', 'USDT')),
        ]));
        DB::statement('SET CONSTRAINTS manual_commission_adjustment_entry_evidence IMMEDIATE');
    }))->toThrow(QueryException::class);
});

it('allows an exact full deduction and rejects a subsequent deduction', function () {
    manualCommissionAdjust($this);
    manualCommissionAdjust($this, ['direction' => 'DECREASE', 'request_id' => (string) Str::uuid()]);
    expect(fn () => manualCommissionAdjust($this, ['direction' => 'DECREASE', 'amount' => '0.01', 'request_id' => (string) Str::uuid()]))->toThrow(DomainException::class);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    expect(app(LedgerReconciliationService::class)->mismatches($this->tenant->id))->toHaveCount(0);
    expect(DB::table('manual_commission_adjustments')->count())->toBe(2);
    expect(AssetActivityLabel::for('MANUAL_COMMISSION', '-1'))->toBe('Manual commission');
});

it('serializes concurrent commission deductions without overdraw or lost audit evidence', function () {
    manualCommissionAdjust($this, ['amount' => '10']);
    $tenant = $this->tenant->id;
    $user = $this->user->id;
    $actor = $this->owner->id;
    $operations = [];
    foreach (range(1, 2) as $n) {
        $data = array_replace($this->data, ['direction' => 'DECREASE', 'amount' => '7', 'request_id' => (string) Str::uuid()]);
        $operations[] = fn () => app(AdjustManualCommission::class)->execute($tenant, $user, AdminUser::findOrFail($actor), $data);
    }
    expect(racePromotionAdjustments($operations))->toEqualCanonicalizing(['completed', 'rejected']);
    expect(app(PromotionReportQuery::class)->cumulative($tenant, $user))->toBe('3.00000000');
    expect(LedgerAccount::where('user_id', $user)->where('account_type', 'USER_AVAILABLE')->value('balance'))->toBe('3.00000000');
    expect(DB::table('manual_commission_adjustments')->where('user_id',$user)->count())->toBe(2);
});
