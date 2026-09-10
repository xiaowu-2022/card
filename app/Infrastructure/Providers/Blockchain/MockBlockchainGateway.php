<?php

namespace App\Infrastructure\Providers\Blockchain;

use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Domain\Withdrawal\DTOs\BlockchainTransferVerification;
use App\Domain\Withdrawal\DTOs\IncomingBlockchainTransfer;
use App\Domain\Withdrawal\Enums\BlockchainVerificationOutcome;
use DateTimeImmutable;

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

    public function listIncomingUsdtTrc20Transfers(string $destination): array
    {
        return array_values(array_map(
            static fn (array $transfer): IncomingBlockchainTransfer => new IncomingBlockchainTransfer(
                strtoupper((string) ($transfer['network'] ?? 'TRON')),
                strtolower((string) $transfer['tx_hash']),
                (int) ($transfer['transfer_index'] ?? 0),
                (string) $transfer['token_contract'],
                (string) ($transfer['destination'] ?? $destination),
                (string) $transfer['amount'],
                (int) ($transfer['confirmations'] ?? 0),
                new DateTimeImmutable((string) ($transfer['occurred_at'] ?? 'now')),
            ),
            (array) config('payment.trc20_mock_incoming_transfers', []),
        ));
    }
}
