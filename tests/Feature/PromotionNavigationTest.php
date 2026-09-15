<?php

use App\Application\Promotion\CommissionHistoryQuery;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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
            ->assertInertia(fn ($page) => $page->component('user/Promotion')->where('section', $section));
    }
    $this->get('http://a.localhost/promotion/team')->assertRedirect('/promotion#team-summary');
    $this->get('http://a.localhost/promotion/commissions')->assertOk()
        ->assertInertia(fn ($page) => $page->component('user/PromotionCommissions')->where('history.items', [])->where('history.date', null));
    $this->get('http://a.localhost/promotion/unknown')->assertNotFound();
});

it('keeps daily filters and direct pagination within their child pages', function (): void {
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/promotion/daily?date=2026-09-10&page=2')->assertOk()
        ->assertInertia(fn ($page) => $page->where('section', 'daily')->where('promotion.date', '2026-09-10')->where('promotion.page', 2));
    $this->get('http://a.localhost/promotion/direct?direct_page=2')->assertOk()
        ->assertInertia(fn ($page) => $page->where('section', 'direct')->where('promotion.directPage', 2));
    $this->get('http://a.localhost/promotion/commissions?date=2026-09-10&page=2')->assertOk()
        ->assertInertia(fn ($page) => $page->where('history.date', '2026-09-10')->where('history.page', 2));
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
