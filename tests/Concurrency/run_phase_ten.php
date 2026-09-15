<?php

declare(strict_types=1);

use App\Application\Card\CreateCardIssueAction;
use App\Application\Card\RecordCardTransactionsAction;
use App\Application\Card\SubmitProviderCardholderAction;
use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Models\UserCard;
use App\Domain\Card\Services\CardholderMaterials;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\DTOs\ProviderCardTransactionDTO;
use App\Domain\CardProvider\Enums\MockProviderMode;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerReconciliationService;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Providers\Card\MockCardProvider;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! extension_loaded('pcntl')) {
    fwrite(STDERR, "pcntl is required for Phase 10 concurrency verification.\n");
    exit(2);
}
if (! in_array(config('database.connections.pgsql.database'), ['card_ui_test', 'card_concurrency_test', 'card_ui_concurrency_test'], true)) {
    fwrite(STDERR, "Concurrency fixtures require a dedicated test database.\n");
    exit(2);
}

// Only the dedicated test database may run the offline Mock fixture provider.
$app->detectEnvironment(fn (): string => 'testing');
config()->set('card-provider.driver', 'mock');

DB::unprepared('DROP FUNCTION IF EXISTS protect_user_account_id() CASCADE;
    DROP FUNCTION IF EXISTS assign_user_account_id() CASCADE;
    DROP FUNCTION IF EXISTS allocate_user_account_id(uuid, timestamptz) CASCADE;');
Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
$tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
$user = User::query()->where('tenant_id', $tenant->id)->firstOrFail();
$owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
$product = CardProduct::query()->where('provider', 'PHOTONPAY')->firstOrFail();
DB::transaction(function () use ($tenant): void {
    DB::table('tenants')->where('id', $tenant->id)->update(['default_asset' => 'USDT']);
    $tenant->refresh();
    app(UpdateTenantBusinessSettingsAction::class)->execute($tenant, [
        'required_security_deposit_amount' => '10', 'required_security_deposit_asset' => 'USDT',
        'allow_wallet_topup' => true, 'allow_withdrawal' => true,
    ], AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());
});
$tenant->refresh();
$imagePath = tempnam(sys_get_temp_dir(), 'phase-ten-concurrency-');
file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
$kyc = app(SubmitKycApplicationAction::class)->execute(
    $tenant, $user, 'MY', 'PHASE-TEN-CONCURRENT',
    new UploadedFile($imagePath, 'front.png', 'image/png', null, true),
    new UploadedFile($imagePath, 'back.png', 'image/png', null, true),
);
app(ApproveKycAction::class)->execute($tenant->id, $kyc->id, $owner);
$wallet = app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id)->wallet;
$available = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserAvailable)->firstOrFail();
$clearing = LedgerAccount::query()->where('tenant_id', $tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing)->firstOrFail();
app(LedgerWriter::class)->post(new LedgerPostingPlan(
    $tenant->id, 'USDT', 'phase-ten:concurrency-credit', 'TEST_WALLET_CREDIT', null, null, null,
    [new LedgerPostingInstruction($clearing->id, Money::of('-60', 'USDT')), new LedgerPostingInstruction($available->id, Money::of('60', 'USDT'))],
));
app(FundSecurityDepositAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), '10');
$counterPath = tempnam(sys_get_temp_dir(), 'cardholder-calls-');
$mockProvider = new MockCardProvider(MockProviderMode::Success, 'READY');
$provider = Mockery::mock(CardProviderInterface::class);
$provider->shouldReceive('available')->andReturn(true);
$provider->shouldReceive('name')->andReturn('PHOTONPAY');
$provider->shouldReceive('createCardholder')->andReturnUsing(function ($request) use ($counterPath, $mockProvider) {
    if (DB::transactionLevel() !== 0) {
        throw new RuntimeException('Provider create called inside a database transaction.');
    }
    file_put_contents($counterPath, "add\n", FILE_APPEND | LOCK_EX);

    return $mockProvider->createCardholder($request);
});
$provider->shouldReceive('issueCard')->andReturnUsing(function ($request) use ($mockProvider) {
    if (DB::transactionLevel() !== 0) {
        throw new RuntimeException('Provider issue called inside a database transaction.');
    }

    return $mockProvider->issueCard($request);
});
app()->instance(CardProviderInterface::class, $provider);

/** @param list<Closure():void> $tasks @return list<int> */
$parallel = function (array $tasks): array {
    $children = [];
    foreach ($tasks as $task) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::disconnect();
            try {
                DB::reconnect();
                $task();
                exit(0);
            } catch (DomainException $exception) {
                if (in_array($exception->errorCode, ['INSUFFICIENT_AVAILABLE_BALANCE', 'LEDGER_NEGATIVE_BALANCE', 'CARD_APPLICATION_USED'], true)) {
                    exit(0);
                }
                fwrite(STDERR, "Unexpected Card issue rejection: {$exception->errorCode}\n");
                exit(1);
            } catch (Throwable $exception) {
                fwrite(STDERR, 'Unexpected Card issue failure: '.$exception::class."\n");
                exit(1);
            }
        }
        $children[] = $pid;
    }
    $statuses = [];
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $statuses[] = pcntl_wexitstatus($status);
    }
    DB::disconnect();
    DB::reconnect();

    return $statuses;
};

