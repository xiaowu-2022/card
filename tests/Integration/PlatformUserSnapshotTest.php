<?php

use App\Application\Cleanup\DataCleanup;
use App\Application\Partners\PartnerManagement;
use App\Application\User\CreatePlatformUserAction;
use App\Application\User\PlatformUserFinancialSummary;
use App\Application\User\PlatformUserQuery;
use App\Application\User\Snapshots\UserSnapshotReader;
use App\Application\User\Snapshots\UserSnapshotSources;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\MarketSnapshot;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Services\WalletProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

// Real commits are necessary to exercise deferred publication. No production DB.
uses(TestCase::class);

beforeEach(function () {
    if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'card_ui_test') {
        throw new RuntimeException('Snapshot integration tests require card_ui_test.');
    }
    DB::unprepared('DROP FUNCTION IF EXISTS protect_user_account_id() CASCADE; DROP FUNCTION IF EXISTS assign_user_account_id() CASCADE; DROP FUNCTION IF EXISTS allocate_user_account_id(uuid,timestamptz) CASCADE;');
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($this->owner, 'platform_admin');
    $this->url = 'http://admin.localhost/platform/users';
});

function snapshotCutoff(): string
{
    // Close a second boundary after the last committed evidence, without fake timestamps.
    $target = CarbonImmutable::parse(DB::scalar('SELECT clock_timestamp()'))->ceilSecond();
    usleep(max(1, (int) (microtime(true) < $target->getTimestamp() ? ($target->getTimestamp() - microtime(true)) * 1000000 + 20000 : 20000)));

    return $target->setTimezone('Asia/Shanghai')->format('Y-m-d\TH:i:s');
}

function snapshotCredit($test, string $amount): void
{
    DB::transaction(function () use ($test, $amount) {
        app(WalletProvisioner::class)->provision($test->tenant, $test->user, 'ETH');
        $available = LedgerAccount::where('user_id', $test->user->id)->where('asset_code', 'ETH')->where('account_type', 'USER_AVAILABLE')->firstOrFail();
        $clearing = LedgerAccount::where('tenant_id', $test->tenant->id)->where('asset_code', 'ETH')->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
        app(LedgerWriter::class)->post(new LedgerPostingPlan($test->tenant->id, 'ETH', 'snapshot:'.Str::uuid(), 'TEST_TOPUP', null, null, null, [
            new LedgerPostingInstruction($available->id, Money::of($amount, 'ETH')),
            new LedgerPostingInstruction($clearing->id, Money::of('-'.$amount, 'ETH')),
        ]));
    });
}

it('restores identities balances and status consistently in list details and CSV after real commits', function () {
    snapshotCredit($this, '0.123456789012345678');
    DB::table('user_profiles')->where('user_id', $this->user->id)->update(['display_name' => '=历史,姓名']);
    $cutoff = snapshotCutoff();
    snapshotCredit($this, '0.200000000000000001');
    DB::table('user_profiles')->where('user_id', $this->user->id)->update(['display_name' => '现在姓名']);
    DB::table('users')->where('id', $this->user->id)->update(['status' => 'SUSPENDED', 'last_login_at' => now(), 'wallet_transfer_blocked' => true]);
    $query = '?'.http_build_query(['company' => $this->tenant->id, 'search' => $this->user->account_id, 'status' => 'ACTIVE', 'as_of' => $cutoff]);
    $before = DB::table('platform_user_history')->count();
    $this->get($this->url.$query)->assertOk()->assertInertia(fn ($p) => $p
        ->where('users.total', 1)->where('snapshotAt', $cutoff)->where('readOnly', true)->where('canCreateUser', false)
        ->where('users.data.0.displayName', '=历史,姓名')->where('users.data.0.status', 'ACTIVE')
        ->where('users.data.0.wallets.0.available', '0.123456789012345678')
        ->where('users.data.0.operationRestrictions.wallet_transfer_blocked', false));
    $this->getJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/users/{$this->user->id}/details?as_of=".$cutoff)
        ->assertOk()->assertJsonPath('user.displayName', '=历史,姓名')->assertJsonPath('readOnly', true);
    $this->getJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/users/{$this->user->id}/funds-summary?as_of=".$cutoff)
        ->assertOk()->assertJsonPath('items.3.amount', '0.123456789012345678');
    $csv = $this->get($this->url.'/export'.$query.'&page=999')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
    expect($csv)->toStartWith("\xEF\xBB\xBF")->toContain("'=历史,姓名", '0.123456789012345678', $cutoff)->not->toContain('现在姓名', '0.323456789012345679');
    expect(DB::table('platform_user_history')->count())->toBe($before);
    expect(DB::table('users')->where('id', $this->user->id)->value('status'))->toBe('SUSPENDED');
    Http::assertNothingSent();
});

