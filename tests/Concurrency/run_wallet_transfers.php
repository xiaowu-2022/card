<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Application\Wallet\TransferWalletBalanceAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\WalletTransfer;
use App\Support\Errors\DomainException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'card_ui_test' || ! extension_loaded('pcntl')) {
    throw new RuntimeException('Requires isolated card_ui_test, testing environment and pcntl.');
}
Queue::fake();
Http::preventStrayRequests();
config(['kyc.data_encryption_key' => str_repeat('d', 32), 'kyc.identity_hash_key' => str_repeat('t', 32), 'kyc.document_disk' => 'transfer_concurrency']);
Storage::fake('transfer_concurrency');
Artisan::call('db:seed', ['--force' => true]);
$tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
$admin = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
$users = [];
$accounts = [];
$image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
foreach (range(0, 2) as $i) {
    $user = User::query()->create(['tenant_id' => $tenant->id, 'email' => Str::uuid().'@transfer-race.test', 'password_hash' => 'Non-login fixture', 'status' => 'ACTIVE'])->refresh();
    $application = app(SubmitKycApplicationAction::class)->execute($tenant, $user, 'MY', 'RACE-'.$user->id,
        UploadedFile::fake()->createWithContent('front.png', $image), UploadedFile::fake()->createWithContent('back.png', $image));
    app(ApproveKycAction::class)->execute($tenant->id, $application->id, $admin);
    app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id);
    $users[] = $user;
    $accounts[] = LedgerAccount::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
}
$clearing = LedgerAccount::query()->where('tenant_id', $tenant->id)->where('asset_code', 'USD')->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
app(LedgerWriter::class)->post(new LedgerPostingPlan($tenant->id, 'USD', 'transfer_concurrency:'.Str::uuid(), 'TEST_TOPUP', null, null, null, [
    new LedgerPostingInstruction($clearing->id, Money::of('-100', 'USD')),
    new LedgerPostingInstruction($accounts[0]->id, Money::of('100', 'USD')),
]));
function raceTransfers(array $jobs): array
{
    DB::disconnect();
    $pids = [];
    foreach ($jobs as $job) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork');
        }
        if ($pid === 0) {
            DB::reconnect();
            try {
                DB::transaction(function () use ($job): void {
                    app(TransferWalletBalanceAction::class)->execute(...$job);
                    DB::select('SELECT pg_sleep(0.2)');
                });
                exit(0);
            } catch (DomainException $e) {
                exit($e->errorCode === 'WALLET_TRANSFER_INSUFFICIENT' ? 10 : 20);
            } catch (Throwable $e) {
                fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
                exit(30);
            }
        }
        $pids[] = $pid;
    }
    $statuses = [];
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $statuses[] = pcntl_wexitstatus($status);
    }
    DB::reconnect();
    sort($statuses);

    return $statuses;
}
$statuses = raceTransfers([
    [$tenant->id, $users[0]->id, $users[1]->account_id, '70', (string) Str::uuid()],
    [$tenant->id, $users[0]->id, $users[2]->account_id, '70', (string) Str::uuid()],
]);
if ($statuses !== [0, 10] || $accounts[0]->fresh()->balance !== '30.00000000') {
    throw new RuntimeException('Concurrent overspend protection failed.');
}
$request = (string) Str::uuid();
$job = [$tenant->id, $users[0]->id, $users[1]->account_id, '10', $request];
if (raceTransfers([$job, $job]) !== [0, 0] || $accounts[0]->fresh()->balance !== '20.00000000'
    || WalletTransfer::query()->where('tenant_id', $tenant->id)->where('sender_user_id', $users[0]->id)->where('request_id', $request)->count() !== 1) {
    throw new RuntimeException('Concurrent replay protection failed.');
}
if (raceTransfers([
    [$tenant->id, $users[0]->id, $users[1]->account_id, '1', (string) Str::uuid()],
    [$tenant->id, $users[1]->id, $users[0]->account_id, '1', (string) Str::uuid()],
]) !== [0, 0] || $accounts[0]->fresh()->balance !== '20.00000000') {
    throw new RuntimeException('Opposing transfers failed.');
}
echo "PASS: concurrent overspend rejected; duplicate request posts once; opposing transfers complete without deadlock. Isolated test receipts retained.\n";