$materialData = [
    'request_id' => (string) Str::uuid(), 'card_product_id' => $product->id,
    'legal_first_name' => 'Independent', 'legal_last_name' => 'Holder', 'date_of_birth' => '1990-01-02',
    'email' => 'holder@example.test', 'nationality_country_code' => 'MY',
    'residential_address' => '1 Test Street', 'residential_city' => 'Kuala Lumpur', 'residential_state' => 'Kuala Lumpur',
    'residential_country_code' => 'MY', 'residential_postal_code' => '50000',
    'document_type' => 'id_card', 'document_country' => 'MY', 'identity_number' => 'TEST-PER-CARD',
    'front' => new UploadedFile($imagePath, 'front.png', 'image/png', null, true),
    'back' => new UploadedFile($imagePath, 'back.png', 'image/png', null, true),
];
$submissions = $parallel([
    fn () => app(SubmitProviderCardholderAction::class)->execute($tenant->id, $user->id, $materialData),
    fn () => app(SubmitProviderCardholderAction::class)->execute($tenant->id, $user->id, $materialData),
]);
$holder = ProviderCardholder::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('request_id', $materialData['request_id'])->firstOrFail();
$addCalls = substr_count(file_get_contents($counterPath), "add\n");
$sameRequest = (string) Str::uuid();
$same = $parallel([
    fn () => app(CreateCardIssueAction::class)->execute($tenant->id, $user->id, $sameRequest, $product->id, '20.00', $holder->id),
    fn () => app(CreateCardIssueAction::class)->execute($tenant->id, $user->id, $sameRequest, $product->id, '20.00', $holder->id),
]);
$reuse = $parallel([
    fn () => app(CreateCardIssueAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), $product->id, '20.00', $holder->id),
    fn () => app(CreateCardIssueAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), $product->id, '20.00', $holder->id),
]);
// Independent READY fixtures exercise two distinct application IDs competing for the remaining balance.
$extra = [];
foreach (['SECOND', 'THIRD'] as $suffix) {
    $next = new ProviderCardholder;
    $next->forceFill([
        'tenant_id' => $tenant->id, 'user_id' => $user->id, 'provider' => 'PHOTONPAY',
        'provider_cardholder_id' => 'MOCK-HOLDER-'.$suffix, 'status' => 'READY',
        'card_product_id' => $product->id, 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64),
        'submission_version' => 1, 'materials_encrypted' => app(CardholderMaterials::class)->encrypt('TEST-'.$suffix),
    ])->save();
    $extra[] = $next->id;
}
$competing = $parallel([
    fn () => app(CreateCardIssueAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), $product->id, '20.00', $extra[0]),
    fn () => app(CreateCardIssueAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), $product->id, '20.00', $extra[1]),
]);

$valid = $submissions === [0, 0] && $addCalls === 1 && $reuse === [0, 0] && $same === [0, 0]
    && $competing === [0, 0]
    && CardIssueOrder::query()->count() === 2
    && UserCard::query()->count() === 2
    && LedgerEntry::query()->where('event_type', 'CARD_ISSUE_FEE_HOLD')->count() === 2
    && LedgerEntry::query()->where('event_type', 'CARD_INITIAL_LOAD_HOLD')->count() === 2
    && LedgerEntry::query()->where('event_type', 'CARD_ISSUE_FEE_SETTLE')->count() === 2
    && LedgerEntry::query()->where('event_type', 'CARD_INITIAL_LOAD_SETTLE')->count() === 2
    && $available->fresh()->balance === '0.00000000'
    && app(LedgerReconciliationService::class)->mismatches() === [];

// Concurrent read-model ingestion must preserve one first-recorded row and all money.
$recordCard = UserCard::query()->firstOrFail();
$recordStartedAt = CarbonImmutable::now();
$moneyBefore = DB::table('ledger_accounts')->orderBy('id')->get()->toJson();
$recordTasks = array_fill(0, 2, function () use ($recordCard, $recordStartedAt): void {
    app(RecordCardTransactionsAction::class)->execute($recordCard, [
        new ProviderCardTransactionDTO('CONCURRENT-READ-MODEL', '1.23000000', 'USD', 'purchase', 'completed', '2020-01-01T00:00:00', null),
    ], $recordStartedAt);
});
$recordChildren = $parallel($recordTasks);
$recordRows = DB::table('card_transactions')->where('card_id', $recordCard->id)->where('provider_transaction_id', 'CONCURRENT-READ-MODEL')->get();
$firstRecorded = $recordRows->first()->created_at;
$recordTasks[0]();
$recordValid = $recordChildren === [0, 0] && $recordRows->count() === 1
    && DB::table('card_transactions')->where('id', $recordRows->first()->id)->value('created_at') === $firstRecorded
    && DB::table('ledger_accounts')->orderBy('id')->get()->toJson() === $moneyBefore;
$valid = $valid && $recordValid;

echo json_encode([
    'transaction_sync_children' => $recordChildren,
    'transaction_recording_valid' => $recordValid,
    'material_submission_children' => $submissions,
    'add_cardholder_calls' => $addCalls,
    'used_material_rejection_children' => $reuse,
    'same_request_children' => $same,
    'competing_balance_children' => $competing,
    'orders' => CardIssueOrder::query()->count(),
    'cards' => UserCard::query()->count(),
    'available' => $available->fresh()->balance,
    'ledger_mismatches' => count(app(LedgerReconciliationService::class)->mismatches()),
], JSON_PRETTY_PRINT).PHP_EOL;
@unlink($imagePath);
@unlink($counterPath);

exit($valid ? 0 : 1);
