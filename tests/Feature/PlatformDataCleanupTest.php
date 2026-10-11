<?php

use App\Application\Assets\AssetAccess;
use App\Application\Cleanup\CleanupTombstones;
use App\Application\Cleanup\DataCleanup;
use App\Application\Cleanup\RemoveCleanedPendingJobs;
use App\Application\Payment\CreditWalletTopupAction;
use App\Application\Promotion\AdjustManualCommission;
use App\Application\Promotion\ChangeReferrer;
use App\Application\Promotion\PaidPromotionRewards;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\Promotion\PromotionReportQuery;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\User\CreatePlatformUserAction;
use App\Application\User\IssueConsumerDeviceToken;
use App\Application\Wallet\TransferWalletBalanceAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Jobs\CreditWalletTopupJob;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    Storage::fake('private');
    Http::preventStrayRequests();
    $this->tenant = Tenant::where('slug', 'tenant-a')->sole();
    $this->actor = AdminUser::where('email', 'owner@platform.local')->sole();
    $this->user = app(CreatePlatformUserAction::class)->execute($this->tenant->id, $this->actor,
        ['email' => 'cleanup@example.test', 'display_name' => 'Cleanup fixture', 'password' => 'secret123', 'request_id' => (string) Str::uuid()]);
    $this->cleanup = app(DataCleanup::class);
    $this->url = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/data-cleanup';
});

function cleanupPreview(object $test, array $modules = ['account'], ?User $user = null): array
{
    return $test->cleanup->preview($test->tenant->id, $test->actor, ['user_ids' => [$user?->id ?? $test->user->id], 'modules' => $modules]);
}

function cleanupTopup(object $test, string $amount = '100'): string
{
    $wallet = DB::table('wallets')->where('tenant_id', $test->tenant->id)->where('user_id', $test->user->id)->sole();
    $order = (string) Str::uuid();
    DB::table('wallet_topup_orders')->insert(['id' => $order, 'tenant_id' => $test->tenant->id, 'user_id' => $test->user->id, 'wallet_id' => $wallet->id,
        'request_id' => (string) Str::uuid(), 'request_hash' => hash('sha256', $order), 'asset_code' => 'USDT', 'amount' => $amount, 'status' => 'PENDING',
        'payment_provider' => 'MOCK', 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
    // The supported action owns exact settlement and immutable evidence.
    DB::table('wallet_topup_orders')->where('id', $order)->update(['status' => 'PAID', 'paid_at' => now()]);
    app(CreditWalletTopupAction::class)->execute($test->tenant->id, $order);

    return $order;
}

it('previews without deleting and permanently removes one full account with backup and reservations', function () {
    $id = $this->user->id;
    $account = $this->user->account_id;
    $member = DB::table('promotion_members')->where('user_id', $id)->sole();
    $other = User::where('tenant_id', $this->tenant->id)->where('id', '<>', $id)->firstOrFail();
    $config = DB::table('tenant_business_settings')->where('tenant_id', $this->tenant->id)->first();
    $preview = cleanupPreview($this);
    expect($preview['summary']['blockers'])->toBe([])->and(User::find($id))->not->toBeNull();
    $request = (string) Str::uuid();
    $result = $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, $request, true);
    expect($result['status'])->toBe('COMPLETED')->and(User::find($id))->toBeNull()->and(User::find($other->id))->not->toBeNull();
    expect(DB::table('promotion_members')->where('id', $member->id)->exists())->toBeFalse();
    expect(DB::table('promotion_invitation_reservations')->where('code', $member->invitation_code)->value('cleanup_member_id'))->toBe($member->id);
    expect(CleanupTombstones::contains('user-account:'.$this->tenant->id, $account))->toBeTrue();
    expect(DB::table('tenant_business_settings')->where('tenant_id', $this->tenant->id)->first())->toEqual($config);
    $batch = DB::table('data_cleanup_batches')->where('id', $preview['id'])->sole();
    $encrypted = Storage::disk('private')->get($batch->backup_path);
    expect($encrypted)->not->toContain('cleanup@example.test');
    $backup = json_decode(gzdecode(base64_decode(Crypt::decryptString($encrypted))), true);
    expect($backup['records']['users'][0]['id'])->toBe($id);
    expect($this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, $request, true)['status'])->toBe('COMPLETED');
    expect(DB::table('audit_logs')->where('action', 'PLATFORM_DATA_CLEANED')->count())->toBe(1);
});

