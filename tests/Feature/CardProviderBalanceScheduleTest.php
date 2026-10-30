<?php

use App\Application\CardProviderDirectory\DeleteCardProviderReferenceAction;
use App\Application\CardProviderDirectory\SaveCardProviderReferenceAction;
use App\Application\CardProviderDirectory\U1Accounts;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Providers\Card\U1ApiClient;
use App\Jobs\RefreshPhotonPayAccountReport;
use App\Jobs\RefreshU1AccountReport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    Queue::fake();
    Http::preventStrayRequests();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->createReference = function (bool $photonpay = false): CardProviderReference {
        $reference = app(SaveCardProviderReferenceAction::class)->execute(null, [
            'name' => 'Offline report '.Str::uuid(), 'reference_balance' => '12.34', 'request_id' => (string) Str::uuid(),
        ], $this->owner);
        if ($photonpay) {
            $reference->forceFill(['photonpay_reporting_encrypted' => Crypt::encryptString(json_encode([
                'base_url' => 'https://x-api.sandbox.photontech.cc', 'app_id' => 'offline-report-id', 'app_secret' => 'offline-report-secret',
            ], JSON_THROW_ON_ERROR))])->save();
        }

        return $reference;
    };
    $this->createU1 = function (string $status = 'VERIFIED', bool $enabled = true): object {
        $reference = app(U1Accounts::class)->save(null, [
            'name' => 'Offline U1 '.Str::uuid(), 'customer_no' => 'OFFLINE-'.Str::uuid(),
            'api_key' => 'offline-schedule-key', 'base_url' => U1Accounts::BASE_URL,
            'enabled' => $enabled, 'request_id' => (string) Str::uuid(),
        ], $this->owner);
        DB::table('u1_connections')->where('reference_id', $reference->id)->update([
            'check_status' => $status,
            'account_report' => json_encode(['balance' => '12.34000000', 'asset' => 'USD', 'issuedCardCount' => '2', 'queriedAt' => now()->subMinutes(10)->toIso8601String()], JSON_THROW_ON_ERROR),
        ]);

        return DB::table('u1_connections')->where('reference_id', $reference->id)->firstOrFail();
    };
    $this->removeReference = function (string $id): void {
        app(DeleteCardProviderReferenceAction::class)->execute($id, [
            'version' => CardProviderReference::findOrFail($id)->version, 'confirmed' => true, 'request_id' => (string) Str::uuid(),
        ], $this->owner);
    };
    $this->financialSnapshot = fn () => collect([
        'ledger_accounts', 'ledger_entries', 'ledger_postings', 'wallets', 'user_cards',
        'card_issue_orders', 'card_management_orders', 'u1_operations',
    ])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
});

it('queues exactly current connected provider reports without network or financial writes', function (string $configuredConnection, string $expectedConnection): void {
    config(['queue.default' => $configuredConnection]);
    $photonpay = ($this->createReference)(true);
    $secondPhotonpay = ($this->createReference)(true);
    ($this->createReference)();
    $deletedPhotonpay = ($this->createReference)(true);
    ($this->removeReference)($deletedPhotonpay->id);
    $activeU1 = ($this->createU1)();
    $pausedU1 = ($this->createU1)('VERIFIED', false);
    ($this->createU1)('UNCHECKED');
    ($this->createU1)('FAILED');
    $deletedU1 = ($this->createU1)();
    ($this->removeReference)($deletedU1->reference_id);
    $money = ($this->financialSnapshot)();
    $references = DB::table('platform_card_provider_references')->orderBy('id')->get()->toJson();
    $connections = DB::table('u1_connections')->orderBy('id')->get()->toJson();

    // Credential-save observers queue initial reports independently of the scheduler.
    Queue::fake();
    $this->artisan('cards:refresh-provider-reports')->assertSuccessful();

    Queue::assertCount(4);
    Queue::assertPushed(RefreshPhotonPayAccountReport::class, 2);
    Queue::assertPushed(RefreshU1AccountReport::class, 2);
    expect(Queue::pushed(RefreshPhotonPayAccountReport::class)->pluck('referenceId')->sort()->values()->all())
        ->toBe(collect([$photonpay->id, $secondPhotonpay->id])->sort()->values()->all());
    expect(Queue::pushed(RefreshU1AccountReport::class)->pluck('connectionId')->sort()->values()->all())
        ->toBe(collect([$activeU1->id, $pausedU1->id])->sort()->values()->all());
    foreach ([RefreshPhotonPayAccountReport::class, RefreshU1AccountReport::class] as $jobClass) {
        foreach (Queue::pushed($jobClass) as $job) {
            expect($job->connection)->toBe($expectedConnection)->and($job->afterCommit)->toBeTrue();
        }
    }
    expect(($this->financialSnapshot)())->toBe($money)
        ->and(DB::table('platform_card_provider_references')->orderBy('id')->get()->toJson())->toBe($references)
        ->and(DB::table('u1_connections')->orderBy('id')->get()->toJson())->toBe($connections);
    Http::assertNothingSent();
})->with([
    'sync uses an asynchronous database queue' => ['sync', 'database'],
    'configured asynchronous queue is retained' => ['redis', 'redis'],
]);

