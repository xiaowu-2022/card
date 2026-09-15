<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Promotion\CommissionHistoryQuery;
use App\Application\Promotion\CommissionTransferEligibility;
use App\Application\Promotion\CompanyFundBookQuery;
use App\Application\Promotion\ConfigurePromotionAction;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\Promotion\PromotionQuery;
use App\Application\Promotion\TransferCommissionAction;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\SecurityDeposit\RefundSecurityDepositAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Application\Wallet\UserWalletQuery;
use App\Application\Withdrawal\CreateWithdrawalAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Promotion\Models\CommissionAward;
use App\Domain\Promotion\Models\PromotionLevel;
use App\Domain\Promotion\Models\PromotionMember;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\RegistrationChallenge;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Mail\UserVerificationCodeMail;
use App\Support\Errors\DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    Queue::fake();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->tenant->businessSettings()->update(['required_security_deposit_amount' => '50', 'allow_withdrawal' => true, 'security_deposit_refund_wait_days' => 0]);
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->admin = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->platform = AdminUser::query()->findOrFail(DB::table('admin_memberships')->where('scope_type', 'PLATFORM')->where('status', 'ACTIVE')->value('admin_user_id'));
});

function promotionTestWallet($test, User $user): Wallet
{
    expect($test->tenant->default_asset)->toBe('USDT');
    $application = app(SubmitKycApplicationAction::class)->execute($test->tenant, $user, 'MY', 'PROMO-'.$user->id, kycTestImage('front.png'), kycTestImage('back.png'));
    app(ApproveKycAction::class)->execute($test->tenant->id, $application->id, $test->admin);
    $wallet = app(ActivateUserWalletAction::class)->execute($test->tenant->id, $user->id)->wallet;
    $available = LedgerAccount::query()->where('tenant_id', $test->tenant->id)->where('wallet_id', $wallet->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $company = LedgerAccount::query()->where('tenant_id', $test->tenant->id)->where('asset_code', 'USDT')->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
    app(LedgerWriter::class)->post(new LedgerPostingPlan(
        $test->tenant->id, 'USDT', 'promotion_test:'.Str::uuid(), 'TEST_TOPUP', null, null, null, [
            new LedgerPostingInstruction($company->id, Money::of('-200', 'USDT')),
            new LedgerPostingInstruction($available->id, Money::of('200', 'USDT')),
        ],
    ));

    return $wallet;
}

it('earns company-funded commission only from real deposit funding and pays again after actual refund', function (): void {
    $members = app(PromotionMembershipAction::class);
    $configure = app(ConfigurePromotionAction::class);
    $level = $configure->level($this->tenant->id, $this->platform->id, 1, 'Agent', '5', null);
    $parent = $configure->memberLevel($this->tenant->id, $this->platform->id, $this->user->id, $level->id);
    $child = $this->user->replicate(['account_id']);
    $child->forceFill(['email' => 'funding-child@example.test'])->save();
    $members->ensure($this->tenant->id, $child->id, $parent->id);
    $wallet = promotionTestWallet($this, $child);
    $fund = app(FundSecurityDepositAction::class);
    $request = (string) Str::uuid();
    $fund->execute($this->tenant->id, $child->id, $request, '50');
    $fund->execute($this->tenant->id, $child->id, $request, '50');
    expect(CommissionAward::query()->count())->toBe(1);
    $commission = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->where('account_type', 'USER_COMMISSION')->firstOrFail();
    expect($commission->balance)->toBe('5.00000000')->and($commission->wallet_id)->toBeNull();
    $refunds = app(RefundSecurityDepositAction::class);
    $cancelled = $refunds->request($this->tenant->id, $child->id, (string) Str::uuid());
    $refunds->cancel($this->tenant->id, $child->id, $cancelled->id);
    $refunds->settle($this->tenant->id, $child->id, $cancelled->id);
    expect(CommissionAward::query()->count())->toBe(1)->and($commission->fresh()->balance)->toBe('5.00000000');
    $refund = $refunds->request($this->tenant->id, $child->id, (string) Str::uuid());
    expect($refunds->settle($this->tenant->id, $child->id, $refund->id)->status)->toBe('COMPLETED');
    expect($commission->fresh()->balance)->toBe('5.00000000');
    $fund->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    expect($commission->fresh()->balance)->toBe('10.00000000');
    expect(LedgerAccount::query()->where('tenant_id', $this->tenant->id)->where('account_type', 'TENANT_COMMISSION_CLEARING')->value('balance'))->toBe('-10.00000000');
    expect(LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', 'USER_SECURITY_DEPOSIT')->value('balance'))->toBe('50.00000000');
    promotionTestWallet($this, $this->user);
    $transferRequest = (string) Str::uuid();
    $transfer = app(TransferCommissionAction::class)->execute($this->tenant->id, $this->user->id, $transferRequest);
    expect($transfer->amount)->toBe('10.00000000')->and($commission->fresh()->balance)->toBe('0.00000000');
    expect(app(TransferCommissionAction::class)->execute($this->tenant->id, $this->user->id, $transferRequest)->id)->toBe($transfer->id);
    $historyQuery = app(CommissionHistoryQuery::class);
    $entryCount = DB::table('ledger_entries')->count();
    $history = $historyQuery->execute($this->tenant->id, $this->user->id);
    expect($history['items'])->toHaveCount(3)
        ->and(array_column($history['items'], 'amount'))->toContain('5.00000000', '-10.00000000')
        ->and(collect($history['items'])->where('kind', 'earned')->pluck('sourceAccountId')->unique()->values()->all())->toBe([$child->fresh()->account_id])
        ->and($historyQuery->execute($this->tenant->id, $child->id)['items'])->toBe([])
        ->and($historyQuery->execute($this->tenant->id, $this->user->id, now($this->tenant->timezone)->subDay()->format('Y-m-d'))['items'])->toBe([])
        ->and($historyQuery->execute($this->tenant->id, $this->user->id, null, 2)['items'])->toBe([])
        ->and(DB::table('ledger_entries')->count())->toBe($entryCount);
    $report = app(PromotionQuery::class)->execute($this->tenant->id, $this->user->id, null);
    expect($report['totals']['invited'])->toBe(1)->and($report['totals']['activated'])->toBe(1)
        ->and($report['totals']['deposits'])->toBe('100.00000000')->and($report['totals']['commission'])->toBe('10.00000000');
    $yesterday = app(PromotionQuery::class)->execute($this->tenant->id, $this->user->id, now($this->tenant->timezone)->subDay()->format('Y-m-d'));
    expect($yesterday['daily']['invited'])->toBe(0)->and($yesterday['details'])->toBe([]);
    $book = app(CompanyFundBookQuery::class)->execute($this->tenant->id, null, 1);
    expect($book['totals']['commissionCost'])->toBe('10.00000000');
    $activity = app(UserWalletQuery::class)->get($this->tenant->id, $this->user->id)['activity'];
    expect(array_column($activity, 'eventType'))->not->toContain('COMMISSION_EARN')->toContain('COMMISSION_TRANSFER');
    DB::statement('SET CONSTRAINTS promotion_funding_evidence, commission_award_evidence, commission_transfer_evidence, deposit_refund_evidence IMMEDIATE');
});

it('keeps earning commission but blocks new transfers during and after own deposit refunds without blocking wallet withdrawals', function (): void {
    $wallet = promotionTestWallet($this, $this->user);
    $configure = app(ConfigurePromotionAction::class);
    $level = $configure->level($this->tenant->id, $this->platform->id, 1, 'Agent', '5', null);
    $parent = $configure->memberLevel($this->tenant->id, $this->platform->id, $this->user->id, $level->id);
    $fund = app(FundSecurityDepositAction::class);
    $fund->execute($this->tenant->id, $this->user->id, (string) Str::uuid(), '50');
    $earn = function (string $suffix) use ($parent, $fund): void {
        $child = $this->user->replicate(['account_id']);
        $child->forceFill(['email' => 'refund-commission-'.$suffix.'@example.test'])->save();
        app(PromotionMembershipAction::class)->ensure($this->tenant->id, $child->id, $parent->id);
        promotionTestWallet($this, $child);
        $fund->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    };
    $earn('before');
    $transfers = app(TransferCommissionAction::class);
    $oldId = (string) Str::uuid();
    $old = $transfers->execute($this->tenant->id, $this->user->id, $oldId);
    $refunds = app(RefundSecurityDepositAction::class);
    $refund = $refunds->request($this->tenant->id, $this->user->id, (string) Str::uuid());
    $earn('pending');
    $commission = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->where('account_type', 'USER_COMMISSION')->firstOrFail();
    $assertBlocked = function () use ($transfers, $commission, $oldId, $old): void {
        $before = DB::table('ledger_entries')->count();
        $amount = $commission->fresh()->balance;
        expect(fn () => $transfers->execute($this->tenant->id, $this->user->id, (string) Str::uuid()))->toThrow(DomainException::class);
        expect($transfers->execute($this->tenant->id, $this->user->id, $oldId)->id)->toBe($old->id);
        $report = app(PromotionQuery::class)->execute($this->tenant->id, $this->user->id, null);
        expect($report['canTransfer'])->toBeFalse()->and($report['commissionRefundRestricted'])->toBeTrue()
            ->and($report['availableCommission'])->toBe($amount)
            ->and(DB::table('ledger_entries')->count())->toBe($before);
        $this->actingAs($this->user, 'tenant_user')->postJson('http://a.localhost/promotion', [
            'action' => 'transfer', 'request_id' => (string) Str::uuid(), 'current_password' => 'local-password', 'confirmed' => true,
        ])->assertStatus(409);
    };
    $withdraw = function () use ($wallet): void {
        $order = app(CreateWithdrawalAction::class)->executeWithAddress(
            $this->tenant->id, $this->user->id, (string) Str::uuid(), 'T'.str_repeat('A', 33), '1', expectedFee: '0');
        expect($order->wallet_id)->toBe($wallet->id)->and($order->status->value)->toBe('PENDING');
    };
    expect($commission->balance)->toBe('5.00000000');
    $assertBlocked();
    $withdraw();
    $refunds->cancel($this->tenant->id, $this->user->id, $refund->id);
    $assertBlocked(); // Restoration has not completed yet.
    $refunds->settle($this->tenant->id, $this->user->id, $refund->id);
    expect(app(PromotionQuery::class)->execute($this->tenant->id, $this->user->id, null)['canTransfer'])->toBeTrue();
    $refund = $refunds->request($this->tenant->id, $this->user->id, (string) Str::uuid());
    $refunds->settle($this->tenant->id, $this->user->id, $refund->id);
    expect($refund->fresh()->status)->toBe('COMPLETED');
    $earn('completed');
    expect($commission->fresh()->balance)->toBe('10.00000000');
    $assertBlocked();
    $withdraw();
    // Another user/company's refund cannot lock this subject through an unscoped lookup.
    expect(CommissionTransferEligibility::refundRestricted((string) Str::uuid(), $this->user->id))->toBeFalse()
        ->and(CommissionTransferEligibility::refundRestricted($this->tenant->id, (string) Str::uuid()))->toBeFalse();
    DB::statement('SET CONSTRAINTS commission_award_evidence, commission_transfer_evidence, deposit_refund_evidence IMMEDIATE');
});

it('binds linked invitations to the browser and OTP challenge and rejects tampering', function (): void {
    Mail::fake();
    $members = app(PromotionMembershipAction::class);
    $inviter = $members->ensure($this->tenant->id, $this->user->id);
    $company = $members->companyInvitation($this->tenant->id);
    $this->get('http://a.localhost/register?invite='.$inviter->invitation_code)->assertOk()->assertInertia(fn ($page) => $page
        ->where('registration.invitationCode', $inviter->invitation_code)->where('registration.invitationLocked', true));
    $payload = ['channel' => 'EMAIL', 'destination' => 'invited@example.test'];
    $this->postJson('http://a.localhost/register/challenges', $payload)->assertUnprocessable()->assertJsonValidationErrors('invitation_code');
    $this->postJson('http://a.localhost/register/challenges', [...$payload, 'invitation_code' => $company->invitation_code])->assertUnprocessable();
    $this->post('http://a.localhost/register/challenges', [...$payload, 'invitation_code' => $inviter->invitation_code])->assertRedirect();
    $challenge = RegistrationChallenge::query()->where('tenant_id', $this->tenant->id)->where('destination', 'invited@example.test')->firstOrFail();
    $code = Mail::sent(UserVerificationCodeMail::class)->first()->code;
    $this->post('http://a.localhost/register/challenges/'.$challenge->id.'/verify', ['code' => $code])->assertRedirect();
    $this->post('http://a.localhost/register/challenges/'.$challenge->id.'/complete', ['password' => 'StrongPass1234', 'password_confirmation' => 'StrongPass1234'])->assertRedirect('/dashboard');
    $newUser = User::query()->where('tenant_id', $this->tenant->id)->where('email', 'invited@example.test')->firstOrFail();
    expect(PromotionMember::query()->where('tenant_id', $this->tenant->id)->where('user_id', $newUser->id)->value('inviter_id'))->toBe($inviter->id);
    expect(fn () => DB::transaction(fn () => $challenge->update(['promotion_inviter_id' => null])))->toThrow(QueryException::class);
});

it('allocates multi-level differences from the company once and preserves earned tariff snapshots', function (): void {
    $members = app(PromotionMembershipAction::class);
    $configure = app(ConfigurePromotionAction::class);
    $levels = [];
    foreach ([1 => '5', 2 => '10', 3 => '15'] as $rank => $reward) {
        $levels[$rank] = $configure->level($this->tenant->id, $this->platform->id, $rank, 'Level '.$rank, $reward, null);
    }
    $parent = $configure->memberLevel($this->tenant->id, $this->platform->id, $this->user->id, $levels[3]->id);
    $chain = [];
    foreach ([2, 1, 0] as $rank) {
        $child = $this->user->replicate(['account_id']);
        $child->forceFill(['email' => 'chain-'.$rank.'@example.test'])->save();
        $chain[$rank] = $child->refresh();
        $parent = $members->ensure($this->tenant->id, $child->id, $parent->id);
        if ($rank > 0) {
            $configure->memberLevel($this->tenant->id, $this->platform->id, $child->id, $levels[$rank]->id);
        }
    }
    promotionTestWallet($this, $child);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $awards = CommissionAward::query()->where('tenant_id', $this->tenant->id)->get();
    expect($awards)->toHaveCount(3)->and($awards->pluck('amount')->all())->toBe(['5.00000000', '5.00000000', '5.00000000']);
    $configure->level($this->tenant->id, $this->platform->id, 3, 'Updated top', '20', 1);
    $report = app(PromotionQuery::class)->execute($this->tenant->id, $this->user->id, null);
    expect($report['totals'])->toBe(['invited' => 3, 'activated' => 1, 'deposits' => '50.00000000', 'commission' => '15.00000000']);
    $details = collect($report['details']);
    $deposit = $details->firstWhere('kind', 'Deposit funded');
    expect($deposit['sourceAccountId'])->toBe($chain[0]->account_id)
        ->and($deposit['inviterAccountId'])->toBe($chain[1]->account_id)
        ->and($deposit['invitedByMe'])->toBeFalse()
        ->and($deposit['depositAmount'])->toBe('50.00000000');
    $commissions = $details->where('kind', 'Commission earned');
    expect($commissions)->toHaveCount(1)
        ->and($report['daily']['commission'])->toBe('5.00000000');
    foreach ($commissions as $row) {
        expect($row['sourceAccountId'])->toBe($chain[0]->account_id)
            ->and($row['inviterAccountId'])->toBe($chain[1]->account_id)
            ->and($row['depositAmount'])->toBe('50.00000000')
            ->and($row['amount'])->toBe('5.00000000')
            ->and($row['accountId'])->toBe($this->user->account_id);
    }
    $directInvitation = $details->where('kind', 'Invitation')->firstWhere('sourceAccountId', $chain[2]->account_id);
    expect($directInvitation['invitedByMe'])->toBeTrue()
        ->and($directInvitation['inviterAccountId'])->toBe($this->user->account_id);
    $inviterReport = app(PromotionQuery::class)->execute($this->tenant->id, $chain[1]->id, null);
    $inviterCommissions = collect($inviterReport['details'])->where('kind', 'Commission earned');
    expect($inviterCommissions)->toHaveCount(1)
        ->and($inviterCommissions->first()['accountId'])->toBe($chain[1]->account_id);
    $directDeposit = collect($inviterReport['details'])->firstWhere('kind', 'Deposit funded');
    expect($directDeposit['invitedByMe'])->toBeTrue()
        ->and($directDeposit['sourceAccountId'])->toBe($chain[0]->account_id);
    $otherTenant = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $otherUser = User::query()->where('tenant_id', $otherTenant->id)->firstOrFail();
    expect(app(PromotionQuery::class)->execute($otherTenant->id, $otherUser->id, null)['details'])->toBe([]);
    $saved = CommissionAward::query()->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->firstOrFail();
    expect($saved->level_revision)->toBe(1)->and($saved->level_reward)->toBe('15.00000000');
    $book = app(CompanyFundBookQuery::class)->execute($this->tenant->id, now($this->tenant->timezone)->subDay()->format('Y-m-d'), 1);
    expect($book['lifetimeTotals']['commissionCost'])->toBe('15.00000000')->and($book['totals']['commissionCost'])->toBe('0');
    expect(fn () => DB::transaction(fn () => $saved->update(['amount' => '999'])))->toThrow(QueryException::class);
    DB::statement('SET CONSTRAINTS promotion_funding_evidence, commission_award_evidence IMMEDIATE');
});

it('restricts company configuration and fund-book access to the resolved company membership', function (): void {
    $this->actingAs($this->admin, 'tenant_admin')->get('http://a.localhost/admin/promotion')->assertOk();
    $this->get('http://a.localhost/admin/company-funds')->assertOk();
    $this->get('http://b.localhost/admin/company-funds')->assertForbidden();
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/promotion?date=invalid')->assertRedirect();
    $this->postJson('http://a.localhost/promotion', ['action' => 'transfer', 'request_id' => (string) Str::uuid(), 'current_password' => 'wrong', 'confirmed' => true])->assertUnprocessable();
});

it('creates scoped unique immutable invitations without changing funds', function (): void {
    $action = app(PromotionMembershipAction::class);
    $member = $action->ensure($this->tenant->id, $this->user->id);
    expect($member->invitation_code)->toMatch('/^[0-9]{6}$/');
    expect($action->ensure($this->tenant->id, $this->user->id)->id)->toBe($member->id);
    expect($action->resolve($this->tenant->id, strtolower($member->invitation_code))->id)->toBe($member->id);
    $other = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    expect(fn () => $action->resolve($other->id, $member->invitation_code))->toThrow(DomainException::class);
    expect(fn () => DB::transaction(fn () => $member->update(['invitation_code' => str_repeat('A', 24)])))->toThrow(QueryException::class);
});

it('migrates legacy company and personal codes without changing relationships or commission history', function (): void {
    $members = app(PromotionMembershipAction::class);
    $company = $members->companyInvitation($this->tenant->id);
    $parent = $members->ensure($this->tenant->id, $this->user->id);
    $level = app(ConfigurePromotionAction::class)->level($this->tenant->id, $this->platform->id, 1, 'Promoter', '5', null);
    $parent->update(['level_id' => $level->id]);
    $child = $this->user->replicate(['account_id']);
    $child->forceFill(['email' => 'migration-child@example.test'])->save();
    $members->ensure($this->tenant->id, $child->id, $parent->id);
    promotionTestWallet($this, $child);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $child->id, (string) Str::uuid(), '50');
    $beforeMembers = DB::table('promotion_members')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $financial = [];
    foreach (['commission_awards', 'commission_transfers', 'promotion_funding_events', 'ledger_entries', 'ledger_postings', 'ledger_accounts'] as $table) {
        $financial[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    expect(CommissionAward::query()->count())->toBeGreaterThan(0);
    // Rebuild only the old invitation schema inside the isolated test transaction.
    DB::unprepared(<<<'SQL'
        DROP TABLE promotion_invitation_aliases;
        DROP TABLE promotion_invitation_counter;
        DROP TRIGGER assign_promotion_invitation_code ON promotion_members;
        DROP TRIGGER assign_promotion_invitation_code ON promotion_company_invitations;
        ALTER TABLE promotion_members DISABLE TRIGGER protect_promotion_members;
        ALTER TABLE promotion_company_invitations DISABLE TRIGGER company_invitation_immutable;
        ALTER TABLE promotion_members DROP CONSTRAINT promotion_invitation_valid;
        ALTER TABLE promotion_company_invitations DROP CONSTRAINT company_invitation_code_format;
        ALTER TABLE promotion_members ALTER COLUMN invitation_code TYPE varchar(24);
        ALTER TABLE promotion_company_invitations ALTER COLUMN invitation_code TYPE varchar(24);
        UPDATE promotion_members SET invitation_code = upper(substr(md5(id::text), 1, 24));
        UPDATE promotion_company_invitations SET invitation_code = upper(substr(md5(id::text), 1, 24));
        ALTER TABLE promotion_members ENABLE TRIGGER protect_promotion_members;
        ALTER TABLE promotion_company_invitations ENABLE TRIGGER company_invitation_immutable;
        ALTER TABLE promotion_members ADD CONSTRAINT promotion_invitation_valid CHECK (invitation_code ~ '^[A-F0-9]{24}$' AND inviter_id IS DISTINCT FROM id);
        ALTER TABLE promotion_company_invitations ADD CONSTRAINT company_invitation_code_format CHECK (invitation_code ~ '^[A-F0-9]{24}$');
        SQL);
    $oldCompany = $company->fresh()->invitation_code;
    $oldParent = $parent->fresh()->invitation_code;
    (require database_path('migrations/2026_09_11_001100_use_sequential_promotion_invitation_codes.php'))->up();
    foreach ($beforeMembers as $before) {
        $after = (array) DB::table('promotion_members')->where('tenant_id', $before['tenant_id'])->where('id', $before['id'])->first();
        unset($before['invitation_code'], $after['invitation_code']);
        expect($after)->toBe($before);
    }
    foreach ($financial as $table => $snapshot) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($snapshot);
    }
    expect($members->enrollment($this->tenant->id, $oldCompany)['companyId'])->toBe($company->id);
    expect($members->enrollment($this->tenant->id, $oldParent)['memberId'])->toBe($parent->id);
    expect(PromotionMember::query()->count())->toBe(User::query()->count());
    expect(DB::table('promotion_company_invitations')->count())->toBe(Tenant::query()->count());
    $codes = DB::table('promotion_members')->pluck('invitation_code')->merge(DB::table('promotion_company_invitations')->pluck('invitation_code'))->sort()->values()->all();
    expect($codes)->toBe(array_map(strval(...), range(523612, 523611 + count($codes))));
    expect(fn () => DB::transaction(fn () => $parent->refresh()->update(['invitation_code' => '999999'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => $company->refresh()->update(['invitation_code' => '999999'])))->toThrow(QueryException::class);
});

it('rejects refund evidence with absent or incorrect business references', function (?string $referenceType): void {
    $wallet = promotionTestWallet($this, $this->user);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $this->user->id, (string) Str::uuid(), '50');
    $refund = app(RefundSecurityDepositAction::class)->request($this->tenant->id, $this->user->id, (string) Str::uuid());
    $deposit = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->where('wallet_id', $wallet->id)->where('account_type', 'USER_SECURITY_DEPOSIT')->firstOrFail();
    $available = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->where('wallet_id', $wallet->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    expect(fn () => DB::transaction(function () use ($refund, $deposit, $available, $referenceType): void {
        $entry = app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'USDT', 'deposit_refund:'.$refund->id, 'SECURITY_DEPOSIT_REFUND', $referenceType, $referenceType ? $refund->id : null, null, [
            new LedgerPostingInstruction($deposit->id, Money::of('-50', 'USDT')),
            new LedgerPostingInstruction($available->id, Money::of('50', 'USDT')),
        ]));
        $refund->update(['status' => 'COMPLETED', 'ledger_entry_id' => $entry->id, 'card_checks' => [], 'completed_at' => now()]);
        DB::statement('SET CONSTRAINTS deposit_refund_evidence IMMEDIATE');
    }))->toThrow(QueryException::class);
    expect($refund->fresh()->status)->toBe('CHECKING')->and($deposit->fresh()->balance)->toBe('50.00000000');
})->with([null, 'WRONG_REFERENCE']);

it('version controls integer tenant tariffs without changing rank or funds', function (): void {
    $configure = app(ConfigurePromotionAction::class);
    $level = $configure->level($this->tenant->id, $this->platform->id, 1, 'Level one', '5', null);
    expect($level->reward_amount)->toBe('5.00000000');
    expect($configure->level($this->tenant->id, $this->platform->id, 1, 'New name', '6', 1)->revision)->toBe(2);
    expect(fn () => $configure->level($this->tenant->id, $this->platform->id, 1, 'Conflict', '7', 1))->toThrow(DomainException::class);
    expect(fn () => $configure->level($this->tenant->id, $this->platform->id, 2, 'Bad higher level', '4', null))->toThrow(DomainException::class);
    expect(fn () => DB::transaction(fn () => PromotionLevel::query()->where('tenant_id', $this->tenant->id)->whereKey($level->id)->update(['rank' => 9])))->toThrow(QueryException::class);
});

it('allows only lower level assignment to an own direct invitee', function (): void {
    $members = app(PromotionMembershipAction::class);
    $configure = app(ConfigurePromotionAction::class);
    $low = $configure->level($this->tenant->id, $this->platform->id, 1, 'Low', '5', null);
    $high = $configure->level($this->tenant->id, $this->platform->id, 2, 'High', '10', null);
    $parent = $configure->memberLevel($this->tenant->id, $this->platform->id, $this->user->id, $high->id);
    $child = $this->user->replicate(['account_id']);
    $child->forceFill(['email' => 'promotion-child@example.test'])->save();
    $childMember = $members->ensure($this->tenant->id, $child->id, $parent->id);
    $members->assignDirectLevel($this->tenant->id, $this->user->id, $childMember->id, $low->id);
    expect($childMember->fresh()->level_id)->toBe($low->id);
    expect(fn () => $members->assignDirectLevel($this->tenant->id, $this->user->id, $childMember->id, $high->id))->toThrow(DomainException::class);
    expect(fn () => $members->assignDirectLevel($this->tenant->id, $child->id, $parent->id, null))->toThrow(ModelNotFoundException::class);
    expect(fn () => DB::transaction(fn () => $parent->update(['inviter_id' => $childMember->id])))->toThrow(QueryException::class);
    expect(PromotionMember::query()->where('tenant_id', $this->tenant->id)->count())->toBe(2);
});

it('filters direct members by account and deposit with only the viewers earned contribution', function (): void {
    $members = app(PromotionMembershipAction::class);
    $configure = app(ConfigurePromotionAction::class);
    $level = $configure->level($this->tenant->id, $this->platform->id, 1, 'Agent', '5', null);
    $parent = $configure->memberLevel($this->tenant->id, $this->platform->id, $this->user->id, $level->id);
    $children = [];
    foreach (['funded', 'unfunded'] as $kind) {
        $child = $this->user->replicate(['account_id']);
        $child->forceFill(['email' => $kind.'-filter@example.test'])->save();
        $members->ensure($this->tenant->id, $child->id, $parent->id);
        $children[$kind] = $child->fresh();
    }
    promotionTestWallet($this, $children['funded']);
    app(FundSecurityDepositAction::class)->execute($this->tenant->id, $children['funded']->id, (string) Str::uuid(), '50');
    $query = app(PromotionQuery::class);
    $funded = $query->execute($this->tenant->id, $this->user->id, null, 1, 1, null, 'funded');
    expect($funded['directTotal'])->toBe(1)->and($funded['direct'][0]['accountId'])->toBe($children['funded']->account_id)
        ->and($funded['direct'][0]['depositAmount'])->toBe('50.00000000')->and($funded['direct'][0]['myCommission'])->toBe('5.00000000');
    $unfunded = $query->execute($this->tenant->id, $this->user->id, null, 1, 1, null, 'unfunded');
    expect($unfunded['directTotal'])->toBe(1)->and($unfunded['direct'][0]['accountId'])->toBe($children['unfunded']->account_id)
        ->and($unfunded['direct'][0]['myCommission'])->toBe('0.00000000');
    $match = $query->execute($this->tenant->id, $this->user->id, null, 1, 1, $children['unfunded']->account_id);
    expect($match['directTotal'])->toBe(1)->and($match['filters']['accountId'])->toBe($children['unfunded']->account_id);
    $other = User::query()->where('tenant_id', '<>', $this->tenant->id)->firstOrFail();
    expect($query->execute($this->tenant->id, $this->user->id, null, 1, 1, $other->account_id)['direct'])->toBe([]);
});
