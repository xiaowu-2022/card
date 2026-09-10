<?php

declare(strict_types=1);

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Payment\CreateWalletTopupAction;
use App\Application\Payment\CreditWalletTopupAction;
use App\Application\Payment\PaymentLedgerReconciliationService;
use App\Application\Payment\PersistPaymentProviderEventAction;
use App\Application\Payment\ProcessPaymentProviderEventAction;
use App\Application\Payment\QueryPaymentStatusAction;
use App\Application\Tenant\SuspendTenantAction;
use App\Application\User\SuspendUserAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerReconciliationService;
use App\Domain\Payment\Contracts\PaymentProviderInterface;
use App\Domain\Payment\Enums\PaymentEventProcessingStatus;
use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Models\PaymentProviderEvent;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! extension_loaded('pcntl')) {
    fwrite(STDERR, "pcntl is required for Phase 5 concurrency verification.\n");
    exit(2);
}

config(['payment.mock_mode' => 'PENDING']);
Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
Queue::fake();

/** @param list<Closure():void> $tasks @return list<int> */
$parallel = function (array $tasks): array {
    $children = [];
    foreach ($tasks as $task) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::disconnect();
            try {
                DB::reconnect();
                $task();
                exit(0);
            } catch (Throwable $exception) {
                $category = $exception instanceof DomainException ? $exception->errorCode : $exception::class;
                fwrite(STDERR, "Phase 5 child rejected: {$category}\n");
                exit(1);
            }
        }
        $children[] = $pid;
    }
    $statuses = [];
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $statuses[] = pcntl_wexitstatus($status);
    }
    DB::disconnect();
    DB::reconnect();

    return $statuses;
};

$tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
$user = User::query()->where('tenant_id', $tenant->id)->firstOrFail();
$owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
$platformOwner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
$imagePath = tempnam(sys_get_temp_dir(), 'payment-concurrency-image-');
file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
$application = app(SubmitKycApplicationAction::class)->execute(
    $tenant, $user, 'MY', 'PHASE-FIVE-CONCURRENCY',
    new UploadedFile($imagePath, 'front.png', 'image/png', null, true),
    new UploadedFile($imagePath, 'back.png', 'image/png', null, true),
);
app(ApproveKycAction::class)->execute($tenant->id, $application->id, $owner);
@unlink($imagePath);
$wallet = app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id)->wallet;

$create = fn (string $requestId, string $amount = '10.00000000') => app(CreateWalletTopupAction::class)->execute(
    $tenant->id, $user->id, $wallet->id, $amount, 'USD', $requestId, 'http://a.localhost/wallet/top-ups/__ORDER__/return',
);
$createRaceId = (string) Str::uuid();
$sameCreate = $parallel([fn () => $create($createRaceId), fn () => $create($createRaceId)]);
$differentCreateId = (string) Str::uuid();
$differentCreate = $parallel([fn () => $create($differentCreateId, '10.00000000'), fn () => $create($differentCreateId, '11.00000000')]);

$eventOrder = $create((string) Str::uuid())->order;
$eventTransaction = PaymentProviderTransaction::query()->where('wallet_topup_order_id', $eventOrder->id)->firstOrFail();
$eventBody = fn (string $eventId, string $amount = '10.00000000'): string => json_encode([
    'event_id' => $eventId, 'event_type' => 'PAYMENT_SUCCEEDED',
    'provider_request_id' => $eventTransaction->provider_request_id,
    'provider_transaction_id' => $eventTransaction->provider_transaction_id,
    'status' => 'SUCCEEDED', 'amount' => $amount, 'asset' => 'USD',
], JSON_THROW_ON_ERROR);
$persist = function (string $body): void {
    $request = Request::create('/webhooks/payments/mock', 'POST', [], [], [], [], $body);
    $request->headers->set('X-Mock-Signature', hash_hmac('sha256', $body, (string) config('payment.mock_webhook_secret')));
    app(PersistPaymentProviderEventAction::class)->execute('mock', $request);
};
$persistFor = function (WalletTopupOrder $order, string $eventId, string $eventType, string $status): PaymentProviderEvent {
    $transaction = PaymentProviderTransaction::query()->where('wallet_topup_order_id', $order->id)->firstOrFail();
    $body = json_encode([
        'event_id' => $eventId, 'event_type' => $eventType,
        'provider_request_id' => $transaction->provider_request_id,
        'provider_transaction_id' => $transaction->provider_transaction_id,
        'status' => $status, 'amount' => $order->amount, 'asset' => $order->asset_code,
    ], JSON_THROW_ON_ERROR);
    $request = Request::create('/webhooks/payments/mock', 'POST', [], [], [], [], $body);
    $request->headers->set('X-Mock-Signature', hash_hmac('sha256', $body, (string) config('payment.mock_webhook_secret')));

    return app(PersistPaymentProviderEventAction::class)->execute('mock', $request);
};
$duplicateBody = $eventBody('phase5-same-event');
$sameEvent = $parallel([fn () => $persist($duplicateBody), fn () => $persist($duplicateBody)]);
$conflictEvent = $parallel([
    fn () => $persist($eventBody('phase5-conflict-event', '10.00000000')),
    fn () => $persist($eventBody('phase5-conflict-event', '11.00000000')),
]);

