<?php

use App\Domain\Admin\Models\Permission;
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
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->wallet = DB::transaction(fn () => app(WalletProvisioner::class)->provision($this->tenant, $this->user, 'USD'));
    $this->available = auditAccount($this->wallet->id, LedgerAccountType::UserAvailable);
    $this->deposit = auditAccount($this->wallet->id, LedgerAccountType::UserSecurityDeposit);
    $this->clearing = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing)->firstOrFail();
    $this->withdrawalClearing = LedgerAccount::query()->where('tenant_id', $this->tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantWithdrawalClearing)->firstOrFail();
});

function auditAccount(string $walletId, LedgerAccountType $type): LedgerAccount
{
    return LedgerAccount::query()->where('wallet_id', $walletId)->where('account_type', $type)->firstOrFail();
}

/** @param list<LedgerPostingInstruction>|null $postings */
function auditPlan($test, string $key, ?array $postings = null, ?string $referenceId = null, ?string $reversalId = null): LedgerPostingPlan
{
    return new LedgerPostingPlan(
        $test->tenant->id,
        'USD',
        $key,
        'CORE_INTEGRITY_TEST',
        'CORE_TEST',
        $referenceId ?? (string) Str::uuid(),
        $reversalId,
        $postings ?? [
            new LedgerPostingInstruction($test->clearing->id, Money::of('-1', 'USD')),
            new LedgerPostingInstruction($test->available->id, Money::of('1', 'USD')),
        ],
    );
}

it('seals a committed entry and rejects balanced late postings', function (): void {
    $entry = app(LedgerWriter::class)->post(auditPlan($this, 'audit:sealed'));

    expect($entry->sealed_at)->not->toBeNull();
    expect(function () use ($entry): void {
        DB::transaction(function () use ($entry): void {
            DB::table('ledger_postings')->insert([
                ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'asset_code' => 'USD', 'ledger_entry_id' => $entry->id, 'ledger_account_id' => $this->deposit->id, 'delta' => '10.00000000', 'created_at' => now()],
                ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'asset_code' => 'USD', 'ledger_entry_id' => $entry->id, 'ledger_account_id' => $this->withdrawalClearing->id, 'delta' => '-10.00000000', 'created_at' => now()],
            ]);
        });
    })->toThrow(QueryException::class);

    expect(LedgerPosting::query()->where('ledger_entry_id', $entry->id)->count())->toBe(2);
});

it('rejects unsealed and incomplete direct entries at the deferred boundary', function (): void {
    expect(function (): void {
        DB::transaction(function (): void {
            DB::table('ledger_entries')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'asset_code' => 'USD',
                'event_key' => 'audit:direct-incomplete', 'event_hash' => hash('sha256', 'incomplete'),
                'event_type' => 'CORE_INTEGRITY_TEST', 'reference_type' => null, 'reference_id' => null,
                'reversal_of_entry_id' => null, 'posted_at' => now(), 'sealed_at' => null, 'created_at' => now(),
            ]);
            DB::statement('SET CONSTRAINTS ledger_entry_balanced_from_entry IMMEDIATE');
        });
    })->toThrow(QueryException::class);
});

it('rejects sealed one-posting and unbalanced direct entries', function (string $case): void {
    expect(function () use ($case): void {
        DB::transaction(function () use ($case): void {
            $entryId = (string) Str::uuid();
            DB::table('ledger_entries')->insert([
                'id' => $entryId, 'tenant_id' => $this->tenant->id, 'asset_code' => 'USD',
                'event_key' => 'audit:direct-'.$case, 'event_hash' => hash('sha256', $case),
                'event_type' => 'CORE_INTEGRITY_TEST', 'reference_type' => null, 'reference_id' => null,
                'reversal_of_entry_id' => null, 'posted_at' => now(), 'sealed_at' => null, 'created_at' => now(),
            ]);
            DB::table('ledger_postings')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'asset_code' => 'USD',
                'ledger_entry_id' => $entryId, 'ledger_account_id' => $this->clearing->id,
                'delta' => '-2.00000000', 'created_at' => now(),
            ]);
            DB::table('ledger_accounts')->where('id', $this->clearing->id)->update(['balance' => '-2.00000000']);
            if ($case === 'unbalanced') {
                DB::table('ledger_postings')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'asset_code' => 'USD',
                    'ledger_entry_id' => $entryId, 'ledger_account_id' => $this->available->id,
                    'delta' => '1.00000000', 'created_at' => now(),
                ]);
                DB::table('ledger_accounts')->where('id', $this->available->id)->update(['balance' => '1.00000000']);
            }
            DB::table('ledger_entries')->where('id', $entryId)->update(['sealed_at' => now()]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    })->toThrow(QueryException::class);
})->with(['one-posting', 'unbalanced']);

