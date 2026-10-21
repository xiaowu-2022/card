<?php

use App\Application\Partners\LegacyStockReport;
use App\Application\Partners\PartnerListSummary;
use App\Application\Partners\PartnerManagement;
use App\Application\Partners\PartnerReport;
use App\Application\Partners\PartnerStockValue;
use App\Application\Partners\UserAdvances;
use App\Application\Payment\ChangeDepositReceiptTypeAction;
use App\Application\Payment\ConfirmPlatformTopupAction;
use App\Application\Promotion\PromotionMembershipAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Services\WalletProvisioner;
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
    $this->company = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->root = User::where('tenant_id', $this->company->id)->firstOrFail();
    $this->actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->advances = app(UserAdvances::class);
    app(PromotionMembershipAction::class)->ensure($this->company->id, $this->root->id);
});

function advanceChild(User $parent): User
{
    $member = app(PromotionMembershipAction::class)->ensure($parent->tenant_id, $parent->id);
    $user = $parent->replicate(['account_id']);
    $user->forceFill(['email' => Str::uuid().'@example.test'])->save();
    app(PromotionMembershipAction::class)->ensure($parent->tenant_id, $user->id, $member->id);

    return $user->refresh();
}
function advanceInput(string $amount): array
{
    return ['amount' => $amount, 'business_date' => '2026-10-21', 'note' => 'Offline advance fixture', 'request_id' => (string) Str::uuid(), 'confirmed' => true];
}
function advancePartner($test, User $user, bool $enabled = true): object
{
    return app(PartnerManagement::class)->configure($test->actor, $user->tenant_id, ['account_id' => $user->account_id, 'enabled' => $enabled, 'share_percent' => '40']);
}

it('records signed standalone user advances with idempotency and one immutable reversal', function () {
    $before = DB::table('ledger_entries')->count();
    $input = advanceInput('12.12345678');
    $entry = $this->advances->record($this->actor, $this->company->id, $this->root->id, $input);
    expect($this->advances->record($this->actor, $this->company->id, $this->root->id, $input)->id)->toBe($entry->id);
    expect(fn () => $this->advances->record($this->actor, $this->company->id, $this->root->id, array_replace($input, ['amount' => '13'])))->toThrow(HttpException::class);
    $this->advances->record($this->actor, $this->company->id, $this->root->id, advanceInput('-2'));
    $reversal = advanceInput('12.12345678') + ['reverses_id' => $entry->id];
    $this->advances->record($this->actor, $this->company->id, $this->root->id, $reversal);
    expect($this->advances->ownTotals($this->company->id, [$this->root->id])[$this->root->id])->toBe('-2.00000000');
    expect(fn () => $this->advances->record($this->actor, $this->company->id, $this->root->id, array_replace($reversal, ['request_id' => (string) Str::uuid()])))->toThrow(HttpException::class);
    expect(DB::table('ledger_entries')->count())->toBe($before)->and(DB::table('partner_configurations')->count())->toBe(0);
    $partner = advancePartner($this, $this->root);
    app(PartnerManagement::class)->journal($this->actor, $this->company->id, $partner->id, ['kind' => 'ADVANCE'] + advanceInput('5'));
    $history = app(\App\Http\Controllers\Platform\UserAdvanceController::class)->show(\Illuminate\Http\Request::create('/advances'), $this->company->id, $this->root->id, $this->advances)->getData(true);
    expect($history['amount'])->toBe('3.00000000')->and($history['total'])->toBe(4)
        ->and(array_unique(array_column($history['items'], 'source')))->toContain('user', 'partner');
    Http::assertNothingSent();
});

it('rejects invalid amounts and missing confirmation', function ($amount) {
    expect(fn () => $this->advances->record($this->actor, $this->company->id, $this->root->id, advanceInput($amount)))->toThrow(ValidationException::class);
})->with(['0', '-0.00000000', '1.123456789', '1e2']);

it('requires confirmation and rejects cross-company ownership and evidence mutation', function () {
    $input = advanceInput('-1');
    unset($input['confirmed']);
    expect(fn () => $this->advances->record($this->actor, $this->company->id, $this->root->id, $input))->toThrow(ValidationException::class);
    $other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    expect(fn () => $this->advances->record($this->actor, $other->id, $this->root->id, advanceInput('1')))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    $entry = $this->advances->record($this->actor, $this->company->id, $this->root->id, advanceInput('-1'));
    expect(fn () => DB::transaction(fn () => DB::table('user_advance_entries')->where('id', $entry->id)->update(['amount' => '2'])))->toThrow(\Illuminate\Database\QueryException::class);
});

