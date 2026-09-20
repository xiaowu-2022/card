<?php

use App\Application\Assets\MarketPrices;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\MarketSettings;
use App\Domain\Assets\MarketSnapshot;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
});

it('keeps saved rates visible to platform settings without making stale or disabled prices spendable', function (int $age, bool $enabled, bool $fresh) {
    $this->freezeTime();
    MarketSettings::findOrFail(1)->update(['enabled' => $enabled]);
    $snapshot = MarketSnapshot::create(['provider' => 'COINGECKO',
        'usd_prices' => ['USDT' => '0.999', 'USDC' => '0.998', 'ETH' => '2000', 'BTC' => '60000'],
        'observed_at' => now()->subSeconds($age)]);
    Http::fake();
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($actor, 'platform_admin')->get('http://admin.localhost/platform/settings/assets')
        ->assertOk()->assertInertia(fn ($page) => $page
            ->where('market.enabled', true)
            ->where('market.snapshot.fresh', $fresh)
            ->where('market.snapshot.observed_at', $snapshot->fresh()->observed_at->toIso8601String())
            ->where('market.snapshot.rates.ETH', (string) app(MarketPrices::class)->rate($snapshot, 'ETH'))
            ->missing('market.snapshot.usd_prices'));
    expect(app(MarketPrices::class)->latest() !== null)->toBe($fresh);
    Http::assertNothingSent();
})->with([[0, true, true], [120, true, true], [121, true, false], [0, false, true], [121, false, false]]);

it('distinguishes absent platform rates without fetching prices from a settings read', function () {
    Http::fake();
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($actor, 'platform_admin')->get('http://admin.localhost/platform/settings/assets')
        ->assertOk()->assertInertia(fn ($page) => $page->where('market.snapshot', null));
    Http::assertNothingSent();
});
