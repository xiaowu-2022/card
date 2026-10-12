<?php

use App\Application\Partners\PartnerManagement;
use App\Application\Promotion\PromotionMembershipAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\SecurityDeposit\Models\SecurityDepositRefundRequest;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('filters ongoing deposit refunds with company referrer search and pagination scope without writes', function () {
    $this->seed();
    $this->withoutVite();
    Http::preventStrayRequests();
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $root = User::where('tenant_id', $tenant->id)->firstOrFail();
    $users = ['legacy' => $root];
    foreach (['waiting', 'blocked', 'cancelled', 'restoring', 'ordinary', 'partner'] as $kind) {
        $users[$kind] = $root->replicate(['account_id']);
        $users[$kind]->forceFill(['email' => 'refund-filter-'.$kind.'@example.test'])->save();
        $users[$kind]->refresh();
    }
    $users['foreign'] = User::where('tenant_id', '<>', $tenant->id)->firstOrFail();
    foreach ($users as $kind => $user) {
        if ($kind === 'ordinary') {
            continue;
        }
        $wallet = Wallet::create(['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'asset_code' => 'USDT', 'status' => 'ACTIVE']);
        $started = now()->subDays(8);
        $timed = in_array($kind, ['waiting', 'blocked', 'restoring'], true);
        SecurityDepositRefundRequest::create([
            'tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'wallet_id' => $wallet->id,
            'request_id' => (string) Str::uuid(), 'amount' => '100', 'asset_code' => 'USDT',
            'status' => $kind === 'cancelled' ? 'CANCELLED' : 'CHECKING', 'created_at' => $started,
            'refund_wait_days' => $timed ? 7 : null,
            'refund_eligible_at' => $timed ? $started->copy()->addDays(7) : null,
            'progress' => $timed ? $kind : null,
            'cancel_requested_at' => $kind === 'restoring' ? now() : null,
        ]);
    }
    app(PartnerManagement::class)->configure($owner, $tenant->id, ['account_id' => $users['partner']->account_id, 'enabled' => true, 'share_percent' => '40']);
    $members = app(PromotionMembershipAction::class);
    $rootMember = $members->ensure($tenant->id, $root->id);
    $members->ensure($tenant->id, $users['waiting']->id, $rootMember->id);
    $snapshot = fn () => collect(['security_deposit_refund_requests', 'wallets', 'ledger_accounts', 'ledger_entries', 'promotion_members', 'audit_logs'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $this->actingAs($owner, 'platform_admin');
    $url = 'http://admin.localhost/platform/users?status=DEPOSIT_REFUNDING';
    $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->where('users.total', 4)->where('filters.status', 'DEPOSIT_REFUNDING'));
    $this->get($url.'&company='.$tenant->id)->assertOk()->assertInertia(fn ($p) => $p->where('users.total', 3));
    foreach (['legacy', 'waiting', 'blocked'] as $kind) {
        $this->get($url.'&search='.$users[$kind]->account_id)->assertOk()->assertInertia(fn ($p) => $p
            ->where('users.total', 1)->where('users.data.0.id', $users[$kind]->id)->where('users.data.0.status', 'ACTIVE'));
    }
    foreach (['cancelled', 'restoring', 'ordinary', 'partner', 'foreign'] as $kind) {
        $this->get($url.'&company='.$tenant->id.'&search='.$users[$kind]->account_id)->assertOk()->assertInertia(fn ($p) => $p->where('users.total', 0));
    }
    $this->get($url.'&referrer='.$root->account_id)->assertOk()->assertInertia(fn ($p) => $p
        ->where('users.total', 1)->where('users.data.0.id', $users['waiting']->id));
    $this->get($url.'&company='.$tenant->id.'&page=2')->assertOk()->assertInertia(fn ($p) => $p
        ->where('users.total', 3)->has('users.data', 0)->where('users.current_page', 2)
        ->where('users.prev_page_url', fn ($value) => str_contains($value, 'status=DEPOSIT_REFUNDING') && str_contains($value, 'company='.$tenant->id)));
    $this->get('http://admin.localhost/platform/users?status=ACTIVE&search='.$users['cancelled']->account_id)->assertOk()->assertInertia(fn ($p) => $p->where('users.total', 1));
    $this->getJson('http://admin.localhost/platform/users?status=INVALID')->assertUnprocessable();
    expect($snapshot())->toBe($before);
    Http::assertNothingSent();
});
