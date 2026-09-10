<?php

namespace App\Infrastructure\Providers\Blockchain;

use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Domain\Withdrawal\DTOs\BlockchainTransferVerification;
use App\Domain\Withdrawal\Enums\BlockchainVerificationOutcome;

final readonly class MockBlockchainGateway implements BlockchainGatewayInterface
{
    public function __construct(private string $mode) {}

    public function available(): bool
    {
        return true;
    }

    public function verifyUsdtTrc20Transfer(string $txHash, string $expectedAddress, string $expectedAmount): BlockchainTransferVerification
    {
        unset($txHash, $expectedAddress, $expectedAmount);
        $outcome = BlockchainVerificationOutcome::tryFrom(strtoupper($this->mode)) ?? BlockchainVerificationOutcome::Failed;

        return new BlockchainTransferVerification($outcome, $outcome === BlockchainVerificationOutcome::Confirmed ? (int) config('withdrawal.minimum_confirmations') : 0);
    }
}
