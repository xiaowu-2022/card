<?php

namespace App\Domain\Withdrawal\DTOs;

use DateTimeImmutable;

final readonly class IncomingBlockchainTransfer
{
    public function __construct(
        public string $network,
        public string $txHash,
        public int $transferIndex,
        public string $tokenContract,
        public string $destination,
        public string $amount,
        public int $confirmations,
        public DateTimeImmutable $occurredAt,
    ) {}
}
