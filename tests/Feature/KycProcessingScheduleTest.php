<?php

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-10-30T12:00:00+08:00'));
    Http::preventStrayRequests();
    Queue::fake();
    $this->tenant = Tenant::where('slug', 'tenant-a')->sole();
    $this->admin = AdminUser::where('email', 'owner@platform.local')->sole();
    $provider = Mockery::mock(KycOcrProviderInterface::class);
    $provider->shouldNotReceive('extractIdentityDocument');
    app()->instance(KycOcrProviderInterface::class, $provider);
});

function scheduleKycApplication($test, string $reviewStatus, ?string $processingStatus, ?CarbonImmutable $due, ?Tenant $tenant = null): KycApplication
{
    $tenant ??= $test->tenant;
    $user = User::create(['tenant_id' => $tenant->id, 'email' => 'kyc-schedule-'.Str::uuid().'@example.test',
        'password_hash' => Hash::make('synthetic-password'), 'status' => 'ACTIVE']);
    $application = new KycApplication;
    $application->forceFill([
        'tenant_id' => $tenant->id, 'user_id' => $user->id, 'document_type' => 'PASSPORT', 'document_country' => 'CN',
        'identity_number_encrypted' => app(KycDataCipher::class)->encrypt('E12345678'), 'identity_hash' => hash('sha256', $user->id),
        'front_object_key' => 'kyc/'.$tenant->id.'/'.$user->id.'/synthetic-front', 'back_object_key' => null,
        'ocr_status' => 'NOT_STARTED', 'review_status' => $reviewStatus, 'processing_status' => $processingStatus,
        'processing_generation' => 1, 'next_processing_at' => $due, 'submitted_at' => now()->subDay(),
        'reviewed_at' => $reviewStatus === 'PENDING' ? null : now()->subHour(),
        'reviewed_by_admin_user_id' => $reviewStatus === 'PENDING' ? null : $test->admin->id,
        'review_reason_code' => in_array($reviewStatus, ['REJECTED', 'RESUBMISSION_REQUIRED'], true) ? 'OTHER' : null,
        'review_message' => in_array($reviewStatus, ['REJECTED', 'RESUBMISSION_REQUIRED'], true) ? 'Synthetic prior review' : null,
        'automatically_approved' => false,
    ])->save();

    return $application->refresh();
}

/** Busy per-application locks stop the final processor before any OCR, storage or business writes. */
function recordScheduledKycAttempts(array &$attempts): void
{
    $busy = Mockery::mock(Lock::class);
    $busy->shouldReceive('get')->andReturnFalse();
    $busy->shouldNotReceive('release');
    Cache::shouldReceive('lock')->andReturnUsing(function (string $key, int $seconds) use (&$attempts, $busy) {
        expect($key)->toStartWith('kyc-processing:')->and($seconds)->toBe(600);
        $attempts[] = substr($key, strlen('kyc-processing:'));

        return $busy;
    });
}

it('starts exactly one bounded KYC event before synchronous scans and runs it in the background with its original guards', function (): void {
    // Read definitions only: never execute schedule:run or an event shell command.
    $events = collect(app(Schedule::class)->events());
    $kyc = $events->filter(fn ($event) => str_contains($event->command ?? '', 'kyc:process-pending'));
    expect($kyc)->toHaveCount(1);
    $event = $kyc->sole();
    expect($events->first())->toBe($event)
        ->and($event->command)->toMatch('/(?:^|\s)--limit=20(?:\s|$)/')
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(30)
        ->and($event->onOneServer)->toBeTrue();
    foreach (['payments:recover', 'topups:scan-trc20', 'assets:scan ETHEREUM', 'assets:scan BITCOIN', 'images:replicate --limit=20'] as $command) {
        expect($events->filter(fn ($scheduled) => str_contains($scheduled->command ?? '', $command)))->toHaveCount(1);
    }
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('selects only due retained queued or recoverable processing applications and leaves every record unchanged when locked', function (): void {
    $now = CarbonImmutable::instance(now());
    $other = Tenant::where('slug', 'tenant-b')->sole();
    $queued = scheduleKycApplication($this, 'PENDING', 'QUEUED', $now->subMinutes(2));
    $processing = scheduleKycApplication($this, 'PENDING', 'PROCESSING', $now->subMinute(), $other);
    $boundary = scheduleKycApplication($this, 'PENDING', 'QUEUED', $now);
    foreach ([
        ['APPROVED', 'COMPLETE', $now->subHour()], ['REJECTED', 'FAILED', $now->subHour()],
        ['RESUBMISSION_REQUIRED', 'FAILED', $now->subHour()], ['PENDING', 'FAILED', $now->subHour()],
        ['PENDING', 'WAITING_REVIEW', $now->subHour()], ['PENDING', null, $now->subHour()],
        ['PENDING', 'QUEUED', $now->addSecond()], ['PENDING', 'PROCESSING', $now->addMinute()],
        ['PENDING', 'QUEUED', null],
    ] as [$review, $processingStatus, $due]) scheduleKycApplication($this, $review, $processingStatus, $due);
    $before = KycApplication::orderBy('id')->get()->map(fn ($application) => $application->getAttributes())->all();
    $counts = [DB::table('audit_logs')->count(), DB::table('identity_records')->count(), DB::table('inbox_events')->count()];
    $attempts = [];
    recordScheduledKycAttempts($attempts);
    $this->artisan('kyc:process-pending')->assertSuccessful();
    expect($attempts)->toBe([$queued->id, $processing->id, $boundary->id])
        ->and(KycApplication::orderBy('id')->get()->map(fn ($application) => $application->getAttributes())->all())->toBe($before)
        ->and([DB::table('audit_logs')->count(), DB::table('identity_records')->count(), DB::table('inbox_events')->count()])->toBe($counts);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('keeps the default twenty item batch and respects an explicit smaller limit in due order', function (?int $limit, int $expectedCount): void {
    $now = CarbonImmutable::instance(now());
    $expected = [];
    foreach (range(1, 25) as $index) {
        $expected[] = scheduleKycApplication($this, 'PENDING', $index % 2 === 0 ? 'PROCESSING' : 'QUEUED', $now->subMinutes(26 - $index))->id;
    }
    $attempts = [];
    recordScheduledKycAttempts($attempts);
    $this->artisan('kyc:process-pending', $limit === null ? [] : ['--limit' => $limit])->assertSuccessful();
    expect($attempts)->toBe(array_slice($expected, 0, $expectedCount));
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['default twenty' => [null, 20], 'explicit smaller batch' => [2, 2]]);
