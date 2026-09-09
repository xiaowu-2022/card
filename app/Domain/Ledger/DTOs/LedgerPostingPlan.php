<?php

namespace App\Domain\Ledger\DTOs;

use App\Support\Errors\DomainException;
use Illuminate\Support\Str;

final readonly class LedgerPostingPlan
{
    /** @param list<LedgerPostingInstruction> $postings */
    public function __construct(
        public string $tenantId,
        public string $assetCode,
        public string $eventKey,
        public string $eventType,
        public ?string $referenceType,
        public ?string $referenceId,
        public ?string $reversalOfEntryId,
        public array $postings,
    ) {
        if (! Str::isUuid($tenantId)) {
            throw new DomainException('LEDGER_TENANT_INVALID', 'Ledger tenant identifier is invalid.');
        }
        if (preg_match('/^[A-Z0-9]{3,12}$/', $assetCode) !== 1) {
            throw new DomainException('LEDGER_ASSET_INVALID', 'Ledger asset code is invalid.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,254}$/', $eventKey) !== 1) {
            throw new DomainException('LEDGER_EVENT_KEY_INVALID', 'Ledger event key is invalid.');
        }
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $eventType) !== 1) {
            throw new DomainException('LEDGER_EVENT_TYPE_INVALID', 'Ledger event type is invalid.');
        }
        if (($referenceType === null) !== ($referenceId === null)) {
            throw new DomainException('LEDGER_REFERENCE_INVALID', 'Ledger reference must contain both type and id.');
        }
        if (($referenceId !== null && ! Str::isUuid($referenceId)) || ($reversalOfEntryId !== null && ! Str::isUuid($reversalOfEntryId))) {
            throw new DomainException('LEDGER_REFERENCE_INVALID', 'Ledger references must use valid UUIDs.');
        }
        if ($referenceType !== null && preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $referenceType) !== 1) {
            throw new DomainException('LEDGER_REFERENCE_INVALID', 'Ledger reference type is invalid.');
        }
    }
}
