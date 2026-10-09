<?php

use App\Application\Assets\WithdrawAssetsAction;
use App\Application\SecurityDeposit\RefundSecurityDepositAction;
use App\Application\User\CreatePlatformUserAction;
use App\Application\User\UpdateUserOperationRestrictions;
use App\Application\User\UserOperationRestrictions;
use App\Application\Wallet\TransferWalletBalanceAction;
use App\Application\Withdrawal\CreateWithdrawalAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    Http::preventStrayRequests();
    Queue::fake();
    config(['inertia.ssr.enabled' => false]);
    $this->tenant = Tenant::where('slug', 'tenant-a')->sole();
    $this->actor = AdminUser::where('email', 'owner@platform.local')->sole();
    $this->subject = app(CreatePlatformUserAction::class)->execute($this->tenant->id, $this->actor, [
        'email' => 'restrictions@example.test', 'display_name' => 'Restrictions', 'password' => 'secret123',
        'password_confirmation' => 'secret123', 'request_id' => (string) Str::uuid(),
    ]);
    $this->url = 'http://admin.localhost/platform/tenants/'.$this->tenant->id.'/users/'.$this->subject->id.'/restrictions';
    $this->data = array_fill_keys(UserOperationRestrictions::FIELDS, true) + ['revision' => 0, 'confirmed' => true, 'request_id' => (string) Str::uuid()];
});

it('defaults to unrestricted and saves four independent flags with scoped audit and replay', function () {
    $money = DB::table('ledger_accounts')->orderBy('id')->get()->toJson();
    $this->actingAs($this->actor, 'platform_admin')->getJson($this->url)->assertOk()->assertJsonPath('revision', 0)->assertJsonPath('withdrawal_blocked', false);
    $this->get('http://admin.localhost/platform/users')->assertInertia(fn ($p) => $p->where('canManageRestrictions', true));
    $this->postJson($this->url, $this->data)->assertOk()->assertJsonPath('revision', 1);
    $this->postJson($this->url, $this->data)->assertOk()->assertJsonPath('revision', 1);
    expect(AuditLog::where('action', 'USER_OPERATION_RESTRICTIONS_UPDATED')->count())->toBe(1);
    $audit = AuditLog::where('action', 'USER_OPERATION_RESTRICTIONS_UPDATED')->sole();
    expect($audit->actor_id)->toBe($this->actor->id)->and($audit->before_data['withdrawal_blocked'])->toBeFalse()->and($audit->after_data['withdrawal_blocked'])->toBeTrue();
    $this->postJson($this->url, [...$this->data, 'withdrawal_blocked' => false])->assertConflict();
    $this->postJson($this->url, [...$this->data, 'request_id' => (string) Str::uuid()])->assertConflict();
    $this->postJson($this->url, [...$this->data, 'revision' => 1, 'request_id' => (string) Str::uuid(), 'withdrawal_blocked' => false])->assertOk();
    expect($this->subject->fresh()->withdrawal_blocked)->toBeFalse()->and($this->subject->fresh()->deposit_refund_blocked)->toBeTrue();
    expect(DB::table('ledger_accounts')->orderBy('id')->get()->toJson())->toBe($money);
});

it('requires permission confirmation and same company and keeps reads inert', function () {
    $this->actingAs($this->actor, 'platform_admin');
    $this->postJson($this->url, [...$this->data, 'confirmed' => false])->assertUnprocessable();
    $this->postJson($this->url, [...$this->data, 'withdrawal_blocked' => 'invalid'])->assertUnprocessable();
    $other = Tenant::where('slug', 'tenant-b')->sole();
    $wrong = str_replace($this->tenant->id, $other->id, $this->url);
    $this->getJson($wrong)->assertNotFound();
    $this->postJson($wrong, $this->data)->assertNotFound();
    $permission = DB::table('permissions')->where('name', 'users.restrictions.manage')->value('id');
    DB::table('role_permissions')->where('permission_id', $permission)->delete();
    $this->getJson($this->url)->assertForbidden();
    $this->postJson($this->url, $this->data)->assertForbidden();
    expect($this->subject->fresh()->operation_restrictions_revision)->toBe(0);
    expect(AuditLog::where('action', 'USER_OPERATION_RESTRICTIONS_UPDATED')->count())->toBe(0);
});

it('rolls back flags when audit cannot be written', function () {
    $event = 'eloquent.creating: '.AuditLog::class;
    Event::listen($event, fn () => throw new RuntimeException('test audit failure'));
    try {
        expect(fn () => app(UpdateUserOperationRestrictions::class)->execute($this->tenant->id, $this->subject->id, $this->actor, $this->data))->toThrow(RuntimeException::class);
    } finally {
        Event::forget($event);
    }
    expect($this->subject->fresh()->withdrawal_blocked)->toBeFalse()->and($this->subject->fresh()->operation_restrictions_revision)->toBe(0);
});

