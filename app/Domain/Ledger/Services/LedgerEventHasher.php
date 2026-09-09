<?php

namespace App\Domain\Ledger\Services;

use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;

final class LedgerEventHasher
{
    /** @param list<LedgerPostingInstruction> $postings */
    public function hash(LedgerPostingPlan $plan, array $postings): string
    {
        usort($postings, fn (LedgerPostingInstruction $a, LedgerPostingInstruction $b): int => strcmp($a->accountId, $b->accountId));
        $parts = [
            'ledger-event-v1', $plan->tenantId, $plan->assetCode, $plan->eventType,
            $plan->referenceType ?? '', $plan->referenceId ?? '', $plan->reversalOfEntryId ?? '',
        ];
        foreach ($postings as $posting) {
            $parts[] = $posting->accountId;
            $parts[] = $posting->delta->amount();
        }

        return hash('sha256', implode('', array_map(static fn (string $part): string => pack('N', strlen($part)).$part, $parts)));
    }

    public function advisoryLockKey(string $tenantId, string $eventKey): int
    {
        /** @var array{high:int,low:int} $words */
        $words = unpack('Nhigh/Nlow', substr(hash('sha256', "ledger-event-lock-v1\0{$tenantId}\0{$eventKey}", true), 0, 8));

        return ($words['high'] << 32) | $words['low'];
    }
}
