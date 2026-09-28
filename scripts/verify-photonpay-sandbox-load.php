<?php

// Explicit, bounded sandbox acceptance. The fixed request ID prevents duplicate loads.
use App\Application\Card\ManageCardAction;
use App\Application\Card\SyncUserCardTransactionsAction;
use App\Application\CardProviderDirectory\PhotonPayAccounts;
use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\CardTransaction;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Domain\Ledger\ValueObjects\Money;
use App\Support\Errors\DomainException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'card_mock') {
    throw new RuntimeException('Local card_mock only');
}
if (($argv[1] ?? '') !== '--execute') {
    throw new RuntimeException('Requires explicit --execute; loads USD 20 on the existing sandbox card');
}
$report = ['environment' => 'sandbox', 'requested_amount' => '20.00', 'currency' => 'USD'];
try {
    $card = UserCard::findOrFail('01a0a290-0399-72ea-8852-6150e924fa35');
    $product = CardProduct::findOrFail($card->card_product_id);
    $merchant = CardProviderReference::findOrFail($product->card_provider_reference_id);
    $issuing = json_decode(Crypt::decryptString($merchant->photonpay_issuing_encrypted), true, 512, JSON_THROW_ON_ERROR);
    if (($issuing['base_url'] ?? '') !== PhotonPayAccounts::BASES['sandbox']
        || ($merchant->photonpay_identity['base_url'] ?? '') !== PhotonPayAccounts::BASES['sandbox']
        || $card->tenant_id !== '01a09996-8c36-7288-bf98-889103088ba6'
        || $card->user_id !== '01a09996-8ff2-71c9-84ee-9d6157ee5576') {
        throw new RuntimeException('Sandbox ownership guard failed');
    }
    $request = '89cc40dd-0927-4927-8000-202609270020';
    $order = CardManagementOrder::where('tenant_id', $card->tenant_id)->where('user_id', $card->user_id)->where('request_id', $request)->first();
    $report['reused_existing_intent'] = $order !== null;
    if (! $order) {
        $order = app(ManageCardAction::class)->quote($card->tenant_id, $card->user_id, $card->id, $request, '20.00');
        if ($order->status !== 'QUOTED'
            || Money::of($order->debit_amount, 'USDT')->compare(Money::of('25', 'USDT')) > 0
            || ! Money::of($order->manual_funding_amount, 'USD')->isZero()) {
            throw new RuntimeException('Bounded provider-only quote guard failed');
        }
        $order = app(ManageCardAction::class)->confirmLoad($card->tenant_id, $card->user_id, $card->id, $order->id);
    }
    $report['order'] = $order->only(['id', 'status', 'amount', 'arrival_amount', 'debit_amount', 'fee_amount']);
    $report['result'] = $order->status === 'SUCCEEDED' ? 'PASS' : 'NOT_CONFIRMED_SUCCESS';
    if ($order->status === 'SUCCEEDED' && in_array('--sync-transactions', $argv, true)) {
        // Explicit scoped read-model refresh, never a provider or financial replay.
        app(SyncUserCardTransactionsAction::class)->execute($card->tenant_id, $card->user_id, $card->id, 1);
        $report['saved_transaction_matches'] = CardTransaction::where('tenant_id', $card->tenant_id)
            ->where('user_id', $card->user_id)->where('card_id', $card->id)
            ->where('provider_transaction_id', $order->provider_transaction_id)->where('amount', $order->arrival_amount)->exists();
    }
} catch (Throwable $e) {
    $report['result'] = 'FAILED_OR_BLOCKED';
    $report['error_class'] = $e::class;
    if ($e instanceof DomainException) {
        $report['error_code'] = $e->errorCode;
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($report['result'] === 'PASS' && ($report['saved_transaction_matches'] ?? true) ? 0 : 1);
