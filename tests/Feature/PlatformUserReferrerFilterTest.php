<?php

use App\Application\Promotion\PromotionMembershipAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('filters direct referrers by exact account or case insensitive email without writes', function () {
    $this->seed();
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    $this->withoutVite();
    Http::preventStrayRequests();
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $rootUser = User::where('tenant_id', $tenant->id)->firstOrFail();
    $members = app(PromotionMembershipAction::class);
    $root = $members->ensure($tenant->id, $rootUser->id);
    $child = $rootUser->replicate(['account_id']);
    $child->forceFill(['email' => 'referrer-child@example.test'])->save();
    $child->refresh();
    $childMember = $members->ensure($tenant->id, $child->id, $root->id);
    $grandchild = $rootUser->replicate(['account_id']);
    $grandchild->forceFill(['email' => 'referrer-grandchild@example.test'])->save();
    $members->ensure($tenant->id, $grandchild->id, $childMember->id);
    $before = collect(['promotion_members', 'ledger_accounts', 'ledger_entries'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
    $this->actingAs($owner, 'platform_admin');
    $url = 'http://admin.localhost/platform/users';
    foreach ([$rootUser->account_id, strtoupper($rootUser->email)] as $referrer) {
        $this->get($url.'?'.http_build_query(['referrer' => $referrer]))->assertOk()->assertInertia(fn ($p) => $p
            ->where('users.total', 1)->where('users.data.0.id', $child->id)->where('filters.referrer', $referrer));
    }
    $this->get($url.'?'.http_build_query(['company' => $tenant->id, 'referrer' => $rootUser->account_id, 'search' => $child->account_id]))
        ->assertOk()->assertInertia(fn ($p) => $p->where('users.total', 1));
    $otherTenant = Tenant::where('id', '<>', $tenant->id)->firstOrFail();
    $this->get($url.'?'.http_build_query(['company' => $otherTenant->id, 'referrer' => $rootUser->account_id]))
        ->assertOk()->assertInertia(fn ($p) => $p->where('users.total', 0));
    $this->getJson($url.'/referrer-candidates?'.http_build_query(['company' => $tenant->id, 'search' => $child->account_id, 'selected' => $rootUser->account_id]))
        ->assertOk()->assertJsonPath('items.0.account_id', $child->account_id)->assertJsonCount(1, 'items')
        ->assertJsonPath('selected.account_id', $rootUser->account_id);
    $this->getJson($url.'/referrer-candidates?'.http_build_query(['company' => $otherTenant->id, 'search' => $child->account_id, 'selected' => $rootUser->account_id]))
        ->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('selected', null);
    $this->get($url.'?search='.$rootUser->account_id)->assertOk()->assertInertia(fn ($p) => $p
        ->where('users.total', 1)->where('users.data.0.descendantCount', 2));
    $this->get($url.'?search='.$child->account_id)->assertOk()->assertInertia(fn ($p) => $p
        ->where('users.total', 1)->where('users.data.0.descendantCount', 1));
    $this->get($url.'?referrer='.$child->account_id)->assertOk()->assertInertia(fn ($p) => $p
        ->where('users.total', 1)->where('users.data.0.id', $grandchild->id)->where('users.data.0.descendantCount', 0));
    $this->get($url.'?referrer=0')->assertOk()->assertInertia(fn ($p) => $p->where('users.total', 0));
    $this->get($url.'?referrer=missing@example.test')->assertOk()->assertInertia(fn ($p) => $p->where('users.total', 0));
    $this->get($url.'?referrer='.$rootUser->account_id.'&page=2')->assertOk()->assertInertia(fn ($p) => $p
        ->where('users.total', 1)->has('users.data', 0)
        ->where('users.prev_page_url', fn ($value) => str_contains($value, 'referrer='.$rootUser->account_id)));
    $this->getJson($url.'?'.http_build_query(['referrer' => str_repeat('a', 121)]))->assertUnprocessable();
    $grandchild->refresh();
    $teamUrl = "http://admin.localhost/platform/tenants/{$tenant->id}/users/{$rootUser->id}/subordinates";
    $this->getJson($teamUrl)->assertOk()->assertJsonPath('total', 2)->assertJsonCount(2, 'data');
    $this->getJson($teamUrl.'?scope=direct')->assertOk()->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $child->id)->assertJsonPath('data.0.descendantCount', 1);
    $this->getJson($teamUrl.'?search='.$grandchild->account_id)->assertOk()->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $grandchild->id);
    $this->getJson($teamUrl.'?scope=direct&search='.$grandchild->account_id)->assertOk()->assertJsonPath('total', 0);
    $this->getJson($teamUrl.'?page=2')->assertOk()->assertJsonPath('total', 2)->assertJsonCount(0, 'data');
    $this->getJson("http://admin.localhost/platform/tenants/{$otherTenant->id}/users/{$rootUser->id}/subordinates")->assertNotFound();
    $this->getJson($teamUrl.'?scope=invalid')->assertUnprocessable();
    foreach ($before as $table => $snapshot) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($snapshot);
    }
    Http::assertNothingSent();
});
