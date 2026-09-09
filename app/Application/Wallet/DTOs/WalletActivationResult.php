<?php

namespace App\Application\Wallet\DTOs;

use App\Domain\Wallet\Models\Wallet;

final readonly class WalletActivationResult
{
    public function __construct(public Wallet $wallet, public bool $created) {}
}
