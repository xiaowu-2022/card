<?php

use App\Application\Promotion\CommissionHistoryQuery;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\Promotion\PromotionReportQuery;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    config(['inertia.ssr.enabled' => false]);
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
});

it('serves independent promotion pages and commission history behind user authentication', function (): void {
    $this->get('http://a.localhost/promotion/commissions')->assertRedirect('/login');
    $this->actingAs($this->user, 'tenant_user');
    $this->get('http://a.localhost/promotion')->assertOk()->assertInertia(fn ($page) => $page->where('section', 'overview'));
    foreach (['daily', 'direct'] as $section) {
        $this->get('http://a.localhost/promotion/'.$section)->assertOk()
            ->assertInertia(fn ($page) => $page->component('user/PromotionReport')->where('section', $section));
    }
    $this->get('http://a.localhost/promotion/team')->assertRedirect('/promotion/invitations');
    $this->get('http://a.localhost/promotion/commissions')->assertOk()
        ->assertInertia(fn ($page) => $page->component('user/PromotionCommissions')->where('history.items', [])->where('history.dateFrom', null));
    $this->get('http://a.localhost/promotion/unknown')->assertNotFound();
});

it('keeps daily filters and direct pagination within their child pages', function (): void {
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/promotion/daily?date=2026-09-10&page=2')->assertOk()
        ->assertInertia(fn ($page) => $page->where('section', 'daily')->where('report.dateFrom', '2026-09-10')->where('report.page', 2));
    $this->get('http://a.localhost/promotion/direct?page=2')->assertOk()
        ->assertInertia(fn ($page) => $page->where('section', 'direct')->where('report.page', 2));
    $this->get('http://a.localhost/promotion/commissions?date=2026-09-10&page=2')->assertOk()
        ->assertInertia(fn ($page) => $page->where('history.dateFrom', '2026-09-10')->where('history.page', 2));
    $this->getJson('http://a.localhost/promotion/commissions?date=wrong')->assertUnprocessable();
});

it('rejects commission history with a mismatched company and user', function (): void {
    $other = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    expect(fn () => app(CommissionHistoryQuery::class)->execute($other->id, $this->user->id))
        ->toThrow(ModelNotFoundException::class);
});

it('preserves access gates before redirecting legacy team links', function (): void {
    $this->get('http://a.localhost/promotion/team')->assertRedirect('/login');
    $this->actingAs($this->user, 'tenant_user')->get('http://b.localhost/promotion/team')->assertRedirect('/login');
    $this->user->update(['status' => UserStatus::Suspended]);
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/promotion/team')->assertRedirect('/account/restricted');
});

it('keeps a single company day exact regardless of the database session timezone', function (string $zone): void {
    $this->tenant->update(['timezone' => 'Asia/Kuala_Lumpur']);
    $membership = app(PromotionMembershipAction::class);
    $parent = $membership->ensure($this->tenant->id, $this->user->id);
    $expected = [];
    foreach (['2026-10-02T23:09:00+08:00', '2026-10-03T00:00:00+08:00', '2026-10-03T23:59:59+08:00', '2026-10-04T00:00:00+08:00'] as $index => $at) {
        $this->travelTo(CarbonImmutable::parse($at)->utc());
        $child = $this->user->replicate(['account_id']);
        $child->forceFill(['email' => Str::uuid().'@example.test'])->save();
        $membership->ensure($this->tenant->id, $child->id, $parent->id);

        if (in_array($index, [1, 2], true)) {
            $expected[] = $child->fresh()->account_id;
        }
    }
    $db = DB::connection();
    $previous = $db->selectOne('SHOW TIME ZONE')->TimeZone;
    try {
        $db->select("SELECT set_config('TimeZone', ?, false)", [$zone]);
        $report = app(PromotionReportQuery::class)->daily($this->tenant->id, $this->user->id, [
            'date_from' => '2026-10-03', 'date_to' => '2026-10-03',
        ]);
        expect($report['counts']['invited'])->toBe(2)
            ->and(array_column($report['items'], 'sourceAccountId'))->toEqualCanonicalizing($expected);
    } finally {
        $db->select("SELECT set_config('TimeZone', ?, false)", [$previous]);
    }
})->with(['UTC', 'Asia/Kuala_Lumpur']);
