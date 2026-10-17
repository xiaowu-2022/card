<?php

use App\Application\Assets\AssetOverviewQuery;
use App\Application\Wallet\UserWalletQuery;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed();
    Http::preventStrayRequests();
    $this->tenant = Tenant::where('slug', 'tenant-a')->sole();
    $this->user = User::where('tenant_id', $this->tenant->id)->where('email', 'user@a.localhost')->sole();
    $token = $this->postJson('http://a.localhost/api/mobile/v1/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])
        ->assertCreated()->json('token');
    $this->withToken($token);
});

it('publishes the complete native balance snapshot without waiting for activity or order history', function () {
    $legacy = app(UserWalletQuery::class)->get($this->tenant->id, $this->user->id, false);
    $full = app(AssetOverviewQuery::class)->get($this->tenant->id, $this->user->id, $legacy);
    $counts = [DB::table('wallets')->count(), DB::table('ledger_entries')->count(), DB::table('ledger_postings')->count()];
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });
    $result = $this->getJson('http://a.localhost/api/mobile/v1/home/summary')->assertOk()->json();
    expect($result['assetOverview']['estimate'])->toBe($full['estimate'])
        ->and($result['assetOverview']['updatedAt'])->toBe($full['updatedAt'])
        ->and($result['assetOverview']['activation'])->toBe($full['activation']);
    foreach ($full['assets'] as $index => $asset) {
        foreach (['asset', 'available', 'held', 'deposit', 'rails', 'transfer', 'exchange'] as $field) {
            expect($result['assetOverview']['assets'][$index][$field])->toBe($asset[$field]);
        }
        expect($result['assetOverview']['assets'][$index]['activity'])->toBe([])
            ->and($result['assetOverview']['assets'][$index]['orders'])->toBe([]);
    }
    foreach ($queries as $sql) {
        foreach (['asset_deposit_orders', 'asset_withdrawal_orders', 'asset_exchange_orders'] as $table) {
            expect($sql)->not->toContain($table);
        }
    }
    expect([DB::table('wallets')->count(), DB::table('ledger_entries')->count(), DB::table('ledger_postings')->count()])->toBe($counts);
    Http::assertNothingSent();
});

it('loads recent transactions independently with existing company and user evidence scope', function () {
    $expected = app(AssetOverviewQuery::class)->recentActivity($this->tenant->id, $this->user->id);
    $this->getJson('http://a.localhost/api/mobile/v1/home/activity?user_id=ignored&tenant_id=ignored')->assertOk()->assertExactJson(['activity' => $expected]);
    $this->getJson('http://b.localhost/api/mobile/v1/home/activity')->assertUnauthorized();
    Http::assertNothingSent();
});

it('requires authentication and operational company/user status for both sections', function () {
    $this->user->update(['status' => 'SUSPENDED']);
    $this->getJson('http://a.localhost/api/mobile/v1/home/summary')->assertForbidden();
    $this->getJson('http://a.localhost/api/mobile/v1/home/activity')->assertForbidden();
    $this->withHeader('Authorization', '');
    $this->getJson('http://a.localhost/api/mobile/v1/home/summary')->assertUnauthorized();
});

it('defers unread queries only when requested while retaining the legacy bootstrap contract', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });
    $this->getJson('http://a.localhost/api/mobile/v1/bootstrap?defer_unread=1')->assertOk()->assertJsonPath('unread', null);
    foreach ($queries as $sql) {
        expect($sql)->not->toContain('support_messages')->not->toContain('inbox_messages');
    }
    $this->getJson('http://a.localhost/api/mobile/v1/bootstrap')->assertOk()->assertJsonStructure(['unread' => ['messages', 'support', 'agentSupport']]);
    Http::assertNothingSent();
});