it('applies historical partner and support filters and excludes later registrations', function () {
    $cutoff = snapshotCutoff();
    app(PartnerManagement::class)->configure($this->owner, $this->tenant->id, ['account_id' => $this->user->account_id, 'enabled' => true, 'share_percent' => '40']);
    $late = User::create(['tenant_id' => $this->tenant->id, 'email' => 'late-snapshot@example.test', 'password_hash' => bcrypt('password'), 'status' => 'ACTIVE'])->refresh();
    $query = '?'.http_build_query(['company' => $this->tenant->id, 'search' => $this->user->account_id, 'as_of' => $cutoff]);
    $this->get($this->url.$query)->assertOk()->assertInertia(fn ($p) => $p->where('users.total', 1));
    $this->get($this->url.'?search='.$this->user->account_id)->assertOk()->assertInertia(fn ($p) => $p->where('users.total', 0));
    $this->get($this->url.'?search='.$late->account_id.'&as_of='.$cutoff)->assertOk()->assertInertia(fn ($p) => $p->where('users.total', 0));
    $this->getJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/users/{$late->id}/details?as_of=".$cutoff)->assertNotFound();
});

it('rejects unsupported future and malformed cutoffs and exports an empty header safely', function () {
    foreach (['2000-01-01T00:00:00', '2999-01-01T00:00:00', '2026-10-13', 'not-a-time'] as $at) {
        $this->getJson($this->url.'/export?as_of='.$at)->assertUnprocessable()->assertJsonValidationErrors('as_of');
    }
    $csv = $this->get($this->url.'/export?search=nonexistent-snapshot-account')->assertOk()->getContent();
    expect(substr_count($csv, "\r\n"))->toBe(1);
    $this->owner->update(['status' => 'SUSPENDED']);
    $this->getJson($this->url.'/export')->assertForbidden();
});

