<?php

// Explicit read-only sandbox acceptance; never submits issuing/funding/activation requests.
use App\Application\Card\CardProductProviderRouter;
use App\Application\CardProviderDirectory\PhotonPayAccounts;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Providers\Card\PhotonPayMerchantReport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'card_mock') {
    throw new RuntimeException('Local card_mock only');
}
$report = ['mode' => 'sandbox_read_only', 'checks' => []];
foreach (CardProviderReference::whereNotNull('photonpay_issuing_encrypted')->get() as $merchant) {
    $item = ['account' => $merchant->id, 'stored_check' => $merchant->photonpay_check_status];
    try {
        $c = json_decode(Crypt::decryptString($merchant->photonpay_issuing_encrypted), true, 512, JSON_THROW_ON_ERROR);
        $r = json_decode(Crypt::decryptString($merchant->photonpay_reporting_encrypted), true, 512, JSON_THROW_ON_ERROR);
        if (($c['base_url'] ?? '') !== PhotonPayAccounts::BASES['sandbox'] || ($r['base_url'] ?? '') !== $c['base_url'] || ($merchant->photonpay_identity['base_url'] ?? '') !== $c['base_url']) {
            $item['result'] = 'SKIPPED_NOT_CONFIRMED_SANDBOX';
            $report['checks'][] = $item;

            continue;
        }
        $item['environment'] = 'sandbox';
        $http = fn () => Http::connectTimeout(5)->timeout(20)->withoutRedirecting();
        $token = $http()->withHeaders(['Authorization' => 'basic '.base64_encode($c['app_id'].'/'.$c['app_secret'])])->withBody('', 'application/json')->post($c['base_url'].'/oauth2/token/accessToken');
        $item['auth_http'] = $token->status();
        if (! $token->successful() || $token->json('code') !== '0000' || ! is_string($token->json('data.token'))) {
            throw new RuntimeException('Authentication failed');
        }
        $account = $http()->withHeaders(['X-PD-TOKEN' => $token->json('data.token')])->get($c['base_url'].'/wallet/openApi/v4/account/single', ['currency' => 'USD', 'accountType' => 'FT10001', 'memberId' => $c['member_id']]);
        $item['account_http'] = $account->status();
        if (! $account->successful() || $account->json('code') !== '0000' || $account->json('data.accountNo') !== $c['account_id'] || $account->json('data.memberId') !== $c['member_id']) {
            throw new RuntimeException('Account ownership mismatch');
        }
        $item['ownership'] = 'MATCH';
        $bins = app(PhotonPayMerchantReport::class)->bins($merchant->photonpay_reporting_encrypted, true);
        $item['bins'] = array_map(fn ($b) => array_intersect_key($b, array_flip(['bin', 'formFactors', 'currencies'])), $bins);
        $products = CardProduct::where('card_provider_reference_id', $merchant->id)->get();
        $item['products'] = $products->map(fn ($p) => ['id' => $p->id, 'bin' => $p->provider_product_ref, 'archived' => $p->archived_at !== null])->all();
        $cards = UserCard::whereIn('card_product_id', $products->pluck('id'))->withoutTestReferences()->limit(3)->get();
        $item['cards'] = [];
        foreach ($cards as $card) {
            $provider = app(CardProductProviderRouter::class)->forCard($card);
            $row = ['local_id' => $card->id];
            try {
                $result = $provider->getCard($card->provider_card_id);
                $row['identity_match'] = $result->providerCardId === $card->provider_card_id;
                if (! $row['identity_match']) {
                    throw new RuntimeException('Card identity mismatch');
                }
                $row['status'] = $result->status;
                $row['asset'] = $result->assetCode;
                $transactions = $provider->getTransactionPage($card->provider_card_id, 1, 20);
                $row['transaction_count'] = count($transactions->items);
                $row['result'] = 'PASS';
            } catch (Throwable $e) {
                $row['result'] = 'FAILED';
                $row['error_class'] = $e::class;
            }
            $item['cards'][] = $row;
        }
        $item['result'] = 'READ_CHECKS_COMPLETED';
    } catch (Throwable $e) {
        $item['result'] = 'FAILED';
        $item['error_class'] = $e::class;
    }
    $report['checks'][] = $item;
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($report['checks'] !== [] && collect($report['checks'])->every(fn ($item) => $item['result'] === 'READ_CHECKS_COMPLETED'
    && collect($item['cards'] ?? [])->every(fn ($card) => $card['result'] === 'PASS')) ? 0 : 1);