it('cleans complete topup ledger entries and recomputes balances while preserving the account', function () {
    $order = cleanupTopup($this, '12.345678');
    $entry = DB::table('wallet_topup_orders')->where('id', $order)->value('ledger_entry_id');
    $preview = cleanupPreview($this, ['orders']);
    expect($preview['summary']['blockers'])->toBe([]);
    $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), true);
    expect(User::find($this->user->id))->not->toBeNull()->and(DB::table('wallet_topup_orders')->where('id', $order)->exists())->toBeFalse();
    expect(DB::table('ledger_entries')->where('id', $entry)->exists())->toBeFalse()->and(DB::table('ledger_postings')->where('ledger_entry_id', $entry)->exists())->toBeFalse();
    expect(DB::table('ledger_accounts')->where('user_id', $this->user->id)->where('balance', '<>', 0)->count())->toBe(0);
    expect(CleanupTombstones::contains('resource', $order))->toBeTrue();
});

it('rejects stale previews and requires an explicit confirmation', function () {
    $preview = cleanupPreview($this);
    expect(fn () => $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), false))->toThrow(DomainException::class);
    $this->user->profile->update(['display_name' => 'changed']);
    expect(fn () => $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), true))->toThrow(DomainException::class, 'Data changed');
    expect(User::find($this->user->id))->not->toBeNull()->and(Storage::disk('private')->files('data-cleanup'))->toBe([]);
});

it('blocks full account deletion with descendants but permits independent empty module previews', function () {
    $member = DB::table('promotion_members')->where('user_id', $this->user->id)->sole();
    $child = User::create(['tenant_id' => $this->tenant->id, 'email' => 'child-cleanup@example.test', 'password_hash' => 'secret123', 'status' => 'ACTIVE']);
    app(PromotionMembershipAction::class)->ensure($this->tenant->id, $child->id, $member->id);
    expect(cleanupPreview($this)['summary']['blockers'][0]['code'])->toBe('CLEANUP_HAS_DESCENDANTS');
    expect(cleanupPreview($this, ['commissions'])['summary']['blockers'])->toBe([]);
});

it('limits all endpoints to active platform membership and company-owned targets', function () {
    $other = User::where('tenant_id', '<>', $this->tenant->id)->firstOrFail();
    $this->actingAs($this->actor, 'platform_admin')->postJson($this->url.'/preview', ['user_ids' => [$other->id], 'modules' => ['account']])->assertNotFound();
    $this->postJson($this->url.'/preview', ['user_ids' => [$this->user->id], 'modules' => ['account'], 'tables' => ['users']])->assertUnprocessable();
    $this->getJson($this->url.'/accounts?search='.$this->user->account_id)->assertOk()->assertJsonCount(1, 'accounts');
    $this->actor->update(['status' => 'SUSPENDED']);
    $this->postJson($this->url.'/preview', ['user_ids' => [$this->user->id], 'modules' => ['account']])->assertForbidden();
});

it('retains ordinary immutable ledger protection outside the reviewed cleanup transaction', function () {
    $order = cleanupTopup($this);
    $entry = DB::table('wallet_topup_orders')->where('id', $order)->value('ledger_entry_id');
    expect(fn () => DB::transaction(fn () => DB::table('ledger_entries')->where('id', $entry)->delete()))->toThrow(QueryException::class, 'immutable');
    expect(DB::table('ledger_entries')->where('id', $entry)->exists())->toBeTrue();
});

function cleanupSecondUser(object $test): User
{
    return app(CreatePlatformUserAction::class)->execute($test->tenant->id, $test->actor,
        ['email' => Str::uuid().'@example.test', 'display_name' => 'Other account', 'password' => 'secret123', 'request_id' => (string) Str::uuid()]);
}

