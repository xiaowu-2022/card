<?php

namespace App\Providers;

use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\Enums\MockProviderMode;
use App\Domain\Notification\Contracts\EmailVerificationSender;
use App\Domain\Notification\Contracts\SmsVerificationSender;
use App\Domain\Tenant\Contracts\DomainVerificationService;
use App\Domain\Tenant\TenantContext;
use App\Infrastructure\Auth\TenantUserProvider;
use App\Infrastructure\Mail\LaravelEmailVerificationSender;
use App\Infrastructure\Providers\Card\MockCardProvider;
use App\Infrastructure\Providers\Domain\LocalDomainVerificationService;
use App\Infrastructure\Sms\FakeSmsVerificationSender;
use App\Infrastructure\Sms\UnavailableSmsVerificationSender;
use Illuminate\Support\Facades\Auth;
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

        $this->app->bind(CardProviderInterface::class, function (): CardProviderInterface {
            $driver = config('card-provider.driver');

            if ($driver !== 'mock') {
                throw new RuntimeException("Card provider driver [{$driver}] is not installed.");
            }

            return new MockCardProvider(MockProviderMode::from((string) config('card-provider.mock_mode')));
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
    }
}
