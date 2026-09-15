<?php

declare(strict_types=1);

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment(['local', 'testing'])) {
    fwrite(STDERR, "Phase 6 browser fixtures are forbidden outside local/testing.\n");
    exit(2);
}

Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);

$prepare = function (string $slug, string $ownerEmail, string $identity, string $available): void {
    $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
    $user = User::query()->where('tenant_id', $tenant->id)->firstOrFail();
    $owner = AdminUser::query()->where('email', $ownerEmail)->firstOrFail();
    app(UpdateTenantBusinessSettingsAction::class)->execute($tenant, [
        'required_security_deposit_amount' => '50', 'required_security_deposit_asset' => 'USD', 'allow_wallet_topup' => true, 'allow_withdrawal' => false,
    ], AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());
    $image = tempnam(sys_get_temp_dir(), 'phase6-browser-');
    file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
    $application = app(SubmitKycApplicationAction::class)->execute(
        $tenant, $user, 'MY', $identity,
        new UploadedFile($image, 'front.png', 'image/png', null, true),
        new UploadedFile($image, 'back.png', 'image/png', null, true),
    );
    app(ApproveKycAction::class)->execute($tenant->id, $application->id, $owner);
    @unlink($image);
    $wallet = app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id)->wallet;
    $availableAccount = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserAvailable->value)->firstOrFail();
    $clearing = LedgerAccount::query()->where('tenant_id', $tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing->value)->firstOrFail();
    app(LedgerWriter::class)->post(new LedgerPostingPlan(
        $tenant->id, 'USD', "phase-six-browser:{$wallet->id}:credit", 'TEST_WALLET_CREDIT', null, null, null,
        [new LedgerPostingInstruction($clearing->id, Money::of('-'.$available, 'USD')), new LedgerPostingInstruction($availableAccount->id, Money::of($available, 'USD'))],
    ));
};

$prepare('tenant-a', 'owner@a.localhost', 'PHASE6-BROWSER-A', '100.00000000');
$prepare('tenant-b', 'owner@b.localhost', 'PHASE6-BROWSER-B', '20.00000000');
echo json_encode(['ready' => true], JSON_THROW_ON_ERROR);
