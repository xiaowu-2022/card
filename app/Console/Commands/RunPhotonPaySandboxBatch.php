<?php

namespace App\Console\Commands;

use App\Application\Card\CreateCardIssueAction;
use App\Application\Card\SyncCardIssueAction;
use App\Application\Tenant\CompanyConfigurationAuthority;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Providers\Card\PhotonPayCardProvider;
use App\Infrastructure\Providers\Card\PhotonPayCardResponseNormalizer;
use App\Infrastructure\Providers\Card\PhotonPayMerchantReport;
use App\Support\Errors\DomainException;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;

/** Explicit, local-only acceptance run. Re-running preserves each order identity. */
final class RunPhotonPaySandboxBatch extends Command
{
    protected $signature = 'cards:photonpay-sandbox-batch {--execute} {--attempt=0} {--bin=}';

    protected $description = 'Verify and run the user-authorized Tenant A PhotonPay sandbox batch';

    public function handle(): int
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'card_mock') {
            $this->error('This acceptance run is restricted to local card_mock.');

            return self::FAILURE;
        }
        try {
            Event::listen(ResponseReceived::class, function ($event) {
                $data = json_decode($event->response->body(), true);
                if (is_array($data) && isset($data['code']) && $data['code'] !== '0000') {
                    $message = strtolower((string) ($data['message'] ?? $data['msg'] ?? $data['errorMsg'] ?? ''));
                    $this->line(json_encode(['endpoint' => parse_url($event->request->url(), PHP_URL_PATH), 'providerCode' => preg_replace('/[^A-Za-z0-9:_-]/', '', (string) $data['code']),
                        'categories' => array_values(array_filter(['sign', 'token', 'amount', 'balance', 'permission', 'cardholder', 'parameter', 'scheme', 'currency', 'required', 'empty', 'invalid', 'limit', 'bin', 'support', 'not', 'exist', '签', '金额', '权限', '参数', '卡组织', '币种', '不能为空', '限额', '不支持', '不存在'], fn ($word) => str_contains($message, $word)))]));
                }
            });
            $tenant = Tenant::query()->whereKey('01a09996-8c36-7288-bf98-889103088ba6')->where('name', 'Tenant A')->sole();
            $user = User::query()->where('tenant_id', $tenant->id)->where('account_id', '202609131303')->sole();
            $actor = AdminUser::query()->where('email', 'owner@platform.local')->sole();
            app(CompanyConfigurationAuthority::class)->assert($actor);
            $merchant = CardProviderReference::query()->findOrFail('044a22d6-c487-4604-bc89-1760b9fb79f8');
            $env = Dotenv::parse(file_get_contents(base_path('.env.example')));
            $reporting = json_decode(Crypt::decryptString($merchant->photonpay_reporting_encrypted), true, 512, JSON_THROW_ON_ERROR);
            if ($env['PHOTONPAY_BASE_URL'] !== 'https://x-api.sandbox.photontech.cc' || $reporting['base_url'] !== $env['PHOTONPAY_BASE_URL']
                || $reporting['app_id'] !== $env['PHOTONPAY_APP_ID'] || $reporting['app_secret'] !== $env['PHOTONPAY_APP_SECRET']) {
                throw new \RuntimeException('Account mismatch');
            }
            $http = fn () => Http::connectTimeout(5)->timeout(20)->withoutRedirecting();
            $decode = function ($response): array {
                $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
                if (! $response->successful() || ($data['code'] ?? null) !== '0000') {
                    throw new \RuntimeException('Provider verification failed');
                }

                return $data;
            };
            $auth = $decode($http()->withHeaders(['Authorization' => 'basic '.base64_encode($reporting['app_id'].'/'.$reporting['app_secret'])])
                ->withBody('', 'application/json')->post($reporting['base_url'].'/oauth2/token/accessToken'));
            $account = $decode($http()->withHeaders(['X-PD-TOKEN' => $auth['data']['token']])->get($reporting['base_url'].'/wallet/openApi/v4/account/single', ['currency' => 'USD', 'accountType' => 'FT10001']))['data'];
            if (($account['accountNo'] ?? null) !== $env['PHOTONPAY_USD_ACCOUNT_ID'] || ! is_string($account['memberId'] ?? null)) {
                throw new \RuntimeException('USD account mismatch');
            }
            $connection = $reporting + ['private_key' => $env['PHOTONPAY_PRIVATE_KEY'], 'account_id' => $env['PHOTONPAY_USD_ACCOUNT_ID'], 'member_id' => $account['memberId']];
            $probe = new PhotonPayCardProvider($connection['base_url'], $connection['app_id'], $connection['app_secret'], $connection['private_key'], $connection['account_id'], $connection['member_id'], null, 20, new PhotonPayCardResponseNormalizer);
            $holderId = 'CH2096144404451819520';
            $verified = $probe->getCardholder($holderId);
            if ($verified->providerCardholderId !== $holderId || $verified->status->value !== 'READY' || $verified->providerStatus !== 'normal') {
                throw new \RuntimeException('Holder not available');
            }
            $bins = app(PhotonPayMerchantReport::class)->bins($merchant->photonpay_reporting_encrypted, true);
            if (! is_array($bins) || count($bins) !== 11) {
                throw new \RuntimeException('Catalog changed');
            }
            $this->info('Verified sandbox account, normal holder and 11 eligible BINs.');
            if (! $this->option('execute')) {
                return self::SUCCESS;
            }
            DB::transaction(function () use ($merchant, $connection, $actor) {
                $locked = CardProviderReference::query()->whereKey($merchant->id)->lockForUpdate()->firstOrFail();
                if ($locked->photonpay_issuing_encrypted !== null) {
                    if (json_decode(Crypt::decryptString($locked->photonpay_issuing_encrypted), true) !== $connection) {
                        throw new \RuntimeException('Connection changed');
                    }

                    return;
                }
                $locked->forceFill(['photonpay_issuing_encrypted' => Crypt::encryptString(json_encode($connection, JSON_THROW_ON_ERROR))])->save();
                app(AuditLogger::class)->record(null, 'ADMIN', $actor->id, 'PHOTONPAY_SANDBOX_ISSUING_CONFIGURED', 'card_provider_reference', $locked->id, null, ['sandbox' => true]);
            });
            $allSucceeded = true;
            foreach ($bins as $bin) {
                if ($this->option('bin') && $this->option('bin') !== $bin['bin']) {
                    continue;
                }
                $product = CardProduct::query()->where('card_provider_reference_id', $merchant->id)->where('provider_product_ref', $bin['bin'])->sole();
                $requestId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'photonpay-sandbox-20260914/'.$tenant->id.'/'.$user->id.'/'.$product->id)->toString();
                $previous = CardIssueOrder::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('card_product_id', $product->id)->get();
                $completed = $previous->first(fn ($row) => $row->status->value === 'SUCCEEDED');
                if ($completed) {
                    $this->line(json_encode(['bin' => $bin['bin'], 'status' => 'SUCCEEDED', 'cardId' => $completed->provider_card_id, 'replayed' => true]));

                    continue;
                }
                if ((int) $this->option('attempt') > 0) {
                    $requestId = Uuid::uuid5(Uuid::NAMESPACE_URL, $requestId.'/retry/'.(int) $this->option('attempt'))->toString();
                    if ($previous->isEmpty() || $previous->contains(fn ($row) => $row->request_id !== $requestId && $row->status->value !== 'FAILED')) {
                        throw new \RuntimeException('Unresolved prior attempt');
                    }
                }
                $application = DB::transaction(function () use ($tenant, $user, $product, $holderId, $verified, $requestId, $actor) {
                    $existing = ProviderCardholder::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('request_id', $requestId)->first();
                    if ($existing) {
                        return $existing;
                    }
                    $holder = new ProviderCardholder;
                    $holder->forceFill(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'provider' => 'PHOTONPAY', 'provider_cardholder_id' => $holderId,
                        'status' => 'READY', 'provider_status' => $verified->providerStatus, 'provider_review_status' => $verified->providerReviewStatus,
                        'request_id' => $requestId, 'request_hash' => hash('sha256', $requestId.'|'.$holderId), 'card_product_id' => $product->id,
                        'submission_version' => 1, 'sandbox_existing_holder' => true, 'submitted_at' => now(), 'synced_at' => now()])->save();
                    app(AuditLogger::class)->record($tenant->id, 'ADMIN', $actor->id, 'SANDBOX_EXISTING_HOLDER_VERIFIED', 'provider_cardholder', $holder->id, null, ['card_product_id' => $product->id, 'sandbox' => true]);

                    return $holder;
                });
                $order = app(CreateCardIssueAction::class)->execute($tenant->id, $user->id, $requestId, $product->id, '20.00', $application->id);
                if (in_array($order->status->value, ['PROCESSING', 'UNKNOWN'], true)) {
                    $order = app(SyncCardIssueAction::class)->execute($tenant->id, $order->id, $user->id);
                }
                $this->line(json_encode(['bin' => $bin['bin'], 'orderId' => $order->id, 'status' => $order->status->value, 'cardId' => $order->provider_card_id]));
                $allSucceeded = $allSucceeded && $order->status->value === 'SUCCEEDED';
                if (in_array($order->status->value, ['UNKNOWN', 'PROCESSING'], true)) {
                    $this->warn('Unconfirmed order retained; stopped for reconciliation.');

                    return self::FAILURE;
                }
            }

            return $allSucceeded ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            if ($exception instanceof DomainException) {
                $this->error($exception->errorCode);
            }
            $this->error('Batch stopped: '.get_class($exception).'. No automatic retry or balance adjustment.');

            return self::FAILURE;
        }
    }
}
