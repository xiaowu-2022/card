<?php

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Promotion\CompanyFundBookQuery;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Application\Wallet\TransferWalletBalanceAction;
use App\Application\Wallet\UserWalletQuery;
use App\Application\Wallet\WalletTransferQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\WalletTransfer;
use App\Support\Errors\DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    legacyUsdAccountingFixtures();
    Storage::fake('private');
    Queue::fake();
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false]);
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->sender = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->recipient = $this->sender->replicate(['account_id']);
    $this->recipient->forceFill(['email' => 'recipient@example.test'])->save();
    $this->recipient->refresh();
    $admin = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    foreach ([$this->sender, $this->recipient] as $user) {
        $application = app(SubmitKycApplicationAction::class)->execute($this->tenant, $user, 'MY', 'TRANSFER-'.$user->id, kycTestImage('front.png'), kycTestImage('back.png'));
        app(ApproveKycAction::class)->execute($this->tenant->id, $application->id, $admin);
        app(ActivateUserWalletAction::class)->execute($this->tenant->id, $user->id);
    }
    $this->senderAccount = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->where('user_id', $this->sender->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $this->recipientAccount = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->where('user_id', $this->recipient->id)->where('account_type', 'USER_AVAILABLE')->firstOrFail();
    $clearing = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->where('asset_code', 'USD')->where('account_type', 'TENANT_TOPUP_CLEARING')->firstOrFail();
    app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'USD', 'transfer_test:'.Str::uuid(), 'TEST_TOPUP', null, null, null, [
        new LedgerPostingInstruction($clearing->id, Money::of('-100', 'USD')),
        new LedgerPostingInstruction($this->senderAccount->id, Money::of('100', 'USD')),
    ]));
    $this->action = app(TransferWalletBalanceAction::class);
    $this->payload = ['request_id' => (string) Str::uuid(), 'recipient_account_id' => $this->recipient->account_id, 'amount' => '10.25', 'current_password' => 'local-password', 'confirmed' => true];
});

it('atomically transfers the exact amount, exposes both activity directions and preserves company totals', function (): void {
    $book = app(CompanyFundBookQuery::class)->execute($this->tenant->id, null, 1);
    $receipt = $this->action->execute($this->tenant->id, $this->sender->id, $this->recipient->account_id, '10.25', $this->payload['request_id']);
    DB::statement('SET CONSTRAINTS wallet_transfer_evidence, wallet_transfer_entry_evidence IMMEDIATE');
    expect($receipt->amount)->toBe('10.25000000')->and($this->senderAccount->fresh()->balance)->toBe('89.75000000')->and($this->recipientAccount->fresh()->balance)->toBe('10.25000000');
    foreach ([$this->sender->id => '-10.25000000', $this->recipient->id => '10.25000000'] as $userId => $amount) {
        $query = app(UserWalletQuery::class)->get($this->tenant->id, $userId);
        expect($query['transferAvailable'])->toBeTrue();
        expect(collect($query['activity'])->firstWhere('eventType', 'WALLET_TRANSFER')['amount'])->toBe($amount);
        expect(app(WalletTransferQuery::class)->get($this->tenant->id, $userId, $receipt->id)['receipt']['recipientAccountId'])->toBe($this->recipient->account_id);
    }
    expect(app(CompanyFundBookQuery::class)->execute($this->tenant->id, null, 1)['totals'])->toBe($book['totals']);
    expect(DB::table('commission_awards')->count())->toBe(0)->and(DB::table('promotion_funding_events')->count())->toBe(0);
    expect(LedgerAccount::query()->where('tenant_id', $this->tenant->id)->where('account_type', 'USER_SECURITY_DEPOSIT')->pluck('balance')->all())->toBe(['0.00000000', '0.00000000']);
});

it('reuses the receipt for identical requests and rejects changed intent', function (): void {
    $id = $this->payload['request_id'];
    $receipt = $this->action->execute($this->tenant->id, $this->sender->id, $this->recipient->account_id, '10', $id);
    expect($this->action->execute($this->tenant->id, $this->sender->id, $this->recipient->account_id, '10.00', $id)->id)->toBe($receipt->id);
    expect(fn () => $this->action->execute($this->tenant->id, $this->sender->id, $this->recipient->account_id, '11', $id))->toThrow(DomainException::class, 'different details');
    expect(fn () => $this->action->execute($this->tenant->id, $this->sender->id, $this->sender->account_id, '10', $id))->toThrow(DomainException::class, 'different details');
    expect(WalletTransfer::query()->count())->toBe(1)->and($this->senderAccount->fresh()->balance)->toBe('90.00000000');
});

