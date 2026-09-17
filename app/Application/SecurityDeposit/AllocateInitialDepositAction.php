<?php

namespace App\Application\SecurityDeposit;

/** Retired handler: already queued top-up jobs must not select an activation method. */
final class AllocateInitialDepositAction
{
    public function execute(string $tenantId, string $intentId): void {}
}
