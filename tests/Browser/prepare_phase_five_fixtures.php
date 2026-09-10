<?php

declare(strict_types=1);

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Payment\CreateWalletTopupAction;
use App\Application\Payment\CreditWalletTopupAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Payment\Contracts\PaymentProviderInterface;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment(['local', 'testing'])) {
    fwrite(STDERR, "Phase 5 browser fixtures are forbidden outside local/testing.\n");
    exit(2);
}

$tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
$user = User::query()->where('tenant_id', $tenant->id)->firstOrFail();
$owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
$image = tempnam(sys_get_temp_dir(), 'phase51-browser-');
file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
$application = app(SubmitKycApplicationAction::class)->execute(
    $tenant, $user, 'MY', 'PHASE51-BROWSER',
    new UploadedFile($image, 'front.png', 'image/png', null, true),
    new UploadedFile($image, 'back.png', 'image/png', null, true),
);
app(ApproveKycAction::class)->execute($tenant->id, $application->id, $owner);
@unlink($image);
$wallet = app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id)->wallet;
$create = fn (string $amount) => app(CreateWalletTopupAction::class)->execute(
    $tenant->id, $user->id, $wallet->id, $amount, 'USD', (string) Str::uuid(),
    'http://a.localhost:8000/wallet/top-ups/__ORDER__/return',
)->order;

config(['payment.mock_mode' => 'PENDING']);
$app->forgetInstance(PaymentProviderInterface::class);
$processing = $create('10.00000000');
$credited = $create('25.00000000');
DB::table('wallet_topup_orders')->where('id', $credited->id)->update([
    'status' => WalletTopupStatus::Paid->value, 'paid_at' => now(), 'updated_at' => now(),
]);
app(CreditWalletTopupAction::class)->execute($tenant->id, $credited->id);

config(['payment.mock_mode' => 'FAILED']);
$app->forgetInstance(PaymentProviderInterface::class);
$failed = $create('15.00000000');

echo json_encode([
    'processing' => $processing->id,
    'credited' => $credited->id,
    'failed' => $failed->id,
], JSON_THROW_ON_ERROR);
