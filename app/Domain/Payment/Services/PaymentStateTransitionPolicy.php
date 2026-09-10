<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Enums\WalletTopupStatus;

final class PaymentStateTransitionPolicy
{
    public function providerTransition(
        PaymentProviderTransactionStatus $current,
        PaymentProviderTransactionStatus $incoming,
    ): string {
        if ($current === $incoming) {
            return 'UNCHANGED';
        }
        if ($current === PaymentProviderTransactionStatus::Succeeded) {
            return in_array($incoming, [PaymentProviderTransactionStatus::Pending, PaymentProviderTransactionStatus::Processing, PaymentProviderTransactionStatus::Unknown], true)
                ? 'STALE'
                : 'CONFLICT';
        }
        if ($current === PaymentProviderTransactionStatus::Failed) {
            return $incoming === PaymentProviderTransactionStatus::Succeeded ? 'CONFLICT' : 'STALE';
        }
        if ($current === PaymentProviderTransactionStatus::Processing && $incoming === PaymentProviderTransactionStatus::Pending) {
            return 'STALE';
        }

        return 'APPLY';
    }

    public function orderMayTransition(WalletTopupStatus $current, WalletTopupStatus $incoming): bool
    {
        if ($current === $incoming) {
            return true;
        }

        return match ($current) {
            WalletTopupStatus::Credited, WalletTopupStatus::Refunded => false,
            WalletTopupStatus::Paid => in_array($incoming, [WalletTopupStatus::Credited, WalletTopupStatus::Refunded], true),
            WalletTopupStatus::Expired => $incoming === WalletTopupStatus::Paid,
            WalletTopupStatus::Failed, WalletTopupStatus::Cancelled => false,
            WalletTopupStatus::Pending, WalletTopupStatus::Processing => $incoming !== WalletTopupStatus::Credited,
        };
    }
}
