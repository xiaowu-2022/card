<?php

use App\Application\Wallet\TenantAdminWalletQuery;
use App\Application\Wallet\UserWalletQuery;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Models\LedgerPosting;
use App\Domain\Ledger\Services\LedgerReconciliationService;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Services\WalletProvisioner;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->wallet = DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $this->user, 'USD'));
    $this->available = LedgerAccount::query()->where('wallet_id', $this->wallet->id)->where('account_type', LedgerAccountType::UserAvailable)->firstOrFail();
    $this->clearing = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing)->firstOrFail();
    $this->fee = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantFeeRevenue)->firstOrFail();
});

function phaseFourPlan($test, string $key, string $amount = '100.00000000', ?array $postings = null): LedgerPostingPlan
{
    return new LedgerPostingPlan($test->tenant->id, 'USD', $key, 'CORE_TEST_TRANSFER', 'CORE_TEST', (string) Str::uuid(), null, $postings ?? [
        new LedgerPostingInstruction($test->clearing->id, Money::of('-'.$amount, 'USD')),
        new LedgerPostingInstruction($test->available->id, Money::of($amount, 'USD')),
    ]);
}

it('posts a balanced single-asset event atomically and permits clearing negative', function (): void {
    $entry = app(LedgerWriter::class)->post(phaseFourPlan($this, 'core:test:balanced'));
    expect($entry->postings)->toHaveCount(2)
        ->and($this->available->fresh()->balance)->toBe('100.00000000')
        ->and($this->clearing->fresh()->balance)->toBe('-100.00000000')
        ->and(LedgerPosting::query()->sum('delta'))->toEqual(0);
});

it('returns user-relevant history through decimal-string read models', function (): void {
    $entry = app(LedgerWriter::class)->post(phaseFourPlan($this, 'core:test:read-model'));
    $userView = app(UserWalletQuery::class)->get($this->tenant->id, $this->user->id);
    $adminView = app(TenantAdminWalletQuery::class)->ledger($this->tenant->id, $this->user->id);

    expect($userView['activity'])->toHaveCount(1)
        ->and($userView['activity'][0]['id'])->toBe($entry->id)
        ->and($adminView['entries']->items())->toHaveCount(1)
        ->and($adminView['entries']->items()[0]['delta'])->toBe('100.00000000');
});

it('rejects unbalanced, one, zero and cross-asset plans before persistence', function (string $case): void {
    $postings = match ($case) {
        'unbalanced' => [new LedgerPostingInstruction($this->clearing->id, Money::of('-100', 'USD')), new LedgerPostingInstruction($this->available->id, Money::of('99', 'USD'))],
        'one' => [new LedgerPostingInstruction($this->available->id, Money::of('1', 'USD'))],
        'zero' => [new LedgerPostingInstruction($this->clearing->id, Money::of('0', 'USD')), new LedgerPostingInstruction($this->available->id, Money::of('1', 'USD'))],
        'asset' => [new LedgerPostingInstruction($this->clearing->id, Money::of('-1', 'USD')), new LedgerPostingInstruction($this->available->id, Money::of('1', 'USDT'))],
    };
    expect(fn () => app(LedgerWriter::class)->post(phaseFourPlan($this, 'core:test:invalid:'.$case, '1.00000000', $postings)))->toThrow(DomainException::class);
    expect(LedgerEntry::query()->count())->toBe(0)->and(LedgerPosting::query()->count())->toBe(0)->and($this->available->fresh()->balance)->toBe('0.00000000');
})->with(['unbalanced', 'one', 'zero', 'asset']);

it('blocks negative user and fee balances without partial history', function (string $account): void {
    $protected = $account === 'user' ? $this->available : $this->fee;
    $plan = phaseFourPlan($this, 'core:test:negative:'.$account, '1.00000000', [
        new LedgerPostingInstruction($protected->id, Money::of('-1', 'USD')),
        new LedgerPostingInstruction($this->clearing->id, Money::of('1', 'USD')),
    ]);
    expect(fn () => app(LedgerWriter::class)->post($plan))->toThrow(DomainException::class);
    expect(LedgerEntry::query()->count())->toBe(0)->and($protected->fresh()->balance)->toBe('0.00000000');
})->with(['user', 'fee']);

it('is idempotent for the same canonical plan and conflicts safely for changed financial details', function (): void {
    $referenceId = (string) Str::uuid();
    $plan = new LedgerPostingPlan($this->tenant->id, 'USD', 'core:test:idempotent', 'CORE_TEST_TRANSFER', 'CORE_TEST', $referenceId, null, [
        new LedgerPostingInstruction($this->available->id, Money::of('100', 'USD')),
        new LedgerPostingInstruction($this->clearing->id, Money::of('-100', 'USD')),
    ]);
    $first = app(LedgerWriter::class)->post($plan);
    $second = app(LedgerWriter::class)->post($plan);
    expect($second->id)->toBe($first->id)->and(LedgerEntry::query()->count())->toBe(1)->and(LedgerPosting::query()->count())->toBe(2)->and($this->available->fresh()->balance)->toBe('100.00000000');

    $changed = new LedgerPostingPlan($this->tenant->id, 'USD', 'core:test:idempotent', 'CORE_TEST_TRANSFER', 'CORE_TEST', $referenceId, null, [
        new LedgerPostingInstruction($this->available->id, Money::of('101', 'USD')),
        new LedgerPostingInstruction($this->clearing->id, Money::of('-101', 'USD')),
    ]);
    try {
        app(LedgerWriter::class)->post($changed);
        $this->fail('Expected idempotency conflict.');
    } catch (DomainException $exception) {
        expect($exception->errorCode)->toBe('LEDGER_IDEMPOTENCY_CONFLICT');
    }
    expect($this->available->fresh()->balance)->toBe('100.00000000')->and(LedgerEntry::query()->count())->toBe(1);
});