$paidOrder = fn (): WalletTopupOrder => tap($create((string) Str::uuid())->order, function (WalletTopupOrder $order): void {
    DB::table('wallet_topup_orders')->where('id', $order->id)->update(['status' => WalletTopupStatus::Paid->value, 'paid_at' => now(), 'updated_at' => now()]);
    DB::table('payment_provider_transactions')->where('wallet_topup_order_id', $order->id)->update(['status' => PaymentProviderTransactionStatus::Succeeded->value, 'updated_at' => now()]);
});
$settlementOrder = $paidOrder();
$sameSettlement = $parallel([
    fn () => app(CreditWalletTopupAction::class)->execute($tenant->id, $settlementOrder->id),
    fn () => app(CreditWalletTopupAction::class)->execute($tenant->id, $settlementOrder->id),
]);

$refundRaceOrder = $paidOrder();
$refundRaceEvent = $persistFor($refundRaceOrder, 'phase51-refund-race', 'PAYMENT_REFUNDED', 'FAILED');
$refundVsCredit = $parallel([
    fn () => app(CreditWalletTopupAction::class)->execute($tenant->id, $refundRaceOrder->id),
    fn () => app(ProcessPaymentProviderEventAction::class)->execute($tenant->id, $refundRaceEvent->id),
]);

$querySuccessOrder = $create((string) Str::uuid())->order;
$querySuccessTransaction = PaymentProviderTransaction::query()->where('wallet_topup_order_id', $querySuccessOrder->id)->firstOrFail();
$pendingRaceEvent = $persistFor($querySuccessOrder, 'phase51-query-success-pending', 'PAYMENT_PENDING', 'PENDING');
$querySuccessVsPending = $parallel([
    function () use ($app, $tenant, $querySuccessTransaction): void {
        config(['payment.mock_mode' => 'SUCCEEDED']);
        $app->forgetInstance(PaymentProviderInterface::class);
        app(QueryPaymentStatusAction::class)->execute($tenant->id, $querySuccessTransaction->id);
    },
    fn () => app(ProcessPaymentProviderEventAction::class)->execute($tenant->id, $pendingRaceEvent->id),
]);

$queryFailureOrder = $create((string) Str::uuid())->order;
$queryFailureTransaction = PaymentProviderTransaction::query()->where('wallet_topup_order_id', $queryFailureOrder->id)->firstOrFail();
$successRaceEvent = $persistFor($queryFailureOrder, 'phase51-query-failure-success', 'PAYMENT_SUCCEEDED', 'SUCCEEDED');
$queryFailureVsSuccess = $parallel([
    function () use ($app, $tenant, $queryFailureTransaction): void {
        config(['payment.mock_mode' => 'FAILED']);
        $app->forgetInstance(PaymentProviderInterface::class);
        app(QueryPaymentStatusAction::class)->execute($tenant->id, $queryFailureTransaction->id);
    },
    fn () => app(ProcessPaymentProviderEventAction::class)->execute($tenant->id, $successRaceEvent->id),
]);

$expiryOrder = $create((string) Str::uuid())->order;
$expiryEvent = $persistFor($expiryOrder, 'phase51-expiry', 'PAYMENT_EXPIRED', 'FAILED');
$expirySuccessEvent = $persistFor($expiryOrder, 'phase51-expiry-success', 'PAYMENT_SUCCEEDED', 'SUCCEEDED');
$expiryVsSuccess = $parallel([
    fn () => app(ProcessPaymentProviderEventAction::class)->execute($tenant->id, $expiryEvent->id),
    fn () => app(ProcessPaymentProviderEventAction::class)->execute($tenant->id, $expirySuccessEvent->id),
]);

$recoverRace = $parallel([
    fn () => Artisan::call('payments:recover'),
    fn () => Artisan::call('payments:recover'),
]);

$userSuspensionOrder = $paidOrder();
$tenantSuspensionOrder = $paidOrder();
$settlementVsUserSuspension = $parallel([
    fn () => app(CreditWalletTopupAction::class)->execute($tenant->id, $userSuspensionOrder->id),
    fn () => app(SuspendUserAction::class)->execute($tenant->id, $user->id, $owner),
]);
DB::table('users')->where('id', $user->id)->update(['status' => UserStatus::Active->value, 'updated_at' => now()]);
$settlementVsTenantSuspension = $parallel([
    fn () => app(CreditWalletTopupAction::class)->execute($tenant->id, $tenantSuspensionOrder->id),
    fn () => app(SuspendTenantAction::class)->execute($tenant->id, $platformOwner),
]);