it('rejects direct cached balance tampering and accepts writer cache updates', function (): void {
    expect(function (): void {
        DB::transaction(function (): void {
            DB::table('ledger_accounts')->where('id', $this->available->id)->update(['balance' => '1.00000000']);
            DB::statement('SET CONSTRAINTS ledger_account_cache_from_account IMMEDIATE');
        });
    })->toThrow(QueryException::class);
    expect($this->available->fresh()->balance)->toBe('0.00000000');

    DB::transaction(function (): void {
        app(LedgerWriter::class)->post(auditPlan($this, 'audit:cache-valid'));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    });
    expect($this->available->fresh()->balance)->toBe('1.00000000');
});

it('keeps LedgerWriter inside the outer transaction boundary', function (): void {
    try {
        DB::transaction(function (): void {
            $this->tenant->branding()->update(['support_email' => 'before-rollback@example.test']);
            app(LedgerWriter::class)->post(auditPlan($this, 'audit:nested-rollback'));
            throw new RuntimeException('rollback outer business action');
        });
    } catch (RuntimeException) {
        // Expected business rollback.
    }

    expect(LedgerEntry::query()->where('event_key', 'audit:nested-rollback')->exists())->toBeFalse()
        ->and($this->available->fresh()->balance)->toBe('0.00000000')
        ->and($this->tenant->branding()->value('support_email'))->not->toBe('before-rollback@example.test');

    DB::transaction(function (): void {
        $this->tenant->branding()->update(['support_email' => 'committed@example.test']);
        app(LedgerWriter::class)->post(auditPlan($this, 'audit:nested-commit'));
    });
    expect(LedgerEntry::query()->where('event_key', 'audit:nested-commit')->exists())->toBeTrue()
        ->and($this->available->fresh()->balance)->toBe('1.00000000')
        ->and($this->tenant->branding()->value('support_email'))->toBe('committed@example.test');
});

it('canonicalizes decimal forms ordering and duplicate account instructions', function (): void {
    $reference = (string) Str::uuid();
    $first = app(LedgerWriter::class)->post(auditPlan($this, 'audit:canonical', [
        new LedgerPostingInstruction($this->available->id, Money::of('1', 'USD')),
        new LedgerPostingInstruction($this->clearing->id, Money::of('-0.4', 'USD')),
        new LedgerPostingInstruction($this->clearing->id, Money::of('-0.60000000', 'USD')),
    ], $reference));
    $again = app(LedgerWriter::class)->post(auditPlan($this, 'audit:canonical', [
        new LedgerPostingInstruction($this->clearing->id, Money::of('-1.0', 'USD')),
        new LedgerPostingInstruction($this->available->id, Money::of('1.00000000', 'USD')),
    ], $reference));

    expect($again->id)->toBe($first->id)
        ->and(LedgerEntry::query()->where('event_key', 'audit:canonical')->count())->toBe(1)
        ->and(Money::of('-0', 'USD')->amount())->toBe('0.00000000')
        ->and(fn () => app(LedgerWriter::class)->post(auditPlan($this, 'audit:negative-zero', [
            new LedgerPostingInstruction($this->available->id, Money::of('-0.00000000', 'USD')),
            new LedgerPostingInstruction($this->clearing->id, Money::of('0', 'USD')),
        ])))->toThrow(DomainException::class);
});

it('conflicts on changed references and reversal references', function (): void {
    $reference = (string) Str::uuid();
    $original = app(LedgerWriter::class)->post(auditPlan($this, 'audit:original', referenceId: $reference));
    app(LedgerWriter::class)->post(auditPlan($this, 'audit:reference-conflict', referenceId: $reference));

    foreach ([
        auditPlan($this, 'audit:reference-conflict', referenceId: (string) Str::uuid()),
        auditPlan($this, 'audit:reference-conflict', referenceId: $reference, reversalId: $original->id),
    ] as $plan) {
        try {
            app(LedgerWriter::class)->post($plan);
            $this->fail('Expected an idempotency conflict.');
        } catch (DomainException $exception) {
            expect($exception->errorCode)->toBe('LEDGER_IDEMPOTENCY_CONFLICT');
        }
    }
});

