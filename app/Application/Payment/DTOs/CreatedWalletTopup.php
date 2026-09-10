<?php

namespace App\Application\Payment\DTOs;

use App\Domain\Payment\Models\WalletTopupOrder;

final readonly class CreatedWalletTopup
{
    public function __construct(public WalletTopupOrder $order, public ?string $checkoutUrl, public bool $created) {}
}