$available = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserAvailable->value)->firstOrFail();
$result = [
    'same_create_request' => ['children' => $sameCreate, 'orders' => WalletTopupOrder::query()->where('request_id', $createRaceId)->count()],
    'different_create_payload' => ['children' => $differentCreate, 'orders' => WalletTopupOrder::query()->where('request_id', $differentCreateId)->count()],
    'same_provider_event' => ['children' => $sameEvent, 'events' => PaymentProviderEvent::query()->where('provider_event_key', 'phase5-same-event')->count()],
    'different_payload_event' => ['children' => $conflictEvent, 'events' => PaymentProviderEvent::query()->where('provider_event_key', 'phase5-conflict-event')->count(), 'status' => PaymentProviderEvent::query()->where('provider_event_key', 'phase5-conflict-event')->firstOrFail()->processing_status->value],
    'same_paid_settlement' => ['children' => $sameSettlement, 'entries' => LedgerEntry::query()->where('event_key', "wallet_topup:{$settlementOrder->id}:credit")->count()],
    'refund_vs_credit' => ['children' => $refundVsCredit, 'order' => $refundRaceOrder->fresh()->status->value, 'entries' => LedgerEntry::query()->where('event_key', "wallet_topup:{$refundRaceOrder->id}:credit")->count(), 'event' => $refundRaceEvent->fresh()->processing_status->value],
    'query_success_vs_webhook_pending' => ['children' => $querySuccessVsPending, 'order' => $querySuccessOrder->fresh()->status->value],
    'query_failure_vs_webhook_success' => ['children' => $queryFailureVsSuccess, 'order' => $queryFailureOrder->fresh()->status->value],
    'expiry_vs_provider_success' => ['children' => $expiryVsSuccess, 'order' => $expiryOrder->fresh()->status->value],
    'recover_vs_recover' => ['children' => $recoverRace],
    'settlement_vs_user_suspension' => ['children' => $settlementVsUserSuspension, 'order' => $userSuspensionOrder->fresh()->status->value],
    'settlement_vs_tenant_suspension' => ['children' => $settlementVsTenantSuspension, 'order' => $tenantSuspensionOrder->fresh()->status->value],
    'available_balance' => $available->fresh()->balance,
    'reconciliation_mismatches' => count(app(LedgerReconciliationService::class)->mismatches()),
    'payment_reconciliation_mismatches' => count(app(PaymentLedgerReconciliationService::class)->mismatches()),
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

$checks = [
    'same create' => $sameCreate === [0, 0] && $result['same_create_request']['orders'] === 1,
    'different create' => collect($differentCreate)->sort()->values()->all() === [0, 1] && $result['different_create_payload']['orders'] === 1,
    'same event' => $sameEvent === [0, 0] && $result['same_provider_event']['events'] === 1,
    'conflicting event' => collect($conflictEvent)->sort()->values()->all() === [0, 1]
        && $result['different_payload_event']['events'] === 1
        && $result['different_payload_event']['status'] === PaymentEventProcessingStatus::RequiresReview->value,
    'same settlement' => $sameSettlement === [0, 0] && $result['same_paid_settlement']['entries'] === 1,
    'refund vs credit' => $refundVsCredit === [0, 0]
        && (($result['refund_vs_credit']['order'] === WalletTopupStatus::Refunded->value && $result['refund_vs_credit']['entries'] === 0)
            || ($result['refund_vs_credit']['order'] === WalletTopupStatus::Credited->value && $result['refund_vs_credit']['entries'] === 1
                && $result['refund_vs_credit']['event'] === PaymentEventProcessingStatus::RequiresReview->value)),
    'query success vs pending' => $querySuccessVsPending === [0, 0]
        && $result['query_success_vs_webhook_pending']['order'] === WalletTopupStatus::Paid->value,
    'query failure vs success' => $queryFailureVsSuccess === [0, 0]
        && $result['query_failure_vs_webhook_success']['order'] === WalletTopupStatus::Paid->value,
    'expiry vs success' => $expiryVsSuccess === [0, 0]
        && $result['expiry_vs_provider_success']['order'] === WalletTopupStatus::Paid->value,
    'recover overlap' => $recoverRace === [0, 0],
    'user suspension' => $settlementVsUserSuspension === [0, 0]
        && $result['settlement_vs_user_suspension']['order'] === WalletTopupStatus::Credited->value,
    'tenant suspension' => $settlementVsTenantSuspension === [0, 0]
        && $result['settlement_vs_tenant_suspension']['order'] === WalletTopupStatus::Credited->value,
    'balance' => $result['available_balance'] === ($result['refund_vs_credit']['order'] === WalletTopupStatus::Credited->value ? '40.00000000' : '30.00000000'),
    'ledger reconciliation' => $result['reconciliation_mismatches'] === 0,
    'payment reconciliation' => $result['payment_reconciliation_mismatches'] === 0,
];
$failedChecks = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));
if ($failedChecks !== []) {
    fwrite(STDERR, 'Failed acceptance checks: '.implode(', ', $failedChecks).PHP_EOL);
}

exit($failedChecks === [] ? 0 : 1);