it('rejects accounts from another tenant and accounts with another asset', function (string $case): void {
    if ($case === 'tenant') {
        $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
        $userB = User::query()->where('tenant_id', $tenantB->id)->firstOrFail();
        $walletB = DB::transaction(fn () => app(WalletProvisioner::class)->provision($tenantB, $userB, 'USD'));
        $foreign = LedgerAccount::query()->where('wallet_id', $walletB->id)->where('account_type', LedgerAccountType::UserAvailable)->firstOrFail();
    } else {
        $wallet = DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $this->user, 'USDT'));
        $foreign = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserAvailable)->firstOrFail();
    }
    $plan = phaseFourPlan($this, 'core:test:scope:'.$case, '1.00000000', [
        new LedgerPostingInstruction($this->clearing->id, Money::of('-1', 'USD')),
        new LedgerPostingInstruction($foreign->id, Money::of('1', 'USD')),
    ]);
    expect(fn () => app(LedgerWriter::class)->post($plan))->toThrow(DomainException::class);
    expect(LedgerEntry::query()->count())->toBe(0);
})->with(['tenant', 'asset']);

it('keeps entries and postings immutable in Eloquent and PostgreSQL', function (): void {
    $entry = app(LedgerWriter::class)->post(phaseFourPlan($this, 'core:test:immutable'));
    $posting = $entry->postings->first();
    expect(fn () => $entry->forceFill(['event_type' => 'CHANGED_EVENT'])->save())->toThrow(LogicException::class)
        ->and(fn () => $posting->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('ledger_entries')->where('id', $entry->id)->update(['event_type' => 'CHANGED_EVENT'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('ledger_postings')->where('id', $posting->id)->delete()))->toThrow(QueryException::class);
});

it('has a deferred database invariant for minimum posting count and exact balance', function (string $case): void {
    expect(function () use ($case): void {
        DB::transaction(function () use ($case): void {
            $entryId = (string) Str::uuid();
            DB::table('ledger_entries')->insert(['id' => $entryId, 'tenant_id' => $this->tenant->id, 'asset_code' => 'USD', 'event_key' => 'db:test:'.$case, 'event_hash' => hash('sha256', $case), 'event_type' => 'CORE_TEST_TRANSFER', 'reference_type' => null, 'reference_id' => null, 'reversal_of_entry_id' => null, 'posted_at' => now(), 'created_at' => now()]);
            DB::table('ledger_postings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'asset_code' => 'USD', 'ledger_entry_id' => $entryId, 'ledger_account_id' => $this->clearing->id, 'delta' => '-2.00000000', 'created_at' => now()]);
            if ($case === 'unbalanced') {
                DB::table('ledger_postings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'asset_code' => 'USD', 'ledger_entry_id' => $entryId, 'ledger_account_id' => $this->available->id, 'delta' => '1.00000000', 'created_at' => now()]);
            }
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    })->toThrow(QueryException::class);
})->with(['one-posting', 'unbalanced']);

it('reconciles cached balances against posting truth and never repairs mismatches', function (): void {
    app(LedgerWriter::class)->post(phaseFourPlan($this, 'core:test:reconcile'));
    expect(app(LedgerReconciliationService::class)->mismatches())->toBe([]);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('ALTER TABLE ledger_accounts DISABLE TRIGGER ledger_account_cache_from_account');
    try {
        DB::table('ledger_accounts')->where('id', $this->available->id)->update(['balance' => '101.00000000']);
    } finally {
        DB::statement('ALTER TABLE ledger_accounts ENABLE TRIGGER ledger_account_cache_from_account');
    }
    $this->artisan('ledger:reconcile', ['--account' => $this->available->id])->assertFailed()->expectsOutputToContain('No data was changed');
    expect(app(LedgerReconciliationService::class)->mismatches(null, $this->available->id))->toHaveCount(1)
        ->and($this->available->fresh()->balance)->toBe('101.00000000');
});

it('supports exact minimum-unit and maximum NUMERIC(20,8) values and rejects overflow or floats', function (): void {
    app(LedgerWriter::class)->post(phaseFourPlan($this, 'core:test:max', '999999999999.99999999'));
    expect($this->available->fresh()->balance)->toBe('999999999999.99999999')
        ->and(fn () => Money::of('1000000000000.00000000', 'USD'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of(0.1, 'USD'))->toThrow(InvalidArgumentException::class);
});