it('removes both sides of transfers without deleting the unselected recipient or their funding', function () {
    $recipient = cleanupSecondUser($this);
    cleanupTopup($this, '100.123456');
    $transfer = app(TransferWalletBalanceAction::class)->execute($this->tenant->id, $this->user->id, $recipient->account_id, '20.123456', (string) Str::uuid());
    $preview = cleanupPreview($this);
    expect($preview['summary']['blockers'])->toBe([]);
    expect(collect($preview['summary']['affectedAccounts'])->firstWhere('id', $recipient->id)['deleted'])->toBeFalse();
    $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), true);
    expect(User::find($recipient->id))->not->toBeNull()->and(DB::table('wallet_transfers')->where('id', $transfer->id)->exists())->toBeFalse();
    expect(DB::table('ledger_entries')->where('id', $transfer->ledger_entry_id)->exists())->toBeFalse();
    expect(DB::table('ledger_accounts')->where('user_id', $recipient->id)->where('balance', '<>', 0)->count())->toBe(0);
});

it('blocks removal of a received commission already spent by an unselected account', function () {
    $recipient = cleanupSecondUser($this);
    $commission = app(AdjustManualCommission::class)->execute($this->tenant->id, $this->user->id, $this->actor,
        ['direction' => 'INCREASE', 'amount' => '30', 'reason' => 'isolated fixture', 'commission_type' => 'activation', 'request_id' => (string) Str::uuid()]);
    app(TransferWalletBalanceAction::class)->execute($this->tenant->id, $this->user->id, $recipient->account_id, '20', (string) Str::uuid());
    $preview = cleanupPreview($this, ['commissions']);
    expect(collect($preview['summary']['blockers'])->pluck('code')->all())->toContain('CLEANUP_NEGATIVE_BALANCE');
    expect(fn () => $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), true))->toThrow(DomainException::class);
    expect(DB::table('manual_commission_adjustments')->where('id', $commission->id)->exists())->toBeTrue();
    expect(Storage::disk('private')->files('data-cleanup'))->toBe([]);
});

it('removes received manual commission and keeps unrelated topups and reports consistent', function () {
    $order = cleanupTopup($this, '7.654321');
    $commission = app(AdjustManualCommission::class)->execute($this->tenant->id, $this->user->id, $this->actor,
        ['direction' => 'INCREASE', 'amount' => '1.234567', 'reason' => 'isolated fixture', 'commission_type' => 'annual', 'request_id' => (string) Str::uuid()]);
    $preview = cleanupPreview($this, ['commissions']);
    expect($preview['summary']['blockers'])->toBe([]);
    $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), true);
    expect(DB::table('wallet_topup_orders')->where('id', $order)->exists())->toBeTrue();
    expect(DB::table('manual_commission_adjustments')->where('id', $commission->id)->exists())->toBeFalse();
    expect(DB::table('ledger_accounts')->where('user_id', $this->user->id)->where('account_type', 'USER_AVAILABLE')->value('balance'))->toBe('7.654321000000000000');
    expect((float) app(PromotionReportQuery::class)->cumulative($this->tenant->id, $this->user->id))->toBe(0.0);
    expect(fn () => app(AdjustManualCommission::class)->execute($this->tenant->id, $this->user->id, $this->actor,
        ['direction' => 'INCREASE', 'amount' => '1.234567', 'reason' => 'isolated fixture', 'commission_type' => 'annual', 'request_id' => $commission->request_id]))->toThrow(QueryException::class, 'replayed');
});

it('does not delete any data when the encrypted backup fails', function () {
    $preview = cleanupPreview($this);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('private')->andReturn($disk);
    expect(fn () => $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), true))->toThrow(DomainException::class, 'backup');
    expect(User::find($this->user->id))->not->toBeNull();
    expect(DB::table('data_cleanup_batches')->where('id', $preview['id'])->value('status'))->toBe('PREVIEW');
    expect(DB::table('data_cleanup_tombstones')->count())->toBe(0);
});

