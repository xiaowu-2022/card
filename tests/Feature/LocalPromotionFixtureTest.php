<?php

use App\Application\Promotion\CommissionHistoryQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Promotion\Models\CommissionAward;
use App\Domain\Promotion\Models\PromotionFundingEvent;
use App\Domain\Promotion\Models\PromotionMember;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Database\Seeders\LocalPromotionFixtureSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('creates dated local promotion fixtures through deposit accounting and replays without duplication', function () {
    $this->seed();
    Http::preventStrayRequests();
    Storage::fake('private');
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $root = User::query()->where('tenant_id', $tenant->id)->firstOrFail();
    $actorId = DB::table('admin_memberships')->where('scope_type', 'PLATFORM')->where('status', 'ACTIVE')->value('admin_user_id');
    $actor = AdminUser::query()->findOrFail($actorId);
    $runner = app(LocalPromotionFixtureSeeder::class);
    $report = $runner->run($tenant->id, $root->id, $actor->id, 10);
    expect($report['totals']['invited'])->toBe(10)->and($report['totals']['activated'])->toBe(6)
        ->and($report['totals']['deposits'])->toBe('600.00000000')->and($report['totals']['commission'])->toBe('720.00000000')
        ->and($report['daily']['commission'])->toBe('0')->and($report['daily']['invited'])->toBe(0)->and($report['levelName'])->toBe('120');
    $member = PromotionMember::query()->where('tenant_id', $tenant->id)->where('user_id', $root->id)->firstOrFail();
    expect(PromotionMember::query()->where('tenant_id', $tenant->id)->where('inviter_id', $member->id)->count())->toBe(2);
    expect(PromotionFundingEvent::query()->where('tenant_id', $tenant->id)->max('funded_at'))->toBeLessThan(now()->startOfDay()->toDateTimeString());
    expect(CommissionAward::query()->where('tenant_id', $tenant->id)->count())->toBeGreaterThan(6);
    $history = app(CommissionHistoryQuery::class)->execute($tenant->id, $root->id);
    expect($history['items'][0]['occurredAt'])->toBeLessThan(now()->startOfDay()->toDateTimeString());
    $entries = LedgerEntry::query()->count();
    $runner->run($tenant->id, $root->id, $actor->id, 10);
    expect(LedgerEntry::query()->count())->toBe($entries)->and(User::query()->where('tenant_id', $tenant->id)->where('email', 'like', '%@fixture.invalid')->count())->toBe(10);
    $this->artisan('ledger:reconcile', ['--tenant' => $tenant->id])->assertSuccessful();
    Http::assertNothingSent();
});

it('denies promotion fixture generation outside isolated local databases', function () {
    $this->app->instance('env', 'production');
    expect(fn () => app(LocalPromotionFixtureSeeder::class)->run('unused', 'unused', 'unused', 10))->toThrow(HttpException::class);
});
