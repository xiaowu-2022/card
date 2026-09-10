<?php

declare(strict_types=1);

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Application\Withdrawal\ApproveWithdrawalAction;
use App\Application\Withdrawal\CreateWithdrawalAction;
use App\Application\Withdrawal\CreateWithdrawalDestinationAction;
use App\Application\Withdrawal\VerifyWithdrawalTransactionAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment(['local', 'testing'])) {
    fwrite(STDERR, "Phase 7 browser fixtures are forbidden outside local/testing.\n");
    exit(2);
}

Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
$tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
$user = User::query()->where('tenant_id', $tenant->id)->firstOrFail();
$owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
DB::transaction(function () use ($tenant, $owner): void {
    $tenant->update(['default_asset' => 'USDT']);
    app(UpdateTenantBusinessSettingsAction::class)->execute($tenant, [
        'required_security_deposit_amount' => '0', 'required_security_deposit_asset' => 'USDT',
        'allow_wallet_topup' => true, 'allow_withdrawal' => true,
    ], $owner);
});
$image = tempnam(sys_get_temp_dir(), 'phase-seven-browser-');
file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
$application = app(SubmitKycApplicationAction::class)->execute(
    $tenant, $user, 'MY', 'PHASE-SEVEN-BROWSER',
    new UploadedFile($image, 'front.png', 'image/png', null, true),
    new UploadedFile($image, 'back.png', 'image/png', null, true),
);
app(ApproveKycAction::class)->execute($tenant->id, $application->id, $owner);
@unlink($image);
$wallet = app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id)->wallet;
$address = 'T'.str_repeat('A', 33);
$destination = app(CreateWithdrawalDestinationAction::class)->execute($tenant->id, $user->id, $address, 'Primary wallet');
$available = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserAvailable->value)->firstOrFail();
$clearing = LedgerAccount::query()->where('tenant_id', $tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing->value)->firstOrFail();
app(LedgerWriter::class)->post(new LedgerPostingPlan(
    $tenant->id, 'USDT', 'phase-seven-browser:credit', 'TEST_WALLET_CREDIT', null, null, null,
    [new LedgerPostingInstruction($clearing->id, Money::of('-300', 'USDT')), new LedgerPostingInstruction($available->id, Money::of('300', 'USDT'))],
));
$pending = app(CreateWithdrawalAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), $destination->id, '40');
$verifying = app(CreateWithdrawalAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), $destination->id, '50');
app(ApproveWithdrawalAction::class)->execute($tenant->id, $verifying->id, $owner);
config(['withdrawal.mock_verification_mode' => 'PENDING']);
app()->forgetInstance(BlockchainGatewayInterface::class);
app(VerifyWithdrawalTransactionAction::class)->execute($tenant->id, $verifying->id, $owner, str_repeat('d', 64));

echo json_encode([
    'pendingOrderId' => $pending->id,
    'verifyingOrderId' => $verifying->id,
    'fullAddress' => $address,
], JSON_THROW_ON_ERROR);
