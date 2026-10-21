<?php

use App\Application\Tenant\OverviewRechargeAdjustments;
use App\Application\Tenant\PlatformOverviewQuery;
use App\Application\Tenant\PlatformOverviewSources;
use App\Domain\Admin\Models\AdminUser;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed();
    Http::preventStrayRequests();
    Queue::fake();
    $this->actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->adjustments = app(OverviewRechargeAdjustments::class);
    CarbonImmutable::setTestNow('2026-10-21T12:00:00+08:00');
    Carbon::setTestNow('2026-10-21T12:00:00+08:00');
});
afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});
function overviewDisplayInput(string $amount = '10.12345678', string $date = '2026-10-21'): array
{
    return ['amount' => $amount, 'business_date' => $date, 'reason' => 'Synthetic overview display adjustment', 'request_id' => (string) Str::uuid(), 'confirmed' => true];
}
function inflowOverviewAccess(): array
{
    return array_replace(array_fill_keys(['inflow', 'outflow', 'partners', 'cards', 'providers', 'assets'], false), ['inflow' => true]);
}

it('appends signed display records with atomic audit and identical retry without changing business evidence', function () {
    $tables = ['wallet_topup_orders', 'asset_deposit_orders', 'ledger_entries', 'ledger_postings', 'ledger_accounts', 'wallets', 'partner_journal_entries', 'user_advance_entries'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()]);
    $input = overviewDisplayInput();
    $entry = $this->adjustments->record($this->actor, $input);
    expect($this->adjustments->record($this->actor, $input)->id)->toBe($entry->id);
    expect(fn () => $this->adjustments->record($this->actor, array_replace($input, ['amount' => '-10'])))->toThrow(HttpException::class);
    $this->adjustments->record($this->actor, overviewDisplayInput('-20'));
    expect($this->adjustments->periods(CarbonImmutable::now())['total'])->toBe('-9.87654322')
        ->and(DB::table('audit_logs')->where('action', 'OVERVIEW_RECHARGE_DISPLAY_ADJUSTED')->count())->toBe(2);
    foreach ($before as $table => $json) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($json);
    }
    expect(fn () => DB::transaction(fn () => DB::table('platform_overview_recharge_adjustments')->where('id', $entry->id)->update(['amount' => '100'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('platform_overview_recharge_adjustments')->where('id', $entry->id)->delete()))->toThrow(QueryException::class);
    Http::assertNothingSent();
});

it('adjusts only lifetime totals and sources while daily recent and shared receipt sources stay unchanged', function () {
    DB::statement("SET LOCAL TIME ZONE 'America/New_York'");
    foreach ([['5', '2026-10-21'], ['-9', '2026-10-20'], ['3', '2026-10-14'], ['20', '2026-09-01']] as [$amount, $date]) {
        $this->adjustments->record($this->actor, overviewDisplayInput($amount, $date));
    }
    $query = app(PlatformOverviewQuery::class);
    $cash = $query->execute(inflowOverviewAccess())['inflow'];
    expect($cash['recordedTotals']['total'])->toBe('0.00000000')->and($cash['totals']['today'])->toBe('0.00000000')
        ->and($cash['totals']['d7'])->toBe('0.00000000')->and($cash['totals']['d15'])->toBe('0.00000000')
        ->and($cash['totals']['total'])->toBe('19.00000000')->and($query->cashSource(false)->count())->toBe(0);
    $sources = app(PlatformOverviewSources::class);
    foreach (['today', 'd7', 'd15', 'total'] as $period) {
        $source = $sources->read('inflow', $period, 1, null);
        expect($source['amount'])->toBe($cash['totals'][$period])->and($source['recordedAmount'])->toBe($period === 'total' ? '0.00000000' : null)
            ->and($source['rows']['total'])->toBe(0);
        if ($period !== 'total') {
            expect($source['adjustments'])->toBeNull();
        }
        expect(BigDecimal::of($sources->read('inflow', $period, 1, 'USDT')['amount'])->compareTo($cash['totals'][$period]))->toBe(0);
    }
    expect($sources->read('inflow', 'total', 1, 'USDC')['adjustments'])->toBeNull();
    $at = CarbonImmutable::now()->subSecond();
    expect($this->adjustments->periods($at)['total'])->toBe('0.00000000');
    Http::assertNothingSent();
});

it('validates nonzero signed precision dates reasons and confirmation', function () {
    foreach (['0', '-0.00000000', '1.123456789', '1e2', '1000000000000000000'] as $amount) {
        expect(fn () => $this->adjustments->record($this->actor, overviewDisplayInput($amount)))->toThrow(ValidationException::class);
    }
    foreach ([['confirmed' => false], ['reason' => ''], ['business_date' => '2026-10-22']] as $change) {
        expect(fn () => $this->adjustments->record($this->actor, array_replace(overviewDisplayInput(), $change)))->toThrow(ValidationException::class);
    }
    expect(DB::table('platform_overview_recharge_adjustments')->count())->toBe(0);
});

it('serves private numbered history and rejects tenant administrators and missing manage permission', function () {
    for ($i = 0; $i < 21; $i++) {
        $this->adjustments->record($this->actor, overviewDisplayInput('1'));
    }
    $url = 'http://admin.localhost/platform/overview/recharge-adjustments';
    $this->actingAs($this->actor, 'platform_admin')->getJson($url.'?page=2')->assertOk()->assertJsonPath('total', 21)->assertJsonPath('page', 2)->assertJsonCount(1, 'items')->assertJsonPath('items.0.created_at', fn ($value) => str_contains($value, 'T'))->assertHeader('Cache-Control', 'no-store, private');
    $tenantActor = AdminUser::whereHas('memberships', fn ($q) => $q->where('scope_type', 'TENANT'))->firstOrFail();
    expect(fn () => $this->adjustments->record($tenantActor, overviewDisplayInput()))->toThrow(HttpException::class);
    $permission = DB::table('permissions')->where('name', 'wallet_topups.confirm')->value('id');
    DB::table('role_permissions')->where('permission_id', $permission)->delete();
    $this->actingAs($this->actor, 'platform_admin')->getJson($url)->assertOk();
    $this->actingAs($this->actor, 'platform_admin')->postJson($url, overviewDisplayInput())->assertForbidden();
});