it('rolls every deletion and balance change back on a database failure after backup', function () {
    $order = cleanupTopup($this, '9.876543');
    $preview = cleanupPreview($this);
    DB::unprepared("CREATE OR REPLACE FUNCTION cleanup_test_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'isolated cleanup failure'; END $$; CREATE TRIGGER cleanup_test_fail BEFORE DELETE ON user_profiles FOR EACH ROW EXECUTE FUNCTION cleanup_test_fail();");
    try {
        expect(fn () => $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), true))->toThrow(QueryException::class, 'isolated cleanup failure');
        expect(User::find($this->user->id))->not->toBeNull()->and(DB::table('wallet_topup_orders')->where('id', $order)->exists())->toBeTrue();
        expect(DB::table('ledger_accounts')->where('user_id', $this->user->id)->where('account_type', 'USER_AVAILABLE')->value('balance'))->toBe('9.876543000000000000');
        expect(DB::table('data_cleanup_batches')->where('id', $preview['id'])->value('status'))->toBe('PREVIEW');
        expect(DB::table('data_cleanup_tombstones')->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER cleanup_test_fail ON user_profiles; DROP FUNCTION cleanup_test_fail();');
    }
});

it('expires previews and only exposes safe immutable records to their actor', function () {
    $preview = cleanupPreview($this);
    $this->actingAs($this->actor, 'platform_admin')->getJson($this->url.'/'.$preview['id'].'/records')->assertOk()->assertJsonMissing(['password_hash' => $this->user->password_hash]);
    $this->travel(11)->minutes();
    expect(fn () => $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), true))->toThrow(DomainException::class, 'expired');
    expect(User::find($this->user->id))->not->toBeNull();
});

it('cleans commissions generated for the source account ancestors while retaining deposit funding', function () {
    $parent = cleanupSecondUser($this);
    $member = DB::table('promotion_members')->where('user_id', $this->user->id)->sole();
    $parentMember = DB::table('promotion_members')->where('user_id', $parent->id)->sole();
    app(ChangeReferrer::class)->execute($this->tenant->id, $this->user->id, $this->actor,
        ['old_inviter_id' => $member->inviter_id, 'new_inviter_id' => $parentMember->id, 'revision' => $member->referrer_revision, 'request_id' => (string) Str::uuid(), 'reason' => 'isolated fixture']);
    cleanupTopup($this, '100');
    $required = $this->tenant->businessSettings->required_security_deposit_amount;
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $this->user->id, (string) Str::uuid(), $required);
    $event = DB::table('promotion_funding_events')->where('user_id', $this->user->id)->sole();
    expect(DB::table('paid_promotion_shares')->where('user_id', $parent->id)->exists())->toBeTrue();
    $preview = cleanupPreview($this, ['commissions']);
    expect($preview['summary']['blockers'])->toBe([]);
    $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), true);
    expect(DB::table('promotion_funding_events')->where('id', $event->id)->exists())->toBeTrue();
    expect(DB::table('paid_promotion_shares')->where('user_id', $parent->id)->exists())->toBeFalse();
    expect(User::find($parent->id))->not->toBeNull();
    DB::transaction(fn () => app(PaidPromotionRewards::class)->execute($this->tenant->id, $this->user->id, 'ACTIVATION', $event->id, $event->amount));
    expect(DB::table('paid_promotion_shares')->where('user_id', $parent->id)->exists())->toBeFalse();
    expect((float) app(PromotionReportQuery::class)->cumulative($this->tenant->id, $parent->id))->toBe(0.0);
});

it('revokes native credentials and drops cleaned queued resources without touching other tasks', function () {
    $token = app(IssueConsumerDeviceToken::class)->execute($this->tenant->id, $this->user->id, 'secret123', 'OFFLINE-TEST');
    $order = cleanupTopup($this);
    config(['queue.default' => 'database']);
    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    $queue = app('queue')->connection('database');
    $queue->push(new CreditWalletTopupJob($this->tenant->id, $order));
    $otherOrder = (string) Str::uuid();
    $queue->push(new CreditWalletTopupJob($this->tenant->id, $otherOrder));
    $preview = cleanupPreview($this);
    $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), true);
    app(RemoveCleanedPendingJobs::class)->run();
    expect(DB::table('jobs')->count())->toBe(1);
    expect(DB::table('jobs')->value('payload'))->toContain($otherOrder)->not->toContain($order);
    expect(DB::table('consumer_device_tokens')->where('tokenable_id', $this->user->id)->exists())->toBeFalse();
    $this->withToken($token['token'])->getJson('http://a.localhost/api/mobile/v1/account')->assertUnauthorized();
});

