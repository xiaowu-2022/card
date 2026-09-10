<?php

namespace App\Domain\Withdrawal\DTOs;

use App\Domain\Withdrawal\Enums\BlockchainVerificationOutcome;

final readonly class BlockchainTransferVerification
{
    public function __construct(public BlockchainVerificationOutcome $outcome, public int $confirmations = 0) {}
}