it('maps balance and consolidation overflow to safe domain errors and bounds posting count', function (): void {
    app(LedgerWriter::class)->post(auditPlan($this, 'audit:max-balance', [
        new LedgerPostingInstruction($this->clearing->id, Money::of('-999999999999.99999999', 'USD')),
        new LedgerPostingInstruction($this->available->id, Money::of('999999999999.99999999', 'USD')),
    ]));

    foreach ([
        auditPlan($this, 'audit:balance-overflow', [
            new LedgerPostingInstruction($this->clearing->id, Money::of('-0.00000001', 'USD')),
            new LedgerPostingInstruction($this->available->id, Money::of('0.00000001', 'USD')),
        ]),
        auditPlan($this, 'audit:consolidation-overflow', [
            new LedgerPostingInstruction($this->clearing->id, Money::of('999999999999.99999999', 'USD')),
            new LedgerPostingInstruction($this->clearing->id, Money::of('0.00000001', 'USD')),
            new LedgerPostingInstruction($this->available->id, Money::of('-999999999999.99999999', 'USD')),
            new LedgerPostingInstruction($this->available->id, Money::of('-0.00000001', 'USD')),
        ]),
    ] as $plan) {
        try {
            app(LedgerWriter::class)->post($plan);
            $this->fail('Expected numeric overflow.');
        } catch (DomainException $exception) {
            expect($exception->errorCode)->toBe('LEDGER_NUMERIC_OVERFLOW');
        }
    }

    $tooMany = array_fill(0, 101, new LedgerPostingInstruction($this->available->id, Money::of('1', 'USD')));
    try {
        app(LedgerWriter::class)->post(auditPlan($this, 'audit:posting-limit', $tooMany));
        $this->fail('Expected posting limit rejection.');
    } catch (DomainException $exception) {
        expect($exception->errorCode)->toBe('LEDGER_POSTING_LIMIT_EXCEEDED');
    }
});

it('protects account wallet entry identities and self reversal at the database boundary', function (): void {
    $entry = app(LedgerWriter::class)->post(auditPlan($this, 'audit:identity'));

    expect(fn () => $this->available->forceFill(['account_type' => LedgerAccountType::UserSecurityDeposit])->save())->toThrow(LogicException::class)
        ->and(fn () => $this->wallet->forceFill(['asset_code' => 'EUR'])->save())->toThrow(LogicException::class)
        ->and(fn () => LedgerEntry::query()->create(['tenant_id' => $this->tenant->id]))->toThrow(MassAssignmentException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('ledger_accounts')->where('id', $this->available->id)->update(['asset_code' => 'EUR'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('wallets')->where('id', $this->wallet->id)->update(['asset_code' => 'EUR'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('ledger_entries')->where('id', $entry->id)->update(['sealed_at' => now()->addSecond()])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('ledger_entries')->where('id', $entry->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('ledger_postings')->where('ledger_entry_id', $entry->id)->limit(1)->update(['delta' => '2.00000000'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('ledger_postings')->where('ledger_entry_id', $entry->id)->limit(1)->delete()))->toThrow(QueryException::class)
        ->and(function (): void {
            DB::transaction(function (): void {
                $id = (string) Str::uuid();
                DB::table('ledger_entries')->insert([
                    'id' => $id, 'tenant_id' => $this->tenant->id, 'asset_code' => 'USD', 'event_key' => 'audit:self-reversal',
                    'event_hash' => hash('sha256', 'self'), 'event_type' => 'CORE_INTEGRITY_TEST', 'reference_type' => null,
                    'reference_id' => null, 'reversal_of_entry_id' => $id, 'posted_at' => now(), 'sealed_at' => null, 'created_at' => now(),
                ]);
            });
        })->toThrow(QueryException::class);
});

it('detects controlled corruption without offering reconciliation repair', function (): void {
    app(LedgerWriter::class)->post(auditPlan($this, 'audit:reconcile-corruption'));
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('ALTER TABLE ledger_accounts DISABLE TRIGGER ledger_account_cache_from_account');
    try {
        DB::table('ledger_accounts')->where('id', $this->available->id)->update(['balance' => '2.00000000']);
    } finally {
        DB::statement('ALTER TABLE ledger_accounts ENABLE TRIGGER ledger_account_cache_from_account');
    }

    expect(app(LedgerReconciliationService::class)->mismatches($this->tenant->id, $this->available->id))->toHaveCount(1);
    $this->artisan('ledger:reconcile', ['--tenant' => $this->tenant->id, '--account' => $this->available->id])
        ->assertFailed()->expectsOutputToContain('No data was changed');
    expect($this->available->fresh()->balance)->toBe('2.00000000');
});

it('exposes no generic financial mutation route or permission', function (): void {
    $forbidden = [
        'wallet.credit', 'wallet.debit', 'wallet.adjust',
        'ledger.create', 'ledger.edit', 'ledger.delete', 'ledger.reverse',
        'deposit.adjust',
    ];
    $routes = collect(Route::getRoutes())->filter(function ($route): bool {
        $uri = strtolower($route->uri());

        return preg_match('/wallet|ledger|balance|adjust|credit|debit/', $uri) === 1
            && collect($route->methods())->contains(fn (string $method): bool => ! in_array($method, ['GET', 'HEAD'], true));
    });

    expect(Permission::query()->whereIn('name', $forbidden)->exists())->toBeFalse()
        ->and($routes->pluck('uri')->values()->all())->toBe([
            'wallet/activate',
            'wallet/top-ups',
            'wallet/withdrawal-destinations',
            'wallet/withdrawals',
            'wallet/withdrawals/{withdrawal}/cancel',
        ]);
});