it('blocks new withdrawals refund applications and outgoing transfers before any side effects', function () {
    app(UpdateUserOperationRestrictions::class)->execute($this->tenant->id, $this->subject->id, $this->actor, $this->data);
    $before = collect(['ledger_entries', 'withdrawal_orders', 'asset_withdrawal_orders', 'security_deposit_refund_requests', 'wallet_transfers'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);
    $calls = [
        fn () => app(CreateWithdrawalAction::class)->execute($this->tenant->id, $this->subject->id, (string) Str::uuid(), (string) Str::uuid(), '1'),
        fn () => app(WithdrawAssetsAction::class)->create($this->tenant->id, $this->subject->id, 'USDC_ETHEREUM', '1', '0x'.str_repeat('1', 40), '0', (string) Str::uuid(), true),
        fn () => app(RefundSecurityDepositAction::class)->request($this->tenant->id, $this->subject->id, (string) Str::uuid()),
        fn () => app(TransferWalletBalanceAction::class)->execute($this->tenant->id, $this->subject->id, '202601010001', '1', (string) Str::uuid()),
    ];
    foreach ($calls as $call) {
        try {
            $call();
            $this->fail('Restricted operation was accepted');
        } catch (DomainException $e) {
            expect($e->errorCode)->toBe('USER_OPERATION_RESTRICTED')->and($e->getMessage())->toBe('Please contact support.');
        }
    }
    foreach ($before as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('removes each restriction independently without changing other users', function () {
    foreach (UserOperationRestrictions::FIELDS as $field) {
        $this->subject->update([$field => true]);
        expect(fn () => UserOperationRestrictions::assertAllowed($this->tenant->id, $this->subject->id, $field))->toThrow(DomainException::class, 'Please contact support.');
        $other = User::where('tenant_id', $this->tenant->id)->where('id', '<>', $this->subject->id)->firstOrFail();
        UserOperationRestrictions::assertAllowed($this->tenant->id, $other->id, $field);
        $this->subject->update([$field => false]);
        UserOperationRestrictions::assertAllowed($this->tenant->id, $this->subject->id, $field);
    }
});

it('returns the contact support error through H5 and legacy confirmation endpoints', function () {
    $this->subject->update(['deposit_refund_blocked' => true, 'wallet_transfer_blocked' => true]);
    $this->actingAs($this->subject, 'tenant_user');
    foreach (['', '/api/v1/client'] as $prefix) {
        $base = 'http://a.localhost'.$prefix;
        $this->postJson($base.'/security-deposit/refund', ['action' => 'request', 'request_id' => (string) Str::uuid(), 'current_password' => 'secret123', 'confirmed' => true])
            ->assertForbidden()->assertJsonPath('error.code', 'USER_OPERATION_RESTRICTED')->assertJsonPath('error.message', 'Please contact support.');
        $this->postJson($base.'/wallet/transfers', ['asset' => 'USDT', 'amount' => '1', 'recipient_account_id' => '202601010001', 'request_id' => (string) Str::uuid(), 'current_password' => 'secret123', 'confirmed' => true])
            ->assertForbidden()->assertJsonPath('error.code', 'USER_OPERATION_RESTRICTED');
    }
});

it('allows unrestricted transfers and preserves completed replay after restrictions change', function () {
    $recipient = app(CreatePlatformUserAction::class)->execute($this->tenant->id, $this->actor, [
        'email' => 'restriction-recipient@example.test', 'display_name' => 'Recipient', 'password' => 'secret123',
        'password_confirmation' => 'secret123', 'request_id' => (string) Str::uuid(),
    ]);
    $source = LedgerAccount::where('tenant_id', $this->tenant->id)->where('user_id', $this->subject->id)->where('asset_code', 'USDT')->where('account_type', 'USER_AVAILABLE')->sole();
    $clearing = LedgerAccount::where('tenant_id', $this->tenant->id)->where('asset_code', 'USDT')->where('account_type', 'TENANT_TOPUP_CLEARING')->sole();
    app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'USDT', 'restrictions-test:'.Str::uuid(), 'TEST_TOPUP', null, null, null, [
        new LedgerPostingInstruction($clearing->id, Money::of('-10', 'USDT')),
        new LedgerPostingInstruction($source->id, Money::of('10', 'USDT')),
    ]));
    $action = app(TransferWalletBalanceAction::class);
    $id = (string) Str::uuid();
    $order = $action->execute($this->tenant->id, $this->subject->id, $recipient->account_id, '1', $id);
    $this->subject->update(['wallet_transfer_blocked' => true]);
    expect($action->execute($this->tenant->id, $this->subject->id, $recipient->account_id, '1', $id)->id)->toBe($order->id);
    expect(fn () => $action->execute($this->tenant->id, $this->subject->id, $recipient->account_id, '1', (string) Str::uuid()))->toThrow(DomainException::class, 'Please contact support.');
    expect($source->fresh()->balance)->toBe('9.00000000');
    $this->subject->update(['wallet_transfer_blocked' => false]);
    $action->execute($this->tenant->id, $this->subject->id, $recipient->account_id, '1', (string) Str::uuid());
    expect($source->fresh()->balance)->toBe('8.00000000');
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});
