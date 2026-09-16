<?php

use Database\Seeders\LocalPromotionFixtureSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('retires unpaid fixture assignment after paid promotion cutover without changing history', function () {
    $this->seed();
    Http::preventStrayRequests();
    $before = [];
    foreach (['ledger_entries', 'ledger_postings', 'ledger_accounts', 'users', 'promotion_members'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    expect(fn () => app(LocalPromotionFixtureSeeder::class)->run('unused', 'unused', 'unused', 10))->toThrow(HttpException::class);
    foreach ($before as $table => $snapshot) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($snapshot);
    }
    Http::assertNothingSent();
});

it('denies promotion fixture generation outside isolated local databases', function () {
    $this->app->instance('env', 'production');
    expect(fn () => app(LocalPromotionFixtureSeeder::class)->run('unused', 'unused', 'unused', 10))->toThrow(HttpException::class);
});
