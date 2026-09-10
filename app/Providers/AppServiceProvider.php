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
use App\Infrastructure\Auth\TenantUserProvider;
use App\Infrastructure\Mail\LaravelEmailVerificationSender;
use App\Infrastructure\Providers\Card\MockCardProvider;
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
use RuntimeException;

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

        $this->app->bind(CardProviderInterface::class, function (): CardProviderInterface {
            $driver = config('card-provider.driver');

            if ($driver !== 'mock') {
                throw new RuntimeException("Card provider driver [{$driver}] is not installed.");
            }

            return new MockCardProvider(MockProviderMode::from((string) config('card-provider.mock_mode')));
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
    }
}
