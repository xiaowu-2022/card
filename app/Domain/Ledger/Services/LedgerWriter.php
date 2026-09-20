<?php

namespace App\Domain\Ledger\Services;

use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountStatus;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\ValueObjects\Money;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class LedgerWriter
{
    private const MAX_POSTING_INSTRUCTIONS = 100;

    public function __construct(private LedgerEventHasher $hasher) {}

    public function post(LedgerPostingPlan $plan): LedgerEntry
    {
        $postings = $this->consolidate($plan);
        $eventHash = $this->hasher->hash($plan, $postings);

        try {
            return DB::transaction(function () use ($plan, $postings, $eventHash): LedgerEntry {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [$this->hasher->advisoryLockKey($plan->tenantId, $plan->eventKey)]);
                $existing = LedgerEntry::query()->where('tenant_id', $plan->tenantId)->where('event_key', $plan->eventKey)->first();
                if ($existing) {
                    if (! hash_equals($existing->event_hash, $eventHash)) {
                        Log::warning('Ledger idempotency conflict.', [
                            'tenant_id' => $plan->tenantId,
                            'event_key_hash' => hash('sha256', $plan->eventKey),
                            'existing_event_hash' => $existing->event_hash,
                        ]);
                        throw new DomainException('LEDGER_IDEMPOTENCY_CONFLICT', 'This ledger event key was already used with different financial details.', 409);
                    }

                    return $existing;
                }

                $ids = array_map(fn (LedgerPostingInstruction $posting): string => $posting->accountId, $postings);
                sort($ids, SORT_STRING);
                $accounts = LedgerAccount::query()->where('tenant_id', $plan->tenantId)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($accounts->count() !== count($ids)) {
                    throw new DomainException('LEDGER_ACCOUNT_SCOPE_MISMATCH', 'One or more ledger accounts do not belong to this tenant.');
                }
                if ($plan->reversalOfEntryId !== null && ! LedgerEntry::query()->where('tenant_id', $plan->tenantId)->where('asset_code', $plan->assetCode)->whereKey($plan->reversalOfEntryId)->exists()) {
                    throw new DomainException('LEDGER_REVERSAL_REFERENCE_INVALID', 'The reversal entry does not belong to this tenant and asset.');
                }

                $newBalances = [];
                foreach ($postings as $posting) {
                    /** @var LedgerAccount $account */
                    $account = $accounts[$posting->accountId];
                    if ($account->asset_code !== $plan->assetCode || $posting->delta->assetCode !== $plan->assetCode) {
                        throw new DomainException('LEDGER_ASSET_MISMATCH', 'Every ledger account and posting must use the entry asset.');
                    }
                    if ($account->status !== LedgerAccountStatus::Active) {
                        throw new DomainException('LEDGER_ACCOUNT_NOT_ACTIVE', 'Ledger postings require active accounts.');
                    }
                    try {
                        $balance = Money::of($account->balance, $plan->assetCode)->add($posting->delta);
                    } catch (InvalidArgumentException) {
                        throw new DomainException('LEDGER_NUMERIC_OVERFLOW', 'This ledger event exceeds the supported numeric range.');
                    }
                    if ($balance->isNegative() && ! $account->account_type->permitsNegativeBalance()) {
                        Log::warning('Negative ledger balance attempt blocked.', ['tenant_id' => $plan->tenantId, 'account_id' => $account->id]);
                        Log::warning('Negative ledger balance attempt blocked.', [
                            'balance' => $balance,
                            'account_balance' => $account->balance,
                            'accounts' => json_encode($accounts),
                            'tenant_id' => json_encode($plan)
                        ]);
                        throw new DomainException('LEDGER_NEGATIVE_BALANCE', 'This ledger event would create a prohibited negative balance.');
                    }
                    $newBalances[$account->id] = $balance->amount();
                }

                $entryId = (string) Str::uuid();
                $now = now();
                DB::table('ledger_entries')->insert([
                    'id' => $entryId,
                    'tenant_id' => $plan->tenantId,
                    'asset_code' => $plan->assetCode,
                    'event_key' => $plan->eventKey,
                    'event_hash' => $eventHash,
                    'event_type' => $plan->eventType,
                    'reference_type' => $plan->referenceType,
                    'reference_id' => $plan->referenceId,
                    'reversal_of_entry_id' => $plan->reversalOfEntryId,
                    'posted_at' => $now,
                    'created_at' => $now,
                    'sealed_at' => null,
                ]);
                DB::table('ledger_postings')->insert(array_map(fn (LedgerPostingInstruction $posting): array => [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $plan->tenantId,
                    'asset_code' => $plan->assetCode,
                    'ledger_entry_id' => $entryId,
                    'ledger_account_id' => $posting->accountId,
                    'delta' => $posting->delta->amount(),
                    'created_at' => $now,
                ], $postings));
                foreach ($newBalances as $accountId => $balance) {
                    DB::table('ledger_accounts')->where('tenant_id', $plan->tenantId)->where('id', $accountId)->update(['balance' => $balance, 'updated_at' => $now]);
                }
                DB::table('ledger_entries')->where('tenant_id', $plan->tenantId)->where('id', $entryId)->whereNull('sealed_at')->update(['sealed_at' => $now]);

                return LedgerEntry::query()->with('postings')->findOrFail($entryId);
            }, 3);
        } catch (QueryException) {
            Log::error('Ledger database invariant rejected an event.', [
                'tenant_id' => $plan->tenantId,
                'event_key_hash' => hash('sha256', $plan->eventKey),
                'error_category' => 'LEDGER_DATABASE_INVARIANT',
            ]);
            throw new DomainException('LEDGER_DATABASE_INVARIANT', 'The ledger event could not be committed safely.', 409);
        }
    }

    /** @return list<LedgerPostingInstruction> */
    private function consolidate(LedgerPostingPlan $plan): array
    {
        if (count($plan->postings) > self::MAX_POSTING_INSTRUCTIONS) {
            throw new DomainException('LEDGER_POSTING_LIMIT_EXCEEDED', 'A ledger event contains too many posting instructions.');
        }

        $byAccount = [];
        foreach ($plan->postings as $posting) {
            if (! $posting instanceof LedgerPostingInstruction) {
                throw new DomainException('LEDGER_POSTING_INVALID', 'Ledger posting plan contains an invalid instruction.');
            }
            if (! Str::isUuid($posting->accountId)) {
                throw new DomainException('LEDGER_ACCOUNT_INVALID', 'Ledger account identifier is invalid.');
            }
            if ($posting->delta->assetCode !== $plan->assetCode) {
                throw new DomainException('LEDGER_ASSET_MISMATCH', 'Every posting must use the entry asset.');
            }
            if ($posting->delta->isZero()) {
                throw new DomainException('LEDGER_ZERO_POSTING', 'Ledger postings cannot have a zero delta.');
            }
            try {
                $byAccount[$posting->accountId] = isset($byAccount[$posting->accountId])
                    ? $byAccount[$posting->accountId]->add($posting->delta)
                    : $posting->delta;
            } catch (InvalidArgumentException) {
                throw new DomainException('LEDGER_NUMERIC_OVERFLOW', 'This ledger event exceeds the supported numeric range.');
            }
        }
        $result = [];
        $sum = Money::of('0', $plan->assetCode);
        foreach ($byAccount as $accountId => $delta) {
            if ($delta->isZero()) {
                continue;
            }
            $result[] = new LedgerPostingInstruction($accountId, $delta);
            try {
                $sum = $sum->add($delta);
            } catch (InvalidArgumentException) {
                throw new DomainException('LEDGER_NUMERIC_OVERFLOW', 'This ledger event exceeds the supported numeric range.');
            }
        }
        if (count($result) < 2) {
            throw new DomainException('LEDGER_MINIMUM_POSTINGS', 'A ledger entry requires at least two distinct non-zero postings.');
        }
        if (! $sum->isZero()) {
            throw new DomainException('LEDGER_UNBALANCED', 'Ledger postings must sum exactly to zero.');
        }
        usort($result, fn (LedgerPostingInstruction $a, LedgerPostingInstruction $b): int => strcmp($a->accountId, $b->accountId));

        return $result;
    }
}
