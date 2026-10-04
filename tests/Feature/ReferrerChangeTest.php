<?php

require_once __DIR__.'/../Support/PromotionAdjustmentRace.php';

use App\Application\Promotion\ChangeReferrer;
use App\Application\Promotion\PromotionMembershipAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Promotion\Models\PromotionMember;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false]);
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->root = app(PromotionMembershipAction::class)->ensure($this->tenant->id, $this->user->id);
    $this->child = referrerTestMember($this, $this->root);
    $this->grandchild = referrerTestMember($this, $this->child);
    $this->target = referrerTestMember($this, null);
    $this->data = ['new_inviter_id' => $this->target->id, 'old_inviter_id' => $this->root->id, 'revision' => 0, 'reason' => 'Move team in isolated test', 'request_id' => (string) Str::uuid(), 'confirmed' => true];
    $this->url = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/users/'.$this->child->user_id.'/referrer';
});
function referrerTestMember($test, ?PromotionMember $parent): PromotionMember
{
    $user = $test->user->replicate(['account_id']);
    $user->forceFill(['email' => Str::uuid().'@example.test'])->save();

    return app(PromotionMembershipAction::class)->ensure($test->tenant->id, $user->id, $parent?->id);
}
function referrerChange($test, array $overrides = []): object
{
    return app(ChangeReferrer::class)->execute($test->tenant->id, $test->child->user_id, $test->actor, array_replace($test->data, $overrides));
}
it('moves the subtree with evidence and idempotency without changing business history', function () {
    $tables = ['ledger_entries', 'ledger_postings', 'account_activations', 'account_activation_relations', 'activation_count_snapshots', 'paid_promotion_shares'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()]);
    $row = referrerChange($this);
    expect(referrerChange($this)->id)->toBe($row->id);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    expect($this->child->fresh()->inviter_id)->toBe($this->target->id)->and($this->grandchild->fresh()->inviter_id)->toBe($this->child->id);
    expect(app(ChangeReferrer::class)->descendants($this->tenant->id, $this->target->id))->toEqualCanonicalizing([$this->child->id, $this->grandchild->id]);
    foreach ($tables as $table) {
        expect(DB::table($table)->get()->toJson())->toBe($before[$table]);
    }
    expect(fn () => DB::transaction(fn () => DB::table('referrer_changes')->where('id', $row->id)->delete()))->toThrow(QueryException::class);
});
it('rejects self descendants cross-company stale forms and revoked permissions', function () {
    $this->actingAs($this->actor, 'platform_admin')->get($this->url.'?search='.User::findOrFail($this->target->user_id)->email)->assertOk();
    foreach ([$this->child->id, $this->grandchild->id] as $target) {
        $this->postJson($this->url, array_replace($this->data, ['new_inviter_id' => $target]))->assertUnprocessable();
    }
    $otherUser = User::where('tenant_id', '<>', $this->tenant->id)->firstOrFail();
    $other = app(PromotionMembershipAction::class)->ensure($otherUser->tenant_id, $otherUser->id);
    $this->postJson($this->url, array_replace($this->data, ['new_inviter_id' => $other->id]))->assertNotFound();
    $this->postJson($this->url, array_replace($this->data, ['revision' => 1]))->assertStatus(409);
    $this->postJson($this->url, array_replace($this->data, ['confirmed' => false]))->assertUnprocessable();
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'users.referrer.manage')->value('id'))->delete();
    $this->postJson($this->url, $this->data)->assertForbidden();
});
it('rejects direct relationship edits without matching immutable evidence', function () {
    expect(fn () => DB::transaction(fn () => DB::table('promotion_members')->where('id', $this->child->id)->update(['inviter_id' => $this->target->id])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('promotion_members')->where('id', $this->child->id)->update(['referrer_revision' => 1])))->toThrow(QueryException::class);
});

it('serializes concurrent reparenting and rejects the stale competing edit', function () {
    $other = referrerTestMember($this, null);
    $tenant = $this->tenant->id;
    $user = $this->child->user_id;
    $actor = $this->actor->id;
    $first = $this->data;
    $second = array_replace($first, ['new_inviter_id' => $other->id, 'request_id' => (string) Str::uuid()]);
    $results = racePromotionAdjustments([
        fn () => app(ChangeReferrer::class)->execute($tenant, $user, AdminUser::findOrFail($actor), $first),
        fn () => app(ChangeReferrer::class)->execute($tenant, $user, AdminUser::findOrFail($actor), $second),
    ]);
    expect($results)->toEqualCanonicalizing(['completed', 'rejected']);
    expect(DB::table('referrer_changes')->where('user_id',$user)->count())->toBe(1);
});
