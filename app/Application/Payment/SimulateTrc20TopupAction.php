<?php

namespace App\Application\Payment;

use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Withdrawal\DTOs\IncomingBlockchainTransfer;
use App\Support\Errors\DomainException;
use DateTimeImmutable;

final readonly class SimulateTrc20TopupAction
{
    public function __construct(private ProcessIncomingTrc20TransferAction $process) {}

    public function execute(string $tenantId, string $userId, string $orderId): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new DomainException('MOCK_BLOCKCHAIN_UNAVAILABLE', 'Blockchain simulation is unavailable.', 404);
        }
        $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('payment_rail', 'TRC20_SHARED')->whereKey($orderId)->firstOrFail();
        $this->process->execute(new IncomingBlockchainTransfer(
            'TRON', hash('sha256', "demo-trc20-topup\0{$order->id}"), 0, $order->token_contract,
            $order->deposit_address, $order->expected_amount, (int) config('payment.trc20_required_confirmations'),
            new DateTimeImmutable,
        ));
    }
}