it('stops at enabled partners but includes multiple ordinary levels and follows status changes', function () {
    $rootPartner = advancePartner($this, $this->root);
    $child = advanceChild($this->root);
    $deep = advanceChild($child);
    $nested = advanceChild($deep);
    advancePartner($this, $nested);
    $blocked = advanceChild($nested);
    foreach ([[$child, '10'], [$deep, '-3'], [$nested, '40'], [$blocked, '100']] as [$user, $amount]) {
        $this->advances->record($this->actor, $this->company->id, $user->id, advanceInput($amount));
    }
    $tenant = $this->company->id;
    expect($this->advances->subordinateTotals($tenant, [$this->root->id])[$this->root->id])->toBe('7.00000000');
    $report = app(PartnerReport::class)->read($tenant, $this->root->id, false, 1, 'subordinateAdvances');
    expect($report['subordinateAdvances'])->toBe('7.00000000')->and($report['flowDetails']['total'])->toBe(2)
        ->and($report['stock'])->toBe(PartnerStockValue::afterDifference($report['stockBeforeDifference'], $report['accountBalance']['difference'], '7'));
    $summary = app(PartnerListSummary::class)->forRows([$rootPartner])[$rootPartner->id]['summary'];
    expect($summary['stock'])->toBe($report['stock'])->and($summary['subordinateAdvances'])->toBe('7.00000000');
    $access = array_replace(array_fill_keys(['partners', 'inflow', 'outflow', 'cards', 'providers', 'assets'], false), ['partners' => true]);
    $overview = app(\App\Application\Tenant\PlatformOverviewQuery::class)->execute($access);
    $sources = app(\App\Application\Tenant\PlatformOverviewSources::class)->read('stock', 'total', 1, null);
    expect($overview['partners']['stock'])->toBe($report['stock'])->and($sources['amount'])->toBe($report['stock'])
        ->and($sources['rows']['items'][0]['amount'])->toBe($report['stock'])->and($report['share'])->toBe('-2.80000000');

    $json = json_encode($report['flowDetails']);
    expect($json)->not->toContain('Offline advance fixture')->not->toContain('actor_id');
    advancePartner($this, $child);
    expect($this->advances->subordinateTotals($tenant, [$this->root->id]))->toBe([]);
    expect(app(LegacyStockReport::class)->accountBalance($tenant, $child->id, DB::table('partner_configurations')->where('user_id', $child->id)->value('id'))['advances'])->toBe('10.00000000');
    advancePartner($this, $child, false);
    expect($this->advances->subordinateTotals($tenant, [$this->root->id])[$this->root->id])->toBe('7.00000000');
    expect($this->advances->subordinateTotals(Tenant::where('slug', 'tenant-b')->value('id'), [$this->root->id]))->toBe([]);
});

it('classifies ordinary user receipts and switches without crediting twice or exposing evidence', function () {
    $wallet = DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->company, $this->root, 'USDT'));
    $id = (string) Str::uuid();
    DB::table('wallet_topup_orders')->insert(['id' => $id, 'tenant_id' => $this->company->id, 'user_id' => $this->root->id, 'wallet_id' => $wallet->id,
        'request_id' => (string) Str::uuid(), 'request_hash' => hash('sha256', $id), 'asset_code' => 'USDT', 'amount' => '100.01', 'requested_amount' => '100',
        'status' => 'PENDING', 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
        'payment_provider' => 'trc20-shared', 'payment_rail' => 'TRC20_SHARED', 'expected_amount' => '100.01', 'identification_increment' => '0.01',
        'network_code' => 'TRON', 'deposit_address' => 'T111111111111111111111111111111111', 'token_contract' => 'T222222222222222222222222222222222']);
    app(ConfirmPlatformTopupAction::class)->execute($this->company->id, $id, (string) Str::uuid(), $this->actor, true, 'ADVANCE', '80');
    $order = WalletTopupOrder::findOrFail($id);
    expect($order->user_advance_journal_id)->not->toBeNull()->and($order->advance_journal_id)->toBeNull()
        ->and($order->toArray())->not->toHaveKeys(['user_advance_journal_id', 'advance_journal_id', 'manual_receipt_type']);
    $count = DB::table('ledger_entries')->count();
    $action = app(ChangeDepositReceiptTypeAction::class);
    $action->execute($this->actor, $this->company->id, 'primary', $id, 'ACTUAL', 0, (string) Str::uuid(), true);
    expect($this->advances->ownTotals($this->company->id, [$this->root->id])[$this->root->id])->toBe('0.00000000');
    $action->execute($this->actor, $this->company->id, 'primary', $id, 'ADVANCE', 1, (string) Str::uuid(), true);
    expect($this->advances->ownTotals($this->company->id, [$this->root->id])[$this->root->id])->toBe('80.00000000')
        ->and(DB::table('ledger_entries')->count())->toBe($count)->and(DB::table('partner_configurations')->count())->toBe(0);
    Http::assertNothingSent();
});
