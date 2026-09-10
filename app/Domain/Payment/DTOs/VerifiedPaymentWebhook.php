<?php

namespace App\Domain\Payment\DTOs;

final readonly class VerifiedPaymentWebhook
{
    public function __construct(public string $rawBody) {}
}