it('retains exact ETH and BTC precision when recalculating company accounts', function () {
    foreach (['ETH' => '0.123456789123456789', 'BTC' => '0.12345678'] as $asset => $amount) {
        DB::transaction(function () use ($asset, $amount) {
            $access = app(AssetAccess::class);
            $wallet = $access->wallet($this->tenant, $this->user, $asset);
            $available = $access->account($wallet, 'USER_AVAILABLE');
            $clearing = $access->companyAccount($this->tenant->id, $asset, 'TENANT_TOPUP_CLEARING');
            app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, $asset, 'cleanup-test:'.Str::uuid(), 'TEST_TOPUP', null, null, null, [
                new LedgerPostingInstruction($clearing->id, Money::of('-'.$amount, $asset)),
                new LedgerPostingInstruction($available->id, Money::of($amount, $asset)),
            ]));
        });
    }
    $preview = cleanupPreview($this);
    expect($preview['summary']['blockers'])->toBe([]);
    expect(collect($preview['summary']['balances'])->first(fn ($r) => $r['asset'] === 'ETH' && $r['accountType'] === 'USER_AVAILABLE')['before'])->toBe('0.123456789123456789');
    $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), true);
    expect(DB::table('ledger_accounts')->where('tenant_id', $this->tenant->id)->whereIn('asset_code', ['ETH', 'BTC'])->where('balance', '<>', 0)->count())->toBe(0);
});

it('rejects cleanup while another database session owns a company operation', function () {
    $preview = cleanupPreview($this);
    $config = DB::connection()->getConfig();
    $pdo = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname=card_ui_test', $config['username'], $config['password']);
    $lock = $pdo->prepare('SELECT pg_advisory_lock_shared(hashtextextended(?,0))');
    $lock->execute(['cleanup-company:'.$this->tenant->id]);
    try {
        expect(fn () => $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), true))->toThrow(DomainException::class, 'in progress');
        expect(User::find($this->user->id))->not->toBeNull();
        expect(Storage::disk('private')->files('data-cleanup'))->toBe([]);
    } finally {
        $unlock = $pdo->prepare('SELECT pg_advisory_unlock_shared(hashtextextended(?,0))');
        $unlock->execute(['cleanup-company:'.$this->tenant->id]);
    }
    $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), true);
    expect(User::find($this->user->id))->toBeNull();
});

it('retains backups for seven days and then removes only cleanup backup files', function () {
    Storage::disk('private')->put('other-private-object', 'retained');
    $preview = cleanupPreview($this);
    $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), true);
    $path = DB::table('data_cleanup_batches')->where('id', $preview['id'])->value('backup_path');
    $this->artisan('data-cleanup:expire-backups')->assertSuccessful();
    expect(Storage::disk('private')->exists($path))->toBeTrue();
    $this->travel(8)->days();
    $this->artisan('data-cleanup:expire-backups')->assertSuccessful();
    expect(Storage::disk('private')->exists($path))->toBeFalse()->and(Storage::disk('private')->exists('other-private-object'))->toBeTrue();
});

it('cannot reuse a cleaned account number or change its retained manifest', function () {
    $account = $this->user->account_id;
    $preview = cleanupPreview($this);
    $this->cleanup->execute($this->tenant->id, $preview['id'], $this->actor, (string) Str::uuid(), true);
    expect(fn () => DB::transaction(function () use ($account) {
        $replacement = new User;
        $replacement->forceFill(['tenant_id' => $this->tenant->id, 'email' => 'replacement@example.test', 'password_hash' => 'secret123', 'status' => 'ACTIVE', 'account_id' => $account])->save();
    }))->toThrow(QueryException::class, 'server assigned');
    $replacement = cleanupSecondUser($this);
    expect($replacement->fresh()->account_id)->not->toBe($account);
    expect(fn () => DB::transaction(fn () => DB::table('data_cleanup_items')->where('batch_id', $preview['id'])->delete()))->toThrow(QueryException::class, 'immutable');
    expect(fn () => DB::transaction(fn () => DB::table('data_cleanup_batches')->where('id', $preview['id'])->update(['status' => 'PREVIEW'])))->toThrow(QueryException::class, 'transition');
    expect(DB::table('data_cleanup_batches')->where('id', $preview['id'])->value('status'))->toBe('COMPLETED');
});
