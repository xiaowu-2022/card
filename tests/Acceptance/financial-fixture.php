<?php

require __DIR__.'/bootstrap.php';

use App\Application\Assets\DepositAssetsAction;
use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Tenant\UpdateTenantLocalesAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Application\Wealth\WealthConfiguration;
use App\Application\Wealth\WealthService;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetRail;
use App\Domain\Assets\ChainConnection;
use App\Domain\Assets\CompanyRail;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerReconciliationService;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wealth\WealthOrder;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$mode = $argv[1] ?? 'snapshot';
if (! in_array($mode, ['setup', 'snapshot', 'reconcile', 'locales'], true)) {
    throw new RuntimeException('Invalid mode');
}
if ($mode === 'setup') {
    Artisan::call('db:seed', ['--force' => true]);
}
$tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
$user = User::where('tenant_id', $tenant->id)->where('email', 'user@a.localhost')->firstOrFail();
$platform = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
if (in_array($mode, ['setup', 'locales'], true)) {
    app(UpdateTenantLocalesAction::class)->execute($tenant, ['zh-CN', 'en', 'ms', 'es'], 'en', $platform);
}

if ($mode === 'setup') {
    if (WealthOrder::where('request_id', 'a1000000-0000-4000-8000-000000000001')->exists()) {
        throw new RuntimeException('Fixture already prepared; use snapshot, never replay setup.');
    }
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    if (app(KycStatusService::class)->forUser($tenant->id, $user->id)->value !== 'APPROVED') {
        $application = app(SubmitKycApplicationAction::class)->execute($tenant, $user, 'MY', 'ACCEPTANCE-'.$user->id,
            UploadedFile::fake()->createWithContent('front.png', $png), UploadedFile::fake()->createWithContent('back.png', $png));
        app(ApproveKycAction::class)->execute($tenant->id, $application->id, AdminUser::where('email', 'owner@a.localhost')->firstOrFail());
    }
    app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id);
    ChainConnection::whereIn('network', ['ETHEREUM', 'BITCOIN'])->update(['enabled' => true, 'start_height' => 100, 'next_height' => 100]);
    $settings = app(WealthConfiguration::class)->get($tenant->id);
    foreach ($settings as &$setting) {
        $setting['minimum'] = '1';
        foreach ($setting['products'] as &$product) {
            $product['enabled'] = true;
        }
        unset($product);
    }
    unset($setting);
    app(WealthConfiguration::class)->save($platform, $tenant->id, ['settings' => $settings]);
    // New historical orders are created normally under a controlled clock; no snapshot edits.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->subMonthsNoOverflow(2)->subDay());
    Carbon::setTestNow(CarbonImmutable::now());
    foreach (['USDT_ETHEREUM', 'USDC_ETHEREUM', 'ETH_ETHEREUM', 'BTC_BITCOIN'] as $i => $code) {
        $rail = AssetRail::findOrFail($code);
        $rail->update(['enabled' => true, 'deposit_address' => $rail->network === 'BITCOIN' ? 'bc1q'.str_repeat('q', 38) : '0x'.str_repeat('1', 40)]);
        CompanyRail::updateOrCreate(['tenant_id' => $tenant->id, 'rail_code' => $code], ['deposit_enabled' => true, 'withdrawal_enabled' => true, 'minimum_deposit' => '1', 'withdrawal_fee_percent' => in_array($rail->asset_code, ['USDT', 'USDC']) ? '10' : '0']);
        $deposit = app(DepositAssetsAction::class)->create($tenant->id, $user->id, $code, '5000', (string) Str::uuid());
        app(DepositAssetsAction::class)->manual($tenant->id, $deposit->id, $platform, (string) Str::uuid(), true);
        $setting = collect(app(WealthConfiguration::class)->get($tenant->id))->firstWhere('asset', $rail->asset_code);
        app(WealthService::class)->deposit($tenant->id, $user->id, $rail->asset_code, '1000', 6, $setting['revision'], 'a1000000-0000-4000-8000-'.str_pad((string) ($i + 1), 12, '0', STR_PAD_LEFT));
    }
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
    if (Artisan::call('wealth:recover') !== 0) {
        throw new RuntimeException(Artisan::output());
    }
}
$rows = LedgerAccount::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get(['asset_code', 'account_type', 'balance']);
$mismatches = app(LedgerReconciliationService::class)->mismatches();
if ($mismatches !== []) {
    throw new RuntimeException('Ledger mismatch');
}
if ($mode === 'reconcile') {
    foreach (['ledger:reconcile', 'payments:reconcile'] as $command) {
        if (Artisan::call($command) !== 0) {
            throw new RuntimeException(Artisan::output());
        }
    }
}
echo json_encode(['tenant' => $tenant->id, 'user' => $user->id, 'balances' => $rows,
    'orders' => WealthOrder::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get()->map(fn ($o) => ['id' => $o->id, 'asset' => $o->asset_code, 'principal' => $o->principal, 'paid' => app(WealthService::class)->paid($o), 'status' => $o->status]),
    'wealthSettings' => app(WealthConfiguration::class)->get($tenant->id),
    'ledgerEntries' => DB::table('ledger_entries')->count(), 'mismatches' => $mismatches], JSON_PRETTY_PRINT)."\n";