it('rejects insufficient funds and malformed amounts without posting money', function (string $amount): void {
    expect(fn () => $this->action->execute($this->tenant->id, $this->sender->id, $this->recipient->account_id, $amount, (string) Str::uuid()))->toThrow(DomainException::class);
    expect(WalletTransfer::query()->count())->toBe(0)->and($this->senderAccount->fresh()->balance)->toBe('100.00000000')->and($this->recipientAccount->fresh()->balance)->toBe('0.00000000');
})->with(['100.01', '0', '-1', '1.001', '1e1', '01', '1000000000000', 'NaN']);

it('denies self, unknown and other-company recipients without disclosing contact data', function (): void {
    $other = User::query()->where('tenant_id', '<>', $this->tenant->id)->firstOrFail();
    foreach ([$this->sender->account_id, '200001010000', $other->account_id] as $accountId) {
        expect(fn () => $this->action->execute($this->tenant->id, $this->sender->id, $accountId, '10', (string) Str::uuid()))->toThrow(DomainException::class, 'recipient is unavailable');
    }
    expect(WalletTransfer::query()->count())->toBe(0);
});

it('denies ineligible parties and missing same-currency wallets', function (string $kind): void {
    if ($kind === 'tenant') {
        $this->tenant->update(['status' => 'SUSPENDED']);
    } elseif ($kind === 'wallet') {
        DB::table('wallets')->where('id', $this->recipientAccount->wallet_id)->update(['status' => 'SUSPENDED']);
    } elseif ($kind === 'unverified') {
        $user = $this->sender->replicate(['account_id']);
        $user->forceFill(['email' => 'unverified@example.test'])->save();
        $this->recipient = $user->refresh();
    } else {
        ($kind === 'sender' ? $this->sender : $this->recipient)->update(['status' => 'SUSPENDED']);
    }
    expect(fn () => $this->action->execute($this->tenant->id, $this->sender->id, $this->recipient->account_id, '10', (string) Str::uuid()))->toThrow(DomainException::class);
    expect(WalletTransfer::query()->count())->toBe(0)->and($this->senderAccount->fresh()->balance)->toBe('100.00000000');
})->with(['tenant', 'sender', 'recipient', 'wallet', 'unverified']);