it('schedules provider report refresh every five minutes with overlap and single server guards', function (): void {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'cards:refresh-provider-reports'));

    expect($events)->toHaveCount(1);
    $event = $events->sole();
    expect($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(5)
        ->and($event->onOneServer)->toBeTrue();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('rechecks U1 verification and current directory membership before a queued report request', function (string $revocation): void {
    $connection = ($this->createU1)();
    $this->artisan('cards:refresh-provider-reports')->assertSuccessful();
    $job = Queue::pushed(RefreshU1AccountReport::class)->sole();
    if ($revocation === 'DELETED') {
        ($this->removeReference)($connection->reference_id);
    } else {
        DB::table('u1_connections')->where('id', $connection->id)->update(['check_status' => $revocation]);
    }
    $before = DB::table('u1_connections')->where('id', $connection->id)->firstOrFail();
    $money = ($this->financialSnapshot)();

    $job->handle(app(U1Accounts::class));

    expect(DB::table('u1_connections')->where('id', $connection->id)->firstOrFail())->toEqual($before)
        ->and(($this->financialSnapshot)())->toBe($money);
    Http::assertNothingSent();
})->with(['UNCHECKED', 'FAILED', 'DELETED']);

it('discards an in flight U1 report after credentials verification or directory membership changes', function (string $change): void {
    $connection = ($this->createU1)();
    $money = ($this->financialSnapshot)();
    $expectedReport = $connection->account_report;
    Http::fake(function ($request) use ($connection, $change, &$expectedReport) {
        expect($request->url())->toBe(U1ApiClient::V2_BASE_URL.'/api/queryaccount');
        if ($change === 'DELETED') {
            ($this->removeReference)($connection->reference_id);
        } else {
            $values = ['check_status' => 'UNCHECKED', 'account_report' => null];
            if ($change === 'CREDENTIALS') {
                $values['api_key_encrypted'] = Crypt::encryptString('rotated-offline-schedule-key');
            }
            DB::table('u1_connections')->where('id', $connection->id)->update($values);
            $expectedReport = null;
        }

        return Http::response([
            'resultCode' => '0000', 'timestamp' => $request['timestamp'],
            'sign' => md5('0000'.$request['timestamp'].'offline-schedule-key'),
            'data' => ['amount' => [['currency' => 'USD', 'freeAmt' => '999.12345678']], 'cardCount' => '9'],
        ]);
    });

    (new RefreshU1AccountReport($connection->id))->handle(app(U1Accounts::class));

    expect(DB::table('u1_connections')->where('id', $connection->id)->value('account_report'))->toBe($expectedReport)
        ->and(($this->financialSnapshot)())->toBe($money);
    Http::assertSentCount(1);
})->with(['CREDENTIALS', 'VERIFICATION', 'DELETED']);
