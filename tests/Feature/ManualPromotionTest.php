<?php

use App\Application\Promotion\AccountActivationStatus;
use App\Application\Promotion\ManualPromotion;
use App\Application\Promotion\PaidPromotionQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->manual = app(ManualPromotion::class);
    $this->level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', 3)->first();
    $this->url = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/users/'.$this->user->id.'/promotion';
});

function manualAdjust($test, ?string $choice = null, ?string $expected = null): object
{
    return $test->manual->adjust($test->tenant->id, $test->user->id, $test->owner, $choice ?? $test->level->id, 'Isolated test adjustment', (string) Str::uuid(), $expected);
}

it('grants long-lived benefits without a wallet, KYC, payment or activation writes', function () {
    $tables = ['ledger_entries', 'wallets', 'paid_promotion_orders', 'paid_promotion_cycles', 'account_activations', 'commission_awards', 'paid_promotion_rebates'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);
    $row = manualAdjust($this);
    expect($this->manual->benefit($this->tenant->id, $this->user->id)->rank)->toBe(3)
        ->and(app(AccountActivationStatus::class)->get($this->tenant->id, $this->user->id))->toMatchArray(['agent' => true, 'qualified' => true, 'rank' => 3, 'endsAt' => null]);
    $dto = app(PaidPromotionQuery::class)->execute($this->tenant->id, $this->user->id);
    expect($dto)->toMatchArray(['rank' => 3, 'manualLevel' => true, 'cycle' => null, 'progress' => null, 'membershipStatus' => 'ACTIVE'])
        ->and($dto['paymentAccess']['verified'])->toBeFalse()
        ->and(collect($dto['levels'])->where('selectable', true))->toHaveCount(0);
    $this->travel(400)->days();
    expect($this->manual->benefit($this->tenant->id, $this->user->id)->rank)->toBe(3);
    foreach ($before as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
    expect(DB::table('audit_logs')->where('action', 'PROMOTION_LEVEL_ADJUSTED')->where('resource_id', $this->user->id)->count())->toBe(1);
});

it('supports downgrades, ordinary status and restoring paid rules with immutable history', function () {
    $first = manualAdjust($this);
    $ordinary = manualAdjust($this, 'ordinary', $first->id);
    expect($this->manual->managed($this->tenant->id, $this->user->id))->toBeTrue()
        ->and($this->manual->benefit($this->tenant->id, $this->user->id))->toBeNull();
    manualAdjust($this, 'paid', $ordinary->id);
    expect($this->manual->managed($this->tenant->id, $this->user->id))->toBeFalse()
        ->and(DB::table('manual_promotion_adjustments')->count())->toBe(3)
        ->and(DB::table('manual_promotion_adjustments')->where('id', $ordinary->id)->value('previous_rank'))->toBe(3);
    expect(fn () => DB::transaction(fn () => DB::table('manual_promotion_adjustments')->where('id', $first->id)->update(['rank' => 2])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('manual_promotion_adjustments')->where('id', $first->id)->delete()))
        ->toThrow(QueryException::class);
});

it('deduplicates requests and rejects stale or changed intent', function () {
    $id = (string) Str::uuid();
    $args = [$this->tenant->id, $this->user->id, $this->owner, $this->level->id, 'Test', $id, null];
    $first = $this->manual->adjust(...$args);
    expect($this->manual->adjust(...$args)->id)->toBe($first->id);
    $args[4] = 'Changed';
    expect(fn () => $this->manual->adjust(...$args))->toThrow(DomainException::class);
    expect(fn () => manualAdjust($this, 'ordinary'))->toThrow(DomainException::class);
    expect(DB::table('manual_promotion_adjustments')->count())->toBe(1);
});

it('rolls back a journal entry and audit atomically', function () {
    try {
        DB::transaction(function () {
            manualAdjust($this);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException $e) {
    }
    expect(DB::table('manual_promotion_adjustments')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'PROMOTION_LEVEL_ADJUSTED')->count())->toBe(0);
});

it('allows configured disabled levels and snapshots their benefits', function () {
    DB::table('paid_promotion_levels')->where('id', $this->level->id)->update(['enabled' => false, 'revision' => $this->level->revision + 1]);
    manualAdjust($this);
    DB::table('paid_promotion_levels')->where('id', $this->level->id)->update(['reward' => $this->level->reward + 1, 'revision' => $this->level->revision + 2]);
    $benefit = $this->manual->benefit($this->tenant->id, $this->user->id);
    expect($benefit->reward)->toBe($this->level->reward)->and($benefit->percent)->toBe($this->level->percent);
});

it('scopes adjustment and history to both company and user', function () {
    $other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $level = DB::table('paid_promotion_levels')->where('tenant_id', $other->id)->value('id');
    expect(fn () => manualAdjust($this, $level))->toThrow(RecordNotFoundException::class);
    $this->actingAs($this->owner, 'platform_admin')->get('http://admin.localhost/platform/tenants/'.$other->id.'/users/'.$this->user->id.'/promotion')->assertNotFound();
    expect(DB::table('manual_promotion_adjustments')->count())->toBe(0);
});

it('requires platform permission and explicit confirmation but not a repeat password', function () {
    $data = ['choice' => $this->level->id, 'reason' => 'Offline acceptance', 'request_id' => (string) Str::uuid(), 'expected_adjustment_id' => null];
    $this->actingAs($this->owner, 'platform_admin')->post($this->url, $data)->assertSessionHasErrors('confirmed');
    $this->post($this->url, $data + ['confirmed' => true])->assertRedirect();
    expect(DB::table('manual_promotion_adjustments')->count())->toBe(1);
    $tenantAdmin = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    expect(fn () => $this->manual->adjust($this->tenant->id, $this->user->id, $tenantAdmin, 'ordinary', 'test', (string) Str::uuid(), null))->toThrow(HttpException::class);
    $this->actingAs($tenantAdmin, 'platform_admin')->post($this->url, $data + ['confirmed' => true])->assertForbidden();
});

it('exposes the effective level and adjustment journal without a phone column', function () {
    manualAdjust($this);
    $this->actingAs($this->owner, 'platform_admin')->get($this->url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('platform/UserPromotion')->where('currentRank', 3)->where('canAdjust', true)->has('history.data', 1));
    $this->get('http://admin.localhost/platform/users?search='.$this->user->account_id)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('platform/Users')->where('users.data.0.promotionRank', 3)->missing('users.data.0.phone'));
});

it('withholds the adjustment form and endpoint from a platform reader without the permission', function () {
    $permission = DB::table('permissions')->where('name', 'promotion_members.manage')->value('id');
    DB::table('role_permissions')->where('permission_id', $permission)->delete();
    $this->actingAs($this->owner, 'platform_admin')->get($this->url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('canAdjust', false));
    $this->post($this->url, ['choice' => $this->level->id, 'reason' => 'Test', 'request_id' => (string) Str::uuid(), 'confirmed' => true])->assertForbidden();
    expect(DB::table('manual_promotion_adjustments')->count())->toBe(0);
});

it('accepts configured ranks beyond eight without hardcoded choices', function () {
    $values = (array) $this->level;
    $values['id'] = (string) Str::uuid();
    $values['rank'] = 12;
    DB::table('paid_promotion_levels')->insert($values);
    manualAdjust($this, $values['id']);
    expect($this->manual->benefit($this->tenant->id, $this->user->id)->rank)->toBe(12);
    expect(app(PaidPromotionQuery::class)->benefits($this->tenant->id,$this->user->id)['rank'])->toBe(12);
});
