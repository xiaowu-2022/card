<?php

use App\Domain\Admin\Models\AdminUser;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Infrastructure\Providers\Card\PhotonPayMerchantReport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->connection = Crypt::encryptString(json_encode(['base_url' => 'https://x-api.sandbox.photontech.cc', 'app_id' => 'report-id', 'app_secret' => 'report-secret']));
});

function fakeMerchantReport(string $accountBody, string $cardsBody): void
{
    Http::fake([
        '*/oauth2/token/accessToken' => Http::response(['code' => '0000', 'data' => ['token' => 'private-report-token', 'expiresIn' => (time() + 1200) * 1000]]),
        '*/wallet/openApi/v4/account/single*' => Http::response($accountBody),
        '*/vcc/openApi/v4/pagingVccCard*' => Http::response($cardsBody),
    ]);
}

it('preserves exact provider numeric balances scopes card totals and caches only sanitized data', function (): void {
    fakeMerchantReport('{"code":"0000","data":{"memberId":"merchant-1","currency":"USD","accountType":"FT10001","realTimeBalance":123456789012.12345678}}',
        '{"code":"0000","total":12,"pageIndex":1,"pageSize":1,"data":[{"cardNo":"sensitive-must-be-discarded","cvv":"never-expose"}]}');
    $report = app(PhotonPayMerchantReport::class)->read($this->connection);
    expect($report['balance'])->toBe('123456789012.12345678')->and($report['issuedCardCount'])->toBe('12')
        ->and(json_encode($report))->not->toContain('sensitive-must-be-discarded', 'never-expose', 'private-report-token');
    expect(app(PhotonPayMerchantReport::class)->read($this->connection))->toBe($report);
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/oauth2/token/accessToken') && $request->body() === '');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'pagingVccCard') && $request['memberId'] === 'merchant-1' && $request->hasHeader('X-PD-TOKEN', 'private-report-token') && ! isset($request['cardStatus']));
});

it('fails closed on invalid responses without exposing credentials or fallback values', function (string $account, string $cards): void {
    fakeMerchantReport($account, $cards);
    $report = app(PhotonPayMerchantReport::class)->read($this->connection);
    expect($report['balance'])->toBeNull()->and($report['issuedCardCount'])->toBeNull()->and($report['queriedAt'])->toBeNull();
})->with([
    ['{"code":"0000","data":{"currency":"EUR","accountType":"FT10001","realTimeBalance":"100","memberId":"m"}}', '{}'],
    ['{"code":"denied","msg":"private-report-token"}', '{}'],
    ['{"code":"0000","data":{"currency":"USD","accountType":"FT10002","realTimeBalance":"100","memberId":"m"}}', '{}'],
    ['{"code":"0000","data":{"currency":"USD","accountType":"FT10001","realTimeBalance":"100","memberId":"m"}}', '{"code":"0000","total":-1,"pageIndex":1,"pageSize":1,"data":[]}'],
]);

it('rejects arbitrary hosts before transmitting credentials', function (): void {
    $connection = Crypt::encryptString(json_encode(['base_url' => 'https://example.com', 'app_id' => 'report-id', 'app_secret' => 'report-secret']));
    expect(app(PhotonPayMerchantReport::class)->read($connection)['balance'])->toBeNull();
    Http::assertNothingSent();
});

it('makes bound balances read only while preserving rename routing and ledger history', function (): void {
    $owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($owner, 'platform_admin');
    $url = 'http://admin.localhost/platform/card-providers';
    $id = (string) Str::uuid();
    $this->post($url, ['name' => 'photonpay', 'request_id' => $id, 'reference_balance' => '1000'])->assertRedirect();
    $record = CardProviderReference::query()->findOrFail($id);
    $record->forceFill(['photonpay_reporting_encrypted' => $this->connection])->save();
    $entries = LedgerEntry::query()->count();
    $this->putJson($url.'/'.$id, ['name' => 'new name', 'version' => 1, 'reference_balance' => '1'])->assertUnprocessable();
    $this->put($url.'/'.$id, ['name' => 'new name', 'version' => 1])->assertRedirect();
    expect($record->fresh()->reference_balance)->toBe('1000.00000000')
        ->and($record->fresh()->runtime_driver)->toBe('UNCONFIGURED')
        ->and($record->fresh()->photonpay_reporting_encrypted)->toBe($this->connection)
        ->and($record->fresh()->toArray())->not->toHaveKey('photonpay_reporting_encrypted')
        ->and(LedgerEntry::query()->count())->toBe($entries);
    Http::assertNothingSent();
    fakeMerchantReport('{"code":"0000","data":{"memberId":"m","currency":"USD","accountType":"FT10001","realTimeBalance":100000}}',
        '{"code":"0000","total":0,"pageIndex":1,"pageSize":1,"data":[]}');
    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('providers.data.0.referenceBalance', '100000.00000000')
        ->where('providers.data.0.issuedCardCount', '0')
        ->where('providers.data.0.apiConnected', true)
        ->missing('providers.data.0.photonpay_reporting_encrypted'));
});
