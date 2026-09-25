<?php

use App\Application\Assets\AssetOverviewQuery;
use App\Application\Assets\AssetRails;
use App\Domain\Assets\AssetRail;
use App\Domain\Assets\ChainConnection;
use App\Domain\Assets\CompanyRail;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Assets\ChainRpc;
use App\Infrastructure\Assets\PublicChainNodes;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Http;

it('exposes configured withdrawals with disabled uninitialized deposit scanners using public reads', function (string $code) {
    $this->seed();
    Http::preventStrayRequests();
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $user = User::where('tenant_id', $tenant->id)->firstOrFail();
    $rail = AssetRail::findOrFail($code);
    $rail->update(['enabled' => true, 'deposit_address' => $rail->network === 'BITCOIN' ? '1BoatSLRHtKNngkdXEeobR76b53LETtpyT' : '0x'.str_repeat('1', 40)]);
    CompanyRail::updateOrCreate(['tenant_id' => $tenant->id, 'rail_code' => $code], ['withdrawal_enabled' => true, 'withdrawal_fee_percent' => '0', 'deposit_enabled' => false]);
    $stored = ChainConnection::findOrFail($rail->network);
    $stored->update(['enabled' => false, 'start_height' => null, 'next_height' => null, 'credential' => ['api_key' => 'must-not-leak']]);
    $before = $stored->fresh()->getRawOriginal();
    $overview = app(AssetOverviewQuery::class)->get($tenant->id, $user->id, ['transferAvailable' => true]);
    $asset = collect($overview['assets'])->firstWhere('asset', $rail->asset_code);
    expect(collect($asset['rails'])->firstWhere('code', $code)['withdrawal'])->toBeTrue();
    Http::assertNothingSent();
    [, , $connection] = app(AssetRails::class)->enabled($tenant->id, $code, 'withdrawal');
    expect($connection->enabled)->toBeTrue()->and($connection->credential)->toBeNull()
        ->and($connection->rpc_url)->toBe(PublicChainNodes::URLS[$rail->network]);
    Http::fake([PublicChainNodes::URLS[$rail->network] => Http::sequence()->push(['result' => '0x1'])->push([], 503)]);
    expect(app(ChainRpc::class)->call($connection, 'eth_chainId'))->toBe('0x1');
    Http::assertSent(fn ($request) => ! $request->hasHeader('X-API-Key') && ! $request->hasHeader('Authorization'));
    expect(fn () => app(ChainRpc::class)->call($connection, 'eth_chainId'))->toThrow(DomainException::class);
    expect($stored->fresh()->getRawOriginal())->toBe($before);
    CompanyRail::where('tenant_id', $tenant->id)->where('rail_code', $code)->update(['withdrawal_enabled' => false]);
    expect(fn () => app(AssetRails::class)->enabled($tenant->id, $code, 'withdrawal'))->toThrow(DomainException::class);
})->with(['USDT_ETHEREUM', 'USDC_ETHEREUM', 'ETH_ETHEREUM', 'BTC_BITCOIN']);
