<?php

// User-authorized, bounded local sandbox acceptance. Never change stable intent IDs.
use App\Application\Card\CardholderTestMaterialsArchive;
use App\Application\Card\CardProductProviderRouter;
use App\Application\Card\CreateCardIssueAction;
use App\Application\Card\ManageCardAction;
use App\Application\Card\RefreshManagedCardAction;
use App\Application\Card\SubmitProviderCardholderAction;
use App\Application\Card\SyncCardIssueAction;
use App\Application\Card\SyncProviderCardholderAction;
use App\Application\CardProduct\ConfigureTenantCardProductAction;
use App\Application\CardProviderDirectory\PhotonPayAccounts;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Domain\Ledger\ValueObjects\Money;
use App\Support\Errors\DomainException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'card_mock') {
    throw new RuntimeException('Local card_mock only');
}
$tenant = '01a09996-8c36-7288-bf98-889103088ba6';
$user = '01a09996-8ff2-71c9-84ee-9d6157ee5576';
$productId = '01a09f97-2e67-7335-a853-e61b0b7d3b11';
$mode = $argv[1] ?? 'inspect';
if (! in_array($mode, ['inspect', '--issue', '--freeze', '--cancel'], true)) {
    throw new RuntimeException('Supported modes: inspect, --issue, --freeze, --cancel. Unfreeze and return were verified through H5.');
}
$report = ['mode' => $mode, 'environment' => 'sandbox', 'checks' => []];
$restoreConfig = null;
$intent = fn (string $kind) => Uuid::uuid5(Uuid::NAMESPACE_URL, 'card-acceptance-20260927-r3/'.$productId.'/'.$user.'/'.$kind)->toString();
try {
    $product = CardProduct::findOrFail($productId);
    $merchant = $product->cardProviderReference;
    $connection = json_decode(Crypt::decryptString($merchant->photonpay_issuing_encrypted), true, 512, JSON_THROW_ON_ERROR);
    if (($connection['base_url'] ?? '') !== PhotonPayAccounts::BASES['sandbox']
        || ($merchant->photonpay_identity['base_url'] ?? '') !== PhotonPayAccounts::BASES['sandbox']
        || $merchant->photonpay_check_status !== 'VERIFIED'
        || ! app(CardProductProviderRouter::class)->isSandbox($product)) {
        throw new RuntimeException('Verified sandbox required');
    }
    $application = ProviderCardholder::where('tenant_id', $tenant)->where('user_id', $user)->where('request_id', $intent('holder'))->first();
    $issue = CardIssueOrder::where('tenant_id', $tenant)->where('user_id', $user)->where('request_id', $intent('issue'))->first();
    if ($mode === '--issue' && ! $issue) {
        if (in_array('--temporary-second-card', $argv, true)) {
            $config = TenantCardProductConfig::where('tenant_id', $tenant)->where('card_product_id', $productId)->sole();
            if ($config->max_cards_per_user !== 1) {
                throw new RuntimeException('Expected original card limit of one');
            }
            $restoreConfig = $config->only(['display_name', 'max_cards_per_user', 'sort_order']);
            $restoreConfig['status'] = $config->status->value;
            $actor = AdminUser::where('email', 'owner@platform.local')->sole();
            app(ConfigureTenantCardProductAction::class)->execute($tenant, $productId, [...$restoreConfig, 'max_cards_per_user' => 2], $actor, $intent('limit-open'));
        }
        if (! $application) {
            $archive = app(CardholderTestMaterialsArchive::class)->read($tenant, $user, $productId);
            $data = $archive['fields'];
            $data['request_id'] = $intent('holder');
            $data['form_factor'] = 'virtual_card';
            foreach ($archive['documents'] as $side => $document) {
                if (! in_array($side, ['front', 'back'], true) || ! in_array($document['mime'], ['image/png', 'image/jpeg'], true)) {
                    throw new RuntimeException('Archived document format invalid');
                }
                $bytes = base64_decode($document['content'], true);
                if (! is_string($bytes) || $bytes === '') {
                    throw new RuntimeException('Archived document missing');
                }
                $data[$side] = UploadedFile::fake()->createWithContent($side.($document['mime'] === 'image/png' ? '.png' : '.jpg'), $bytes);
            }
            $application = app(SubmitProviderCardholderAction::class)->execute($tenant, $user, $data);
            unset($data, $archive, $bytes);
        }
        if ($application->status->value !== 'READY' && $application->provider_cardholder_id) {
            $application = app(SyncProviderCardholderAction::class)->execute($tenant, $user, $application->id);
        }
        $report['application'] = ['id' => $application->id, 'status' => $application->status->value];
        if ($application->status->value !== 'READY') {
            throw new RuntimeException('Holder not confirmed ready; stop');
        }
        if (Money::of($product->opening_fee, 'USDT')->compare(Money::of('5', 'USDT')) > 0
            || Money::of($product->minimum_initial_load, 'USDT')->compare(Money::of('20', 'USDT')) > 0) {
            throw new RuntimeException('Issuing budget exceeded');
        }
        $issue = app(CreateCardIssueAction::class)->execute($tenant, $user, $intent('issue'), $productId, '20.00', $application->id);
    }
    if ($issue && in_array($issue->status->value, ['PROCESSING', 'UNKNOWN'], true)) {
        $issue = app(SyncCardIssueAction::class)->execute($tenant, $issue->id, $user);
    }
    $report['application'] = $application ? ['id' => $application->id, 'status' => $application->status->value] : null;
    $report['issue'] = $issue ? ['id' => $issue->id, 'status' => $issue->status->value, 'initial_amount' => $issue->initial_load_amount, 'opening_fee' => $issue->opening_fee] : null;
    if (in_array($mode, ['--freeze', '--cancel'], true)) {
        if (! $issue || $issue->status->value !== 'SUCCEEDED') {
            throw new RuntimeException('New sandbox card must be confirmed first');
        }
        $card = UserCard::where('tenant_id', $tenant)->where('user_id', $user)->where('card_product_id', $productId)->where('provider_card_id', $issue->provider_card_id)->sole();
        $kind = strtoupper(substr($mode, 2));
        $existing = CardManagementOrder::where('tenant_id', $tenant)->where('user_id', $user)->where('request_id', $intent($kind))->first();
        if (! $existing && $kind === 'CANCEL') {
            $card = app(RefreshManagedCardAction::class)->execute($tenant, $user, $card->id);
            if (! Money::of($card->availableBalance(), 'USD')->isZero()) {
                throw new RuntimeException('Only cancel this new card after confirmed zero balance');
            }
        }
        $order = $existing ?? app(ManageCardAction::class)->operate($tenant, $user, $card->id, $intent($kind), $kind);
        if (in_array($order->status, ['PROCESSING', 'UNKNOWN'], true)) {
            $order = app(ManageCardAction::class)->sync($tenant, $order->id);
        }
        $report['operation'] = $order->only(['id', 'kind', 'status', 'amount', 'arrival_amount', 'fee_amount', 'settlement_entry_id']);
        $fresh = app(RefreshManagedCardAction::class)->execute($tenant, $user, $card->id);
        $report['card'] = ['id' => $fresh->id, 'status' => $fresh->provider_status, 'balance' => $fresh->availableBalance()];
        $report['result'] = $order->status === 'SUCCEEDED' ? 'PASS' : 'UNCONFIRMED_OR_FAILED';
    } else {
        $report['result'] = $issue?->status->value === 'SUCCEEDED' ? 'PASS' : 'NOT_CONFIRMED_ISSUED';
    }
} catch (Throwable $e) {
    $report['result'] = 'BLOCKED_OR_FAILED';
    $report['error_class'] = $e::class;
    if ($e instanceof DomainException) {
        $report['error_code'] = $e->errorCode;
    }
} finally {
    if ($restoreConfig !== null) {
        DB::transaction(function () use ($tenant, $productId, $restoreConfig, $actor, $intent, &$report) {
            CardProduct::whereKey($productId)->lockForUpdate()->firstOrFail();
            $current = TenantCardProductConfig::where('tenant_id', $tenant)->where('card_product_id', $productId)->lockForUpdate()->sole();
            if ($current->max_cards_per_user !== 2) {
                throw new RuntimeException('Card limit changed concurrently; manual review required');
            }
            $restored = $current->only(['display_name', 'sort_order']);
            $restored['status'] = $current->status->value;
            $restored['max_cards_per_user'] = $restoreConfig['max_cards_per_user'];
            app(ConfigureTenantCardProductAction::class)->execute($tenant, $productId, $restored, $actor, $intent('limit-restore'));
            $report['original_card_limit_restored'] = true;
        });
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($report['result'] === 'PASS' ? 0 : 1);
