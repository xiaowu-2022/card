<?php

namespace App\Providers;

use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\Enums\MockProviderMode;
use App\Domain\Tenant\TenantContext;
use App\Infrastructure\Providers\Card\MockCardProvider;
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
        //
    }
}
