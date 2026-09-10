<?php

namespace App\Domain\Withdrawal\Contracts;

use App\Domain\Withdrawal\DTOs\BlockchainTransferVerification;
use App\Domain\Withdrawal\DTOs\IncomingBlockchainTransfer;

interface BlockchainGatewayInterface
{
    public function available(): bool;

    public function verifyUsdtTrc20Transfer(string $txHash, string $expectedAddress, string $expectedAmount): BlockchainTransferVerification;

    /** @return list<IncomingBlockchainTransfer> */
    public function listIncomingUsdtTrc20Transfers(string $destination): array;
}
