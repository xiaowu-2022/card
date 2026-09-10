<?php

namespace App\Infrastructure\Providers\Blockchain;

use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Domain\Withdrawal\DTOs\BlockchainTransferVerification;
use App\Support\Errors\DomainException;

final class UnavailableBlockchainGateway implements BlockchainGatewayInterface
{
    public function available(): bool
    {
        return false;
    }

    public function verifyUsdtTrc20Transfer(string $txHash, string $expectedAddress, string $expectedAmount): BlockchainTransferVerification
    {
        throw new DomainException('BLOCKCHAIN_VERIFICATION_UNAVAILABLE', 'Blockchain verification is currently unavailable.', 503);
    }

    public function listIncomingUsdtTrc20Transfers(string $destination): array
    {
        throw new DomainException('BLOCKCHAIN_MONITOR_UNAVAILABLE', 'Blockchain monitoring is currently unavailable.', 503);
    }
}
