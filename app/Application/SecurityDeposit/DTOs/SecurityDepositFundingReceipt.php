<?php

namespace App\Application\SecurityDeposit\DTOs;

final readonly class SecurityDepositFundingReceipt
{
    public function __construct(
        public string $ledgerEntryId,
        public string $walletId,
        public string $amount,
        public string $asset,
        public bool $replayed,
    ) {}

    /** @return array{ledgerEntryId:string,walletId:string,amount:string,asset:string,replayed:bool} */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
