<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Payment\CreateTrc20WalletTopupAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Services\WalletProvisioner;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('exports isolated read-only consumer fixtures for old and generated H5 comparison', function () {
    $this->seed();
    Http::preventStrayRequests();
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $user = User::where('tenant_id', $tenant->id)->where('email', 'user@a.localhost')->firstOrFail();
    $version = app(HandleInertiaRequests::class)->version(Request::create('http://a.localhost'));
    $before = DB::table('ledger_entries')->count();
    $fixtures = ['pages' => [], 'api' => []];
    foreach (['/', '/login', '/register', '/forgot-password'] as $path) {
        $fixtures['pages'][$path] = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->get('http://a.localhost'.$path)->assertOk()->json();
    }
    $this->flushHeaders();
    $fixtures['guest'] = $this->getJson('http://a.localhost/api/v1/bootstrap')->assertOk()->json();
    $this->actingAs($user, 'tenant_user')->withSession(['tenant_user_session_version' => $user->session_version]);
    $this->flushHeaders();
    $fixtures['authenticated'] = $this->getJson('http://a.localhost/api/v1/bootstrap')->assertOk()->json();
    foreach (['/dashboard', '/account', '/account/settings', '/account/security', '/kyc', '/cards', '/wallet', '/wallet/top-up', '/wallet/transfer', '/security-deposit', '/security-deposit/history', '/wealth', '/wealth/assets/USDT', '/promotion', '/promotion/membership', '/promotion/invitations', '/promotion/rules', '/promotion/registration', '/promotion/features', '/promotion/reward-guide', '/promotion/direct', '/promotion/daily', '/promotion/commissions', '/messages', '/support', '/about'] as $path) {
        $this->flushHeaders();
        $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->get('http://a.localhost'.$path);
        if ($response->isRedirection()) {
            $fixtures['pages'][$path] = ['redirect' => parse_url($response->headers->get('Location'), PHP_URL_PATH)];
        } else {
            $response->assertOk();
            $fixtures['pages'][$path] = $response->json();
        }
    }
    $this->flushHeaders();
    foreach (['/account', '/messages', '/support', '/unread'] as $path) {
        $fixtures['api'][$path] = $this->getJson('http://a.localhost/api/v1'.$path)->assertOk()->json();
    }
    expect(DB::table('ledger_entries')->count())->toBe($before);
    // New synthetic records in card_ui_test only; all upstream calls remain blocked.
    Storage::fake('private');
    $ocr = Mockery::mock(KycOcrProviderInterface::class);
    $ocr->shouldReceive('name')->andReturn('TEST');
    $ocr->shouldReceive('extractIdentityDocument')->andReturn(new KycOcrResultDTO(KycOcrOutcome::Success, 'PARITY-'.$user->id));
    app()->instance(KycOcrProviderInterface::class, $ocr);
    $application = app(SubmitKycApplicationAction::class)->execute($tenant, $user, 'CN', 'PARITY-'.$user->id, kycTestImage('front.png'), kycTestImage('back.png'));
    $reviewer = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    app(ApproveKycAction::class)->execute($tenant->id, $application->id, $reviewer);
    $wallet = DB::transaction(fn () => app(WalletProvisioner::class)->provision($tenant, $user, 'USDT'));
    $account = LedgerAccount::where('wallet_id', $wallet->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $clearing = LedgerAccount::where('tenant_id', $tenant->id)->where('asset_code', 'USDT')->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
    app(LedgerWriter::class)->post(new LedgerPostingPlan($tenant->id, 'USDT', 'parity-fixture:'.Str::uuid(), 'TEST_TOPUP', null, null, null, [new LedgerPostingInstruction($clearing->id, Money::of('-2000', 'USDT')), new LedgerPostingInstruction($account->id, Money::of('2000', 'USDT'))]));
    $topup = app(CreateTrc20WalletTopupAction::class)->execute($tenant->id, $user->id, '20', (string) Str::uuid());
    $before = DB::table('ledger_entries')->count();
    foreach (['/dashboard', '/cards', '/kyc', '/wallet', '/wallet/transfer', '/security-deposit', '/wallet/withdraw', '/wallet/withdrawals', '/funds', '/assets/operate?mode=deposit&asset=USDT', '/assets/operate?mode=withdrawal&asset=ETH', '/assets/operate?mode=exchange&asset=BTC', '/wealth/assets/USDT', '/about/terms', '/about/privacy', '/about/account-closure', '/promotion/rewards?kind=ACTIVATION&rank=0', '/wallet/top-ups/'.$topup->order->id.'/return'] as $path) {
        $this->flushHeaders();
        $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->get('http://a.localhost'.$path);
        $response->assertOk();
        $key = $path.(str_contains($path, '?') ? '&' : '?').'fixture=verified';
        $fixtures['pages'][$key] = $response->json();
    }
    expect(DB::table('ledger_entries')->count())->toBe($before);
    if (getenv('UNI_PARITY_EXPORT') === '1') {
        $dir = storage_path('framework/testing/uni-parity');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir.'/fixtures.json', json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
});
