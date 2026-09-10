<?php

namespace App\Domain\Withdrawal\Contracts;

use App\Domain\Withdrawal\DTOs\BlockchainTransferVerification;

interface BlockchainGatewayInterface
{
    public function available(): bool;

    public function verifyUsdtTrc20Transfer(string $txHash, string $expectedAddress, string $expectedAmount): BlockchainTransferVerification;
}
