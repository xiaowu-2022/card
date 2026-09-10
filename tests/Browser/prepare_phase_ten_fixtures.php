<?php

declare(strict_types=1);

use App\Application\Card\CreateCardIssueAction;
use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\Enums\MockProviderMode;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Providers\Card\MockCardProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment(['local', 'testing'])) {
    fwrite(STDERR, "Phase 10 browser fixtures are forbidden outside local/testing.\n");
    exit(2);
}
$state = $argv[1] ?? 'setup';
if (! in_array($state, ['setup', 'pending', 'ready', 'insufficient', 'processing', 'success'], true)) {
    fwrite(STDERR, "Unknown Phase 10 browser fixture.\n");
    exit(2);
}

Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
$tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
$user = User::query()->where('tenant_id', $tenant->id)->firstOrFail();
$owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
$product = CardProduct::query()->where('provider', 'PHOTONPAY')->firstOrFail();
DB::transaction(function () use ($tenant, $owner): void {
    DB::table('tenants')->where('id', $tenant->id)->update(['default_asset' => 'USDT']);
    $tenant->refresh();
    app(UpdateTenantBusinessSettingsAction::class)->execute($tenant, [
        'required_security_deposit_amount' => '10', 'required_security_deposit_asset' => 'USDT',
        'allow_wallet_topup' => true, 'allow_withdrawal' => true,
    ], $owner);
});
$tenant->refresh();
$imagePath = tempnam(sys_get_temp_dir(), 'phase-ten-browser-');
file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
$kyc = app(SubmitKycApplicationAction::class)->execute(
    $tenant, $user, 'MY', 'PHASE-TEN-BROWSER',
    new UploadedFile($imagePath, 'front.png', 'image/png', null, true),
    new UploadedFile($imagePath, 'back.png', 'image/png', null, true),
);
app(ApproveKycAction::class)->execute($tenant->id, $kyc->id, $owner);
@unlink($imagePath);
$wallet = app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id)->wallet;
$available = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserAvailable)->firstOrFail();
$clearing = LedgerAccount::query()->where('tenant_id', $tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing)->firstOrFail();
$targetAvailable = $state === 'insufficient' ? '18.00000000' : '100.00000000';
$credit = Money::of($targetAvailable, 'USDT')->add(Money::of('10', 'USDT'));
app(LedgerWriter::class)->post(new LedgerPostingPlan(
    $tenant->id, 'USDT', 'phase-ten:browser-credit', 'TEST_WALLET_CREDIT', null, null, null,
    [new LedgerPostingInstruction($clearing->id, Money::of('-'.$credit->amount(), 'USDT')), new LedgerPostingInstruction($available->id, $credit)],
));
app(FundSecurityDepositAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), '10');

$holder = null;
if ($state !== 'setup') {
    $holder = new ProviderCardholder;
    $holder->forceFill([
        'tenant_id' => $tenant->id, 'user_id' => $user->id, 'provider' => 'PHOTONPAY',
        'provider_cardholder_id' => 'MOCK-HOLDER-BROWSER', 'status' => $state === 'pending' ? 'PENDING' : 'READY',
        'provider_status' => $state === 'pending' ? 'pending' : 'normal',
        'provider_review_status' => $state === 'pending' ? 'pending' : 'approved',
        'submitted_at' => now(), 'synced_at' => now(),
    ])->save();
}
$order = null;
if (in_array($state, ['processing', 'success'], true)) {
    app()->instance(CardProviderInterface::class, new MockCardProvider($state === 'processing' ? MockProviderMode::Unknown : MockProviderMode::Success, 'READY'));
    $order = app(CreateCardIssueAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), $product->id, '20.00');
}

echo json_encode(['state' => $state, 'orderId' => $order?->id], JSON_THROW_ON_ERROR).PHP_EOL;