it('requires password, explicit confirmation and trusted ownership inputs at the endpoint', function (string $field, mixed $value): void {
    $this->actingAs($this->sender, 'tenant_user')->postJson('http://a.localhost/wallet/transfers', [...$this->payload, $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(WalletTransfer::query()->count())->toBe(0);
})->with([
    ['current_password', 'wrong'], ['confirmed', false], ['amount', 10], ['amount', '1.001'], ['recipient_account_id', 'bad'],
    ['tenant_id', 'untrusted'], ['recipient_user_id', 'untrusted'], ['wallet_id', 'untrusted'], ['asset_code', 'USD'], ['fee', '0'], ['request_id', 'bad'],
]);

it('returns a scoped receipt after password-confirmed HTTP submission and safely handles replay', function (): void {
    $response = $this->actingAs($this->sender, 'tenant_user')->post('http://a.localhost/wallet/transfers', $this->payload);
    $receipt = WalletTransfer::query()->sole();
    $response->assertRedirect('/wallet/transfers/'.$receipt->id);
    $this->post('http://a.localhost/wallet/transfers', $this->payload)->assertRedirect('/wallet/transfers/'.$receipt->id);
    $this->get('http://a.localhost/wallet/transfers/'.$receipt->id)->assertOk()->assertInertia(fn ($page) => $page->component('user/Transfer')->where('receipt.amount', '10.25000000')->where('receipt.sent', true));
    $this->actingAs($this->recipient, 'tenant_user')->get('http://a.localhost/wallet/transfers/'.$receipt->id)->assertOk()->assertInertia(fn ($page) => $page->where('receipt.sent', false));
    $third = $this->sender->replicate(['account_id']);
    $third->forceFill(['email' => 'third@example.test'])->save();
    expect(fn () => app(WalletTransferQuery::class)->get($this->tenant->id, $third->id, $receipt->id))->toThrow(ModelNotFoundException::class);
    $other = User::query()->where('tenant_id', '<>', $this->tenant->id)->firstOrFail();
    $this->actingAs($other, 'tenant_user')->get('http://b.localhost/wallet/transfers/'.$receipt->id)->assertNotFound();
    expect(WalletTransfer::query()->count())->toBe(1);
});

it('rolls back the complete transfer with its outer transaction', function (): void {
    expect(fn () => DB::transaction(function (): void {
        $this->action->execute($this->tenant->id, $this->sender->id, $this->recipient->account_id, '10', (string) Str::uuid());
        throw new RuntimeException('outer rollback');
    }))->toThrow(RuntimeException::class, 'outer rollback');
    expect(WalletTransfer::query()->count())->toBe(0)->and($this->senderAccount->fresh()->balance)->toBe('100.00000000');
    expect(DB::table('ledger_entries')->where('event_type', 'WALLET_TRANSFER')->count())->toBe(0);
});

it('protects receipts from update and deletion at database level', function (): void {
    $receipt = $this->action->execute($this->tenant->id, $this->sender->id, $this->recipient->account_id, '10', (string) Str::uuid());
    foreach (['update', 'delete'] as $operation) {
        expect(fn () => DB::transaction(function () use ($receipt, $operation): void {
            $query = DB::table('wallet_transfers')->where('id', $receipt->id);
            $operation === 'update' ? $query->update(['amount' => '11']) : $query->delete();
        }))->toThrow(QueryException::class);
    }
});

it('rejects wallet-transfer ledger entries without an exact receipt', function (): void {
    expect(fn () => DB::transaction(function (): void {
        $id = (string) Str::uuid();
        app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'USD', 'wallet_transfer:'.$id, 'WALLET_TRANSFER', 'WALLET_TRANSFER', $id, null, [
            new LedgerPostingInstruction($this->senderAccount->id, Money::of('-10', 'USD')),
            new LedgerPostingInstruction($this->recipientAccount->id, Money::of('10', 'USD')),
        ]));
        DB::statement('SET CONSTRAINTS wallet_transfer_entry_evidence IMMEDIATE');
    }))->toThrow(QueryException::class);
    expect($this->senderAccount->fresh()->balance)->toBe('100.00000000');
});

it('rejects receipts with missing reference type or mismatched posting amounts', function (?string $referenceType, string $receiptAmount): void {
    expect(fn () => DB::transaction(function () use ($referenceType, $receiptAmount): void {
        $id = (string) Str::uuid();
        $entry = app(LedgerWriter::class)->post(new LedgerPostingPlan($this->tenant->id, 'USD', 'wallet_transfer:'.$id, 'WALLET_TRANSFER', $referenceType, $id, null, [
            new LedgerPostingInstruction($this->senderAccount->id, Money::of('-10', 'USD')),
            new LedgerPostingInstruction($this->recipientAccount->id, Money::of('10', 'USD')),
        ]));
        WalletTransfer::query()->create(['id' => $id, 'tenant_id' => $this->tenant->id, 'sender_user_id' => $this->sender->id,
            'recipient_user_id' => $this->recipient->id, 'sender_wallet_id' => $this->senderAccount->wallet_id, 'recipient_wallet_id' => $this->recipientAccount->wallet_id,
            'recipient_account_id' => $this->recipient->account_id, 'request_id' => (string) Str::uuid(), 'asset_code' => 'USD', 'amount' => $receiptAmount, 'ledger_entry_id' => $entry->id]);
        DB::statement('SET CONSTRAINTS wallet_transfer_evidence, wallet_transfer_entry_evidence IMMEDIATE');
    }))->toThrow($referenceType === null ? DomainException::class : QueryException::class);
    expect($this->senderAccount->fresh()->balance)->toBe('100.00000000')->and(WalletTransfer::query()->count())->toBe(0);
})->with([[null, '10'], ['WRONG', '10'], ['WALLET_TRANSFER', '11']]);

it('does not allow administrator sessions to act as wallet senders', function (): void {
    $this->get('http://a.localhost/wallet/transfer')->assertRedirect();
    $admin = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($admin, 'tenant_admin')->post('http://a.localhost/wallet/transfers', $this->payload)->assertRedirect();
    expect(WalletTransfer::query()->count())->toBe(0);
});
