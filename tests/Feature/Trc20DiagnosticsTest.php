<?php

use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Infrastructure\Providers\Blockchain\TronGridBlockchainGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['payment.trc20_deposit_address' => TronGridBlockchainGateway::TOKEN]);
    $this->app->instance(BlockchainGatewayInterface::class, new TronGridBlockchainGateway);
});

it('reports effective TRC20 configuration and cooldown without exposing credentials or touching scan progress', function () {
    $this->travelTo(now()->startOfSecond());
    config(['payment.trongrid_api_key' => 'synthetic-diagnostics-key-only']);
    $until = now()->timestamp + 180;
    Cache::put('trc20:trongrid:backoff:v1:until', $until, 180);
    $cursor = ['id' => hash('sha256', 'TRON:USDT:'.TronGridBlockchainGateway::TOKEN),
        'started_at' => now()->subDay(), 'scanned_through' => now()->subHour()];
    DB::table('trc20_scan_cursors')->insert($cursor);
    $before = DB::table('trc20_scan_cursors')->get()->toJson();
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    expect(Artisan::call('topups:diagnose-trc20'))->toBe(0);
    $output = Artisan::output();
    $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    expect($result['api_key_configured'])->toBeTrue()->and($result['api_key_format_valid'])->toBeTrue()
        ->and($result['cooldown_remaining_seconds'])->toBe(180)
        ->and($result['provider_contacted'])->toBeFalse()->and($result['provider_authentication_verified'])->toBeFalse()
        ->and($result['gateway_revision'])->toBe(TronGridBlockchainGateway::RUNTIME_REVISION)
        ->and($result['unfinished_orders'])->toBe(0)
        ->and($result['scan_cursors'])->toHaveCount(1)
        ->and($output)->not->toContain('synthetic-diagnostics-key-only', TronGridBlockchainGateway::TOKEN)
        ->and(DB::table('trc20_scan_cursors')->get()->toJson())->toBe($before)
        ->and(Cache::get('trc20:trongrid:backoff:v1:until'))->toBe($until)
        ->and($writes)->toBe([]);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('identifies anonymous or malformed configuration without bootstrapping a cursor', function (string $key, bool $configured, ?bool $valid) {
    config(['payment.trongrid_api_key' => $key]);
    expect(Artisan::call('topups:diagnose-trc20'))->toBe(0);
    $output = Artisan::output();
    $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    expect($result['api_key_configured'])->toBe($configured)->and($result['api_key_format_valid'])->toBe($valid)
        ->and(DB::table('trc20_scan_cursors')->count())->toBe(0);
    if ($key !== '') {
        expect($output)->not->toContain($key);
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([['', false, null], ["invalid\nheader", true, false]]);
