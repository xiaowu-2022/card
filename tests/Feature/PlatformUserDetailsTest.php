<?php

use App\Application\Partners\PartnerManagement;
use App\Application\Promotion\ManualPromotion;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\User\PlatformUserSummary;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Services\WalletProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($this->owner, 'platform_admin');
    $this->url = "http://admin.localhost/platform/tenants/{$this->tenant->id}/users/{$this->user->id}/details";
});

it('reads the exact user independently of list pagination and does not provision or mutate funds', function () {
    $tables = ['wallets', 'ledger_accounts', 'ledger_entries', 'ledger_postings', 'promotion_members', 'audit_logs'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    $this->getJson($this->url.'?page=999&search=someone-else')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('user.id', $this->user->id)
        ->assertJsonPath('user.companyId', $this->tenant->id)
        ->assertJsonPath('user.email', $this->user->email)
        ->assertJsonPath('canViewFunds', true)
        ->assertJsonStructure(['user' => ['accountId', 'displayName', 'remark', 'supportAgent', 'status', 'promotionRank', 'wallets', 'securityDeposit', 'commission', 'totalWithdrawn', 'createdAt', 'lastLoginAt']])
        ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token');
    expect(collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all())->toBe($before);
    Http::assertNothingSent();
});

it('rejects mismatched company identities and non platform readers', function () {
    $other = User::where('tenant_id', '<>', $this->tenant->id)->firstOrFail();
    $this->getJson(str_replace($this->user->id, $other->id, $this->url))->assertNotFound();
    $this->actingAs(AdminUser::where('email', 'owner@a.localhost')->firstOrFail(), 'platform_admin')->getJson($this->url)->assertForbidden();
});

it('requires user read access and independently hides financial details and actions', function () {
    DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->whereIn('name', ['wallet.read', 'ledger.read', 'withdrawals.read'])->pluck('id'))->delete();
    $this->getJson($this->url)->assertOk()
        ->assertJsonMissingPath('user.wallets')->assertJsonMissingPath('user.securityDeposit')
        ->assertJsonMissingPath('user.commission')->assertJsonMissingPath('user.totalWithdrawn')
        ->assertJsonPath('canViewFunds', false)->assertJsonPath('canAdjustWallet', false)->assertJsonPath('canAdjustCommission', false);
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'users.read')->value('id'))->delete();
    $this->actingAs($this->owner->fresh(), 'platform_admin')->getJson($this->url)->assertForbidden();
});

it('batches summaries by both company and account without conflating business row ids', function () {
    request()->setUserResolver(fn ($guard = null) => auth()->guard($guard)->user());
    $rows = PlatformUserSummary::rows($this->tenant->id, [
        ['id' => 'order-1', 'accountId' => $this->user->account_id],
        ['id' => 'order-2', 'userId' => $this->user->id],
        ['id' => 'order-3', 'userId' => User::where('tenant_id', '<>', $this->tenant->id)->firstOrFail()->id],
    ]);
    expect($rows[0]['id'])->toBe('order-1')->and($rows[0]['userInfo']['id'])->toBe($this->user->id)
        ->and($rows[1]['userInfo']['accountId'])->toBe($this->user->account_id)->and($rows[2]['userInfo'])->toBeNull();
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'users.read')->value('id'))->delete();
    $this->actingAs($this->owner->fresh(), 'platform_admin');
    expect(PlatformUserSummary::rows($this->tenant->id, [['accountId' => $this->user->account_id]])[0])->not->toHaveKey('userInfo');
});

it('preserves native precision and limits wallet summaries to the selected user', function () {
    DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $this->user, 'ETH'));
    $available = LedgerAccount::where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->where('asset_code', 'ETH')->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $clearing = LedgerAccount::where('tenant_id', $this->tenant->id)->where('asset_code', 'ETH')->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
    app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'ETH', 'user-detail-precision', 'TEST_FUNDING', null, null, null, [
        new LedgerPostingInstruction($available->id, Money::of('0.000000000000000001', 'ETH')),
        new LedgerPostingInstruction($clearing->id, Money::of('-0.000000000000000001', 'ETH')),
    ]));
    $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'user.wallets')
        ->assertJsonPath('user.wallets.0.asset', 'ETH')->assertJsonPath('user.wallets.0.available', '0.000000000000000001');
    Http::assertNothingSent();
});

