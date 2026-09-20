<?php

// Explicit local fixture batch requested by the user on 2026-09-20. Never auto-run.
use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Payment\CreateWalletTopupAction;
use App\Application\Payment\CreditWalletTopupAction;
use App\Application\Payment\InitiateWalletTopupPaymentAction;
use App\Application\Payment\QueryPaymentStatusAction;
use App\Application\Promotion\PaidPromotionQuery;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\Tenant\CompanyConfigurationAuthority;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Payment\Enums\MockPaymentMode;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Domain\Payment\Services\PaymentStateTransitionPolicy;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserProfile;
use App\Infrastructure\Providers\Payment\MockPaymentProvider;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(app()->environment('local', 'testing') && in_array(DB::connection()->getDatabaseName(), ['card_mock', 'card_ui_test'], true) && config('payment.driver') === 'mock', 403);
$tenantId = '01a09996-8c36-7288-bf98-889103088ba6';
$rootId = '01a09996-8ff2-71c9-84ee-9d6157ee5576';
$actor = AdminUser::findOrFail('01a09996-8d2b-737b-9a89-29e301109a66');
app(CompanyConfigurationAuthority::class)->assert($actor);
$tenant = Tenant::whereKey($tenantId)->where('status', 'ACTIVE')->firstOrFail();
$root = User::where('tenant_id', $tenantId)->whereKey($rootId)->where('status', 'ACTIVE')->firstOrFail();
$deposit = (string) $tenant->businessSettings()->firstOrFail()->required_security_deposit_amount;
abort_unless(BigDecimal::of($deposit)->isPositive(), 422);
$batch = 'direct-activation-20260920-'.$root->account_id;
abort_unless(DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired', [$batch])->acquired, 409);
try {
    $audit = app(AuditLogger::class);
    $existing = DB::table('audit_logs')->where('tenant_id', $tenantId)->where('resource_id', $rootId)->where('action', 'LOCAL_DIRECT_501_STARTED')->first();
    if (! $existing) {
        $audit->record($tenantId, 'ADMIN', $actor->id, 'LOCAL_DIRECT_501_STARTED', 'user', $rootId, null, ['batch' => $batch, 'count' => 501, 'deposit' => $deposit]);
    } else {
        abort_unless(json_decode($existing->after_data, true)['deposit'] === $deposit, 409);
    }
    Queue::fake(); // New fixture payments only; execute their credit inline below.
    $provider = new MockPaymentProvider(MockPaymentMode::Succeeded, Str::random(48));
    $transitions = app(PaymentStateTransitionPolicy::class);
    $payments = new CreateWalletTopupAction($provider, app(KycStatusService::class), $audit, new InitiateWalletTopupPaymentAction($provider, $transitions));
    $query = new QueryPaymentStatusAction($provider, $transitions);
    $members = app(PromotionMembershipAction::class);
    $parent = $members->ensure($tenantId, $rootId);
    $password = Hash::make(Str::random(64));
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    for ($i = 1; $i <= 501; $i++) {
        $user = DB::transaction(function () use ($tenantId, $batch, $i, $password, $members, $parent) {
            $user = User::firstOrCreate(['tenant_id' => $tenantId, 'email' => $batch.'-'.$i.'@fixture.invalid'], ['password_hash' => $password, 'status' => 'ACTIVE', 'email_verified_at' => now()]);
            UserProfile::firstOrCreate(['tenant_id' => $tenantId, 'user_id' => $user->id], ['display_name' => '直属激活测试 '.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
            $members->ensure($tenantId, $user->id, $parent->id);

            return $user;
        });
        if (! IdentityRecord::where('tenant_id', $tenantId)->where('user_id', $user->id)->exists()) {
            $kyc = KycApplication::where('tenant_id', $tenantId)->where('user_id', $user->id)->first();
            $kyc ??= app(SubmitKycApplicationAction::class)->execute($tenant, $user, 'MY', 'LOCAL-DIRECT-'.$user->id, UploadedFile::fake()->createWithContent('LOCAL-TEST-front.png', $image), UploadedFile::fake()->createWithContent('LOCAL-TEST-back.png', $image));
            if ($kyc->review_status->value === 'PENDING') {
                app(ApproveKycAction::class)->execute($tenantId, $kyc->id, $actor);
            }
        }
        $wallet = app(ActivateUserWalletAction::class)->execute($tenantId, $user->id)->wallet;
        $topup = $payments->execute($tenantId, $user->id, $wallet->id, $deposit, 'USDT', $user->id, 'http://a.localhost/wallet/top-ups/fixture/return');
        if ($topup->order->status->value !== 'CREDITED') {
            abort_unless($topup->created, 409, 'Incomplete fixture payment needs inspection.');
            $tx = PaymentProviderTransaction::where('tenant_id', $tenantId)->where('wallet_topup_order_id', $topup->order->id)->firstOrFail();
            $query->execute($tenantId, $tx->id);
            app(CreditWalletTopupAction::class)->execute($tenantId, $topup->order->id);
        }
        app(FundSecurityDepositAction::class)->execute($tenantId, $user->id, $user->id, $deposit);
        if ($i % 25 === 0 || $i === 501) {
            echo "Activated {$i}/501\n";
        }
    }
    if (! DB::table('audit_logs')->where('tenant_id', $tenantId)->where('resource_id', $rootId)->where('action', 'LOCAL_DIRECT_501_COMPLETED')->exists()) {
        $audit->record($tenantId, 'ADMIN', $actor->id, 'LOCAL_DIRECT_501_COMPLETED', 'user', $rootId, null, ['batch' => $batch, 'count' => 501]);
    }
    $result = app(PaidPromotionQuery::class)->benefits($tenantId, $rootId);
    echo json_encode(['upgradeEligibility' => $result['upgradeEligibility'], 'levels' => array_map(fn ($l) => ['rank' => $l['rank'], 'selectable' => $l['selectable']], $result['levels'])], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    DB::select('SELECT pg_advisory_unlock(hashtextextended(?, 0))',[$batch]);
}
