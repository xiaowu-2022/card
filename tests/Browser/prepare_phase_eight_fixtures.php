<?php

declare(strict_types=1);

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Payment\CreateTrc20WalletTopupAction;
use App\Application\Payment\ExpireTrc20TopupsAction;
use App\Application\Payment\ProcessIncomingTrc20TransferAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Withdrawal\DTOs\IncomingBlockchainTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment(['local', 'testing'])) {
    fwrite(STDERR, "Phase 8 browser fixtures are forbidden outside local/testing.\n");
    exit(2);
}

config([
    'payment.trc20_deposit_address' => 'T111111111111111111111111111111111',
    'payment.trc20_token_contract' => 'T222222222222222222222222222222222',
    'payment.trc20_required_confirmations' => 20,
]);
Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
$tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
$user = User::query()->where('tenant_id', $tenant->id)->firstOrFail();
$owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
DB::transaction(function () use ($tenant): void {
    $tenant->update(['default_asset' => 'USDT']);
    app(UpdateTenantBusinessSettingsAction::class)->execute($tenant, [
        'required_security_deposit_amount' => '0', 'required_security_deposit_asset' => 'USDT',
        'allow_wallet_topup' => true, 'allow_withdrawal' => true,
    ], AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());
});
$image = tempnam(sys_get_temp_dir(), 'phase-eight-browser-');
file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
$application = app(SubmitKycApplicationAction::class)->execute(
    $tenant, $user, 'MY', 'PHASE-EIGHT-BROWSER',
    new UploadedFile($image, 'front.png', 'image/png', null, true),
    new UploadedFile($image, 'back.png', 'image/png', null, true),
);
app(ApproveKycAction::class)->execute($tenant->id, $application->id, $owner);
@unlink($image);
app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id);
$create = fn (string $amount) => app(CreateTrc20WalletTopupAction::class)
    ->execute($tenant->id, $user->id, $amount, (string) Str::uuid())->order;

$waiting = $create('100');
$confirming = $create('125');
$pendingTransfer = new IncomingBlockchainTransfer(
    'TRON', hash('sha256', 'phase-eight-browser-confirming'), 0, $confirming->token_contract,
    $confirming->deposit_address, $confirming->expected_amount, 2, new DateTimeImmutable,
);
app(ProcessIncomingTrc20TransferAction::class)->execute($pendingTransfer);
$success = $create('150');
app(ProcessIncomingTrc20TransferAction::class)->execute(new IncomingBlockchainTransfer(
    'TRON', hash('sha256', 'phase-eight-browser-success'), 0, $success->token_contract,
    $success->deposit_address, $success->expected_amount, 20, new DateTimeImmutable,
));
CarbonImmutable::setTestNow(now()->subMinutes(31));
$expired = $create('175');
CarbonImmutable::setTestNow();
app(ExpireTrc20TopupsAction::class)->execute();

echo json_encode([
    'waitingOrderId' => $waiting->id,
    'confirmingOrderId' => $confirming->id,
    'successOrderId' => $success->id,
    'expiredOrderId' => $expired->id,
], JSON_THROW_ON_ERROR);