it('keeps customer order tabs scoped, paginated, permission checked and read only', function () {
    $other = $this->user->replicate(['account_id']);
    $other->id = (string) Str::uuid();
    $other->email = 'order-tab-other@example.test';
    $other->save();
    foreach ([$this->user, $other] as $person) {
        $wallet = DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $person, 'USDT'));
        for ($i = 0; $i < ($person->id === $this->user->id ? 26 : 1); $i++) {
            $id = (string) Str::uuid();
            DB::table('wallet_topup_orders')->insert([
                'id' => $id, 'tenant_id' => $this->tenant->id, 'user_id' => $person->id, 'wallet_id' => $wallet->id,
                'request_id' => $id, 'request_hash' => hash('sha256', $id), 'asset_code' => 'USDT', 'amount' => '100.01',
                'requested_amount' => '100', 'expected_amount' => '100.01', 'status' => 'EXPIRED',
                'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
                'payment_provider' => 'trc20-shared', 'payment_rail' => 'TRC20_SHARED',
                'identification_increment' => '0.01', 'token_contract' => 'T222222222222222222222222222222222',
                'network_code' => 'TRON', 'deposit_address' => 'T222222222222222222222222222222222',
            ]);
        }
    }
    $before = DB::table('ledger_entries')->count();
    $url = str_replace('/details', '/deposit-orders', $this->url);
    $this->getJson($url.'?user='.$other->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('orders.total', 26)->assertJsonCount(25, 'orders.data');
    $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(1, 'orders.data');
    $this->getJson($url.'?status=CREDITED')->assertOk()->assertJsonPath('orders.total', 0);
    $this->getJson($url.'?status=BAD')->assertUnprocessable();
    $this->getJson(str_replace('/deposit-orders', '/withdrawal-orders', $url))->assertOk();
    $cross = User::where('tenant_id', '<>', $this->tenant->id)->firstOrFail();
    $this->getJson(str_replace($this->user->id, $cross->id, $url))->assertNotFound();
    DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->whereIn('name', ['wallet_topups.read', 'withdrawals.read'])->pluck('id'))->delete();
    $this->actingAs($this->owner->fresh(), 'platform_admin');
    $this->getJson($url)->assertForbidden();
    $this->getJson(str_replace('/deposit-orders', '/withdrawal-orders', $url))->assertForbidden();
    expect(DB::table('ledger_entries')->count())->toBe($before);
    Http::assertNothingSent();
});

it('offers partner stock only for enabled same company partners with report permission', function () {
    $this->getJson($this->url)->assertOk()->assertJsonPath('user.partnerId', null);
    $partner = app(PartnerManagement::class)->configure($this->owner, $this->tenant->id, [
        'account_id' => $this->user->account_id, 'enabled' => true, 'share_percent' => '40',
    ]);
    $before = DB::table('ledger_entries')->count();
    $this->getJson($this->url)->assertOk()->assertJsonPath('user.partnerId', $partner->id);
    DB::table('partner_configurations')->where('id', $partner->id)->update(['enabled' => false]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('user.partnerId', null);
    DB::table('partner_configurations')->where('id', $partner->id)->update(['enabled' => true]);
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'partners.manage')->value('id'))->delete();
    $this->actingAs($this->owner->fresh(), 'platform_admin');
    $this->getJson($this->url)->assertOk()->assertJsonPath('user.partnerId', null);
    $this->getJson("http://admin.localhost/platform/partners/{$partner->id}/stock?company={$this->tenant->id}")->assertForbidden();
    expect(DB::table('ledger_entries')->count())->toBe($before);
    Http::assertNothingSent();
});

it('reads every upstream referrer in order with current ranks without provisioning or writes', function () {
    $membership = app(PromotionMembershipAction::class);
    $root = $membership->ensure($this->tenant->id, $this->user->id);
    $chain = [$this->user];
    $parent = $root;
    for ($i = 0; $i < 3; $i++) {
        $person = $this->user->replicate(['account_id']);
        $person->email = "ancestor-{$i}@example.test";
        $person->save();
        $person->refresh();
        $parent = $membership->ensure($this->tenant->id, $person->id, $parent->id);
        $chain[] = $person;
    }
    $manual = app(ManualPromotion::class);
    foreach ([0 => 3, 2 => 1] as $index => $rank) {
        $level = DB::table('paid_promotion_levels')->where('tenant_id', $this->tenant->id)->where('rank', $rank)->value('id');
        $manual->adjust($this->tenant->id, $chain[$index]->id, $this->owner, $level, 'Isolated ancestor test', (string) Str::uuid(), null);
    }
    $before = collect(['promotion_members', 'ledger_entries', 'wallets', 'audit_logs'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    $url = str_replace($this->user->id, $chain[3]->id, $this->url);
    $this->getJson($url)->assertOk()->assertJsonCount(3, 'user.ancestors')
        ->assertJsonPath('user.ancestors.0.id', $chain[2]->id)->assertJsonPath('user.ancestors.0.promotionRank', 1)
        ->assertJsonPath('user.ancestors.1.id', $chain[1]->id)->assertJsonPath('user.ancestors.1.promotionRank', 0)
        ->assertJsonPath('user.ancestors.2.id', $chain[0]->id)->assertJsonPath('user.ancestors.2.promotionRank', 3)
        ->assertJsonPath('user.ancestors.2.distance', 3)
        ->assertJsonPath('user.promotionTeamCount', 0)
        ->assertJsonPath('user.invitationCode', $parent->invitation_code);
    $this->getJson(str_replace($this->user->id, $chain[1]->id, $this->url))->assertOk()->assertJsonPath('user.promotionTeamCount', 2);
    $this->getJson($this->url)->assertOk()->assertJsonCount(0, 'user.ancestors')->assertJsonPath('user.promotionTeamCount', 3);
    expect(collect(array_keys($before))->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all())->toBe($before);
    Http::assertNothingSent();
});
