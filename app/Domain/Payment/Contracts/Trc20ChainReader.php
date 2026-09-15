<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Withdrawal\DTOs\IncomingBlockchainTransfer;
use DateTimeImmutable;

interface Trc20ChainReader
{
    /** @return list<IncomingBlockchainTransfer> */
    public function lookup(string $txHash, string $destination): array;

    public function confirmedThrough(): DateTimeImmutable;

    /** @return list<IncomingBlockchainTransfer> */
    public function between(string $destination, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
