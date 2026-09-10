<?php

namespace App\Providers;

use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\Enums\MockProviderMode;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Notification\Contracts\EmailVerificationSender;
use App\Domain\Notification\Contracts\SmsVerificationSender;
use App\Domain\Payment\Contracts\PaymentProviderInterface;
use App\Domain\Payment\Enums\MockPaymentMode;
use App\Domain\Tenant\Contracts\DomainVerificationService;
use App\Domain\Tenant\Repositories\TenantDomainRepository;
use App\Domain\Tenant\TenantContext;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Infrastructure\Auth\TenantUserProvider;
use App\Infrastructure\Mail\LaravelEmailVerificationSender;
use App\Infrastructure\Providers\Blockchain\MockBlockchainGateway;
use App\Infrastructure\Providers\Blockchain\UnavailableBlockchainGateway;
use App\Infrastructure\Providers\Card\MockCardProvider;
use App\Infrastructure\Providers\Card\PhotonPayCardProvider;
use App\Infrastructure\Providers\Card\PhotonPayCardResponseNormalizer;
use App\Infrastructure\Providers\Card\UnavailableCardProvider;
use App\Infrastructure\Providers\Domain\LocalDomainVerificationService;
use App\Infrastructure\Providers\Kyc\MockKycOcrProvider;
use App\Infrastructure\Providers\Kyc\UnavailableKycOcrProvider;
use App\Infrastructure\Providers\Payment\MockPaymentProvider;
use App\Infrastructure\Providers\Payment\UnavailablePaymentProvider;
use App\Infrastructure\Sms\FakeSmsVerificationSender;
use App\Infrastructure\Sms\UnavailableSmsVerificationSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->bind(DomainVerificationService::class, LocalDomainVerificationService::class);
        $this->app->bind(EmailVerificationSender::class, LaravelEmailVerificationSender::class);
        $this->app->singleton(SmsVerificationSender::class, fn () => app()->environment('testing')
            ? new FakeSmsVerificationSender
            : new UnavailableSmsVerificationSender);
        $this->app->singleton(PaymentProviderInterface::class, function (): PaymentProviderInterface {
            if (config('payment.driver') === 'mock' && app()->environment(['local', 'testing'])) {
                return new MockPaymentProvider(
                    MockPaymentMode::from((string) config('payment.mock_mode')),
                    (string) config('payment.mock_webhook_secret'),
                );
            }

            return new UnavailablePaymentProvider;
        });
        $this->app->singleton(BlockchainGatewayInterface::class, function (): BlockchainGatewayInterface {
            if (config('withdrawal.blockchain_driver') === 'mock' && app()->environment(['local', 'testing'])) {
                return new MockBlockchainGateway((string) config('withdrawal.mock_verification_mode'));
            }

            return new UnavailableBlockchainGateway;
        });

        $this->app->bind(CardProviderInterface::class, function (): CardProviderInterface {
            $driver = config('card-provider.driver');

            if ($driver === 'mock' && app()->environment(['local', 'testing'])) {
                return new MockCardProvider(
                    MockProviderMode::from((string) config('card-provider.mock_mode')),
                    (string) config('card-provider.mock_cardholder_mode'),
                );
            }
            if ($driver === 'photonpay') {
                $config = config('card-provider.photonpay');

                return new PhotonPayCardProvider(
                    (string) $config['base_url'],
                    (string) $config['app_id'],
                    (string) $config['app_secret'],
                    (string) $config['private_key'],
                    (string) $config['account_id_usd'],
                    filled($config['member_id']) ? (string) $config['member_id'] : null,
                    filled($config['matrix_account']) ? (string) $config['matrix_account'] : null,
                    (int) $config['timeout_seconds'],
                    new PhotonPayCardResponseNormalizer,
                );
            }

            return new UnavailableCardProvider;
        });
        $this->app->bind(KycOcrProviderInterface::class, function (): KycOcrProviderInterface {
            if (config('kyc.ocr_driver') === 'mock' && app()->environment(['local', 'testing'])) {
                return new MockKycOcrProvider((string) config('kyc.mock_ocr_mode'));
            }

            return new UnavailableKycOcrProvider;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('tenant-eloquent', fn ($app, array $config): TenantUserProvider => new TenantUserProvider(
            $app['hash'],
            $config['model'],
            $app->make(TenantContext::class),
        ));
        RateLimiter::for('kyc-documents', function ($request): Limit {
            $adminId = Auth::guard('tenant_admin')->id() ?? 'guest';
            $tenantId = app(TenantDomainRepository::class)
                ->resolveActiveHostname(strtolower(rtrim($request->getHost(), '.')))?->tenant_id ?? 'unknown';

            return Limit::perMinute((int) config('kyc.document_access_rate_limit_per_minute'))
                ->by("{$tenantId}:{$adminId}");
        });
        RateLimiter::for('wallet-topups', function (): Limit {
            $tenantId = app(TenantContext::class)->hasTenant() ? app(TenantContext::class)->id() : 'unknown';
            $userId = Auth::guard('tenant_user')->id() ?? 'guest';

            return Limit::perMinute((int) config('payment.topup_rate_limit_per_minute'))->by("{$tenantId}:{$userId}");
        });
        RateLimiter::for('security-deposit-funding', function (): Limit {
            $tenantId = app(TenantContext::class)->hasTenant() ? app(TenantContext::class)->id() : 'unknown';
            $userId = Auth::guard('tenant_user')->id() ?? 'guest';

            return Limit::perMinute((int) config('security-deposit.funding_rate_limit_per_minute'))->by("{$tenantId}:{$userId}");
        });
        RateLimiter::for('withdrawals', function (): Limit {
            $tenantId = app(TenantContext::class)->hasTenant() ? app(TenantContext::class)->id() : 'unknown';
            $userId = Auth::guard('tenant_user')->id() ?? 'guest';

            return Limit::perMinute((int) config('withdrawal.creation_rate_limit_per_minute'))->by("{$tenantId}:{$userId}");
        });
        RateLimiter::for('cards', function (): Limit {
            $tenantId = app(TenantContext::class)->hasTenant() ? app(TenantContext::class)->id() : 'unknown';
            $userId = Auth::guard('tenant_user')->id() ?? 'guest';

            return Limit::perMinute(10)->by("{$tenantId}:{$userId}");
        });
    }
}