it('rolls back temporal evidence and restores the query grammar and clock after failure', function () {
    $count = DB::table('platform_user_history')->count();
    DB::beginTransaction();
    DB::table('user_profiles')->where('user_id', $this->user->id)->update(['display_name' => 'rollback snapshot']);
    DB::rollBack();
    expect(DB::table('platform_user_history')->count())->toBe($count);
    $at = CarbonImmutable::parse(snapshotCutoff(), 'Asia/Shanghai');
    $grammar = DB::connection()->getQueryGrammar();
    try {
        app(UserSnapshotReader::class)->read($at, function () {
            expect(DB::table('users')->count())->toBeGreaterThan(0);
            throw new RuntimeException('expected');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('expected');
    }
    expect(DB::connection()->getQueryGrammar())->toBe($grammar)->and(CarbonImmutable::getTestNow())->toBeNull();
    Http::assertNothingSent();
});

it('exports all filtered pages and enforces live read permissions for historical data', function () {
    for ($i = 0; $i < 24; $i++) {
        $copy = $this->user->replicate(['account_id']);
        $copy->forceFill(['email' => 'snapshot-page-'.$i.'@example.test'])->save();
    }
    $cutoff = snapshotCutoff();
    $filters = http_build_query(['company' => $this->tenant->id, 'search' => 'snapshot-page-', 'as_of' => $cutoff]);
    $this->get($this->url.'?'.$filters.'&page=2')->assertOk()->assertInertia(fn ($p) => $p
        ->where('users.total', 24)->has('users.data', 4)
        ->where('users.prev_page_url', fn ($url) => str_contains($url, 'as_of=')));
    $csv = $this->get($this->url.'/export?'.$filters.'&page=2')->assertOk()->getContent();
    expect(substr_count($csv, "\r\n"))->toBe(25);
    foreach (['wallet.read', 'ledger.read', 'wallet_topups.read', 'withdrawals.read'] as $permission) {
        $id = DB::table('permissions')->where('name', $permission)->value('id');
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
    $restricted = $this->get($this->url.'/export?'.$filters)->assertOk()->getContent();
    expect($restricted)->not->toContain('可用余额', '预估总资金', '实际到账金额', '年费佣金', '转出资金');
    expect(DB::table('platform_user_history')->where('table_name', 'users')->first()->row_data)->not->toContain('password_hash');
});

it('pins saved rates to the cutoff and never substitutes future or stale rates', function () {
    snapshotCredit($this, '0.123456789012345678');
    $snapshot = MarketSnapshot::create(['provider' => 'OKX', 'usd_prices' => [], 'usdt_rates' => ['USDC' => '1', 'ETH' => '2000', 'BTC' => '60000'], 'observed_at' => now(), 'created_at' => now()]);
    $cutoff = snapshotCutoff();
    MarketSnapshot::create(['provider' => 'OKX', 'usd_prices' => [], 'usdt_rates' => ['USDC' => '1', 'ETH' => '9000', 'BTC' => '60000'], 'observed_at' => now(), 'created_at' => now()]);
    $this->getJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/users/{$this->user->id}/funds-summary?as_of=".$cutoff)
        ->assertOk()->assertJsonPath('estimate', '246.91357802');
    $expired = CarbonImmutable::parse($cutoff, 'Asia/Shanghai')->addSeconds(130);
    $result = app(UserSnapshotReader::class)->read($expired, fn () => app(PlatformUserFinancialSummary::class)->funds($this->tenant->id, $this->user->id));
    expect($result['estimate'])->toBeNull();
    Http::assertNothingSent();
});

it('removes temporal copies through the reviewed cleanup backup and rejects incomplete older snapshots', function () {
    Storage::fake('private');
    $user = app(CreatePlatformUserAction::class)->execute($this->tenant->id, $this->owner,
        ['email' => 'snapshot-cleanup@example.test', 'display_name' => 'Cleaned snapshot', 'password' => 'secret123', 'request_id' => (string) Str::uuid()]);
    $cutoff = snapshotCutoff();
    $cleanup = app(DataCleanup::class);
    $preview = $cleanup->preview($this->tenant->id, $this->owner, ['user_ids' => [$user->id], 'modules' => ['account']]);
    expect(collect($preview['summary']['counts'])->pluck('table')->all())->toContain('platform_user_history');
    $cleanup->execute($this->tenant->id, $preview['id'], $this->owner, (string) Str::uuid(), true);
    expect(DB::table('platform_user_history')->where('user_id', $user->id)->count())->toBe(0);
    $this->getJson($this->url.'/export?'.http_build_query(['company' => $this->tenant->id, 'as_of' => $cutoff]))->assertUnprocessable()->assertJsonValidationErrors('as_of');
    $this->get($this->url.'/export?search='.$user->account_id)->assertOk();
});

it('publishes every source change atomically at transaction finalization and never leaks an in-flight writer', function () {
    config(['database.connections.snapshot_writer' => config('database.connections.pgsql')]);
    $writer = DB::connection('snapshot_writer');
    $old = DB::table('user_profiles')->where('user_id', $this->user->id)->value('display_name');
    $writer->beginTransaction();
    try {
        $writer->table('user_profiles')->where('user_id', $this->user->id)->update(['display_name' => 'Committed later']);
        $cutoff = snapshotCutoff();
        $url = "http://admin.localhost/platform/tenants/{$this->tenant->id}/users/{$this->user->id}/details?as_of=".$cutoff;
        $this->getJson($url)->assertOk()->assertJsonPath('user.displayName', $old);
        $writer->commit();
        $this->getJson($url)->assertOk()->assertJsonPath('user.displayName', $old);
        $newCutoff = snapshotCutoff();
        $this->getJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/users/{$this->user->id}/details?as_of=".$newCutoff)
            ->assertOk()->assertJsonPath('user.displayName', 'Committed later');
    } finally {
        if ($writer->transactionLevel()) {
            $writer->rollBack();
        }
        DB::disconnect('snapshot_writer');
    }
});

it('rejects exports above the complete-result limit rather than returning a truncated file', function () {
    // Exercise the endpoint's upper bound without spending minutes in the unrelated
    // registration account-ID allocator to create ten thousand synthetic identities.
    app()->instance(PlatformUserQuery::class, new class
    {
        public function paginate(...$arguments): LengthAwarePaginator
        {
            return new LengthAwarePaginator([], 10001, 100);
        }
    });
    $this->getJson($this->url.'/export?search=snapshot-limit-')->assertUnprocessable()->assertJsonValidationErrors('export')
        ->assertJsonPath('errors.export.0', 'Too many users. Narrow the filters to 10,000 records or fewer.');
});

/** Synthetic reporting evidence only; never issue cards, credit receipts or call providers. */
function snapshotEvidence(string $table, array $row): void
{
    DB::table('platform_user_history')->insert([
        'table_name' => $table, 'source_key' => json_encode(['id' => $row['id']]),
        'tenant_id' => $row['tenant_id'] ?? null, 'user_id' => $row['user_id'] ?? null,
        'transaction_id' => DB::raw('pg_current_xact_id()::text'),
        'row_data' => json_encode(array_intersect_key($row, array_flip(UserSnapshotSources::COLUMNS[$table]))),
    ]);
}

it('uses the receipt classification effective at the cutoff rather than its later correction', function () {
    $id = (string) Str::uuid();
    snapshotEvidence('wallet_topup_orders', ['id' => $id, 'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
        'asset_code' => 'USDT', 'status' => 'CREDITED', 'amount' => '100.01', 'actual_received_amount' => '97.12345678', 'manual_receipt_type' => 'ACTUAL']);
    $cutoff = snapshotCutoff();
    snapshotEvidence('deposit_receipt_type_changes', ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
        'source' => 'primary', 'order_id' => $id, 'after_type' => 'ADVANCE', 'after_journal_id' => null, 'revision' => 1]);
    $query = http_build_query(['search' => $this->user->account_id, 'as_of' => $cutoff]);
    $this->get($this->url.'?'.$query)->assertOk()->assertInertia(fn ($p) => $p
        ->where('users.data.0.receiptTotals.0.actual', '97.12345678')->where('users.data.0.receiptTotals.0.advance', '0'));
    $later = snapshotCutoff();
    $this->get($this->url.'?'.http_build_query(['search' => $this->user->account_id, 'as_of' => $later]))->assertOk()->assertInertia(fn ($p) => $p
        ->where('users.data.0.receiptTotals.0.actual', '0')->where('users.data.0.receiptTotals.0.advance', '97.12345678'));
    $csv = $this->get($this->url.'/export?'.$query)->assertOk()->getContent();
    expect($csv)->toContain('97.12345678');
    Http::assertNothingSent();
});

it('pins cached card balances funding and archive scope and retains unknown values', function () {
    $product = DB::table('card_products')->first();
    $issue = (string) Str::uuid();
    $card = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
        'card_product_id' => $product->id, 'card_issue_order_id' => $issue, 'card_currency' => 'USD',
        'provider_balance' => '20.00000000', 'archived_at' => null, 'last4' => '1234',
        'provider_balance_synced_at' => now()->toIso8601String(), 'created_at' => now()->toIso8601String()];
    DB::transaction(function () use ($card, $issue) {
        snapshotEvidence('card_issue_orders', ['id' => $issue, 'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'status' => 'SUCCEEDED', 'initial_load_amount' => '20.00000000']);
        snapshotEvidence('user_cards', $card);
    });
    $cutoff = snapshotCutoff();
    $base = "http://admin.localhost/platform/tenants/{$this->tenant->id}/users/{$this->user->id}";
    snapshotEvidence('user_cards', [...$card, 'provider_balance' => null]);
    $this->getJson($base.'/cards-summary?as_of='.$cutoff)->assertOk()->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.funded', '20.00000000')->assertJsonPath('items.0.balance', '20.00000000');
    $unknownAt = snapshotCutoff();
    $this->getJson($base.'/funds-summary?as_of='.$unknownAt)->assertOk()->assertJsonPath('cardBalance', null)->assertJsonPath('estimate', null);
    snapshotEvidence('user_cards', [...$card, 'provider_balance' => '25.00000000', 'archived_at' => now()->toIso8601String()]);
    $archivedAt = snapshotCutoff();
    $this->getJson($base.'/cards-summary?as_of='.$archivedAt)->assertOk()->assertJsonPath('total', 0);
    $this->getJson($base.'/cards-summary?archived=1&as_of='.$archivedAt)->assertOk()->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.consumed', '-5.00000000');
    $this->getJson($base.'/cards-summary?as_of='.$cutoff)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.balance', '20.00000000');
    Http::assertNothingSent();
});

it('fails closed when a report tries to read an untracked live table', function () {
    $at = CarbonImmutable::parse(snapshotCutoff(), 'Asia/Shanghai');
    expect(fn () => app(UserSnapshotReader::class)->read($at, fn () => DB::table('admin_users')->count()))
        ->toThrow(LogicException::class, 'Untracked user snapshot source.');
});
