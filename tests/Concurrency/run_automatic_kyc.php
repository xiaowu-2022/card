<?php

use App\Application\Kyc\SubmitKycApplicationAction;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'card_ui_test' || ! extension_loaded('pcntl')) {
    throw new RuntimeException('Requires isolated card_ui_test, testing environment and pcntl.');
}
Artisan::call('db:seed', ['--force' => true]);
$tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
PlatformKycSetting::current()->update(['enabled' => true, 'review_mode' => 'AUTOMATIC', 'max_accounts_per_identity' => 1]);
config(['kyc.document_disk' => 'automatic_kyc_concurrency']);
Storage::fake('automatic_kyc_concurrency');
$identity = 'CONCURRENT-'.Str::uuid();
$users = collect(range(1, 2))->map(fn () => User::query()->create([
    'tenant_id' => $tenant->id, 'email' => Str::uuid().'@example.test', 'password_hash' => 'unused', 'status' => 'ACTIVE',
]));
$image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
DB::disconnect();
$children = [];
foreach ($users as $user) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Unable to fork');
    }
    if ($pid === 0) {
        try {
            DB::reconnect();
            app(SubmitKycApplicationAction::class)->execute($tenant, $user, 'MY', $identity,
                UploadedFile::fake()->createWithContent('front.png', $image),
                UploadedFile::fake()->createWithContent('back.png', $image));
            exit(0);
        } catch (DomainException $exception) {
            exit($exception->errorCode === 'IDENTITY_ACCOUNT_LIMIT_REACHED' ? 10 : 20);
        } catch (Throwable) {
            exit(30);
        }
    }
    $children[] = $pid;
}
$statuses = [];
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
    $statuses[] = pcntl_wexitstatus($status);
}
DB::reconnect();
sort($statuses);
$count = IdentityRecord::query()->where('tenant_id', $tenant->id)->whereIn('user_id', $users->pluck('id'))->count();
if ($statuses !== [0, 10] || $count !== 1) {
    throw new RuntimeException('Concurrent identity limit verification failed');
}
echo "PASS: concurrent automatic submissions approve exactly one account at limit one.\n";
