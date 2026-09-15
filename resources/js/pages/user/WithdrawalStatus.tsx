import { t, useClientTranslation } from '@/i18n';
import { Head, router } from '@inertiajs/react';
import { CheckCircle2, Clock3, XCircle } from 'lucide-react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { WithdrawalAmounts } from '@/components/user/WithdrawalAmounts';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Button } from '@/components/ui/button';
import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount } from '@/types/global';

type Order = {
    id: string;
    amount: MoneyAmount;
    feeAmount: string;
    receiveAmount: string;
    asset: string;
    network: string;
    maskedAddress: string;
    status: string;
    txHash: string | null;
    reviewReason: string | null;
};

const content: Record<string, [string, string]> = {
    PENDING: ['Withdrawal submitted', 'Pending review'],
    APPROVED: ['Withdrawal approved', "We're processing your transfer."],
    VERIFYING: ['Transfer submitted', "We're confirming the transaction."],
    SUCCEEDED: ['Withdrawal complete', 'Your transfer is confirmed on the TRON network.'],
    REJECTED: ['Withdrawal rejected', 'Funds have been returned to your available balance.'],
    CANCELLED: ['Withdrawal cancelled', 'Funds have been returned to your available balance.'],
};

export default function WithdrawalStatus({ order }: { order: Order }) {
    useClientTranslation();
    const [title, description] = content[order.status] ?? [
        'Withdrawal',
        'Review your withdrawal status.',
    ];
    const Icon =
        order.status === 'SUCCEEDED'
            ? CheckCircle2
            : ['REJECTED', 'CANCELLED'].includes(order.status)
              ? XCircle
              : Clock3;
    return (
        <UserLayout>
            <Head title={t(title)} />
            <div className="space-y-6">
                <UserPageHeader title={t('Withdrawal')} backHref="/wallet/withdrawals" />
                <section className="rounded-[var(--user-radius-lg)] border bg-surface p-6 text-center sm:p-8">
                    <Icon className="mx-auto size-10 text-[var(--user-primary-readable)]" />
                    <h1 className="mt-4 text-2xl font-semibold">{t(title)}</h1>
                    <p className="mt-2 text-sm text-muted-foreground">{t(description)}</p>
                    <p className="mt-6 text-3xl font-semibold">
                        <MoneyDisplay amount={order.amount} asset={order.asset} compact />
                    </p>
                    <dl className="mt-6 space-y-3 border-t pt-5 text-sm">
                        <div className="flex justify-between">
                            <dt className="text-muted-foreground">{t('Network')}</dt>
                            <dd>{order.network}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-muted-foreground">{t('Address')}</dt>
                            <dd>{order.maskedAddress}</dd>
                        </div>
                        {order.txHash ? (
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">{t('Transaction')}</dt>
                                <dd className="max-w-[70%] break-all">{order.txHash}</dd>
                            </div>
                        ) : null}
                    </dl>
                    <div className="mt-4">
                        <WithdrawalAmounts
                            fee={
                                ['REJECTED', 'CANCELLED'].includes(order.status)
                                    ? '0'
                                    : order.feeAmount
                            }
                            receive={
                                ['REJECTED', 'CANCELLED'].includes(order.status)
                                    ? '0'
                                    : order.receiveAmount
                            }
                        />
                    </div>
                    {order.status === 'PENDING' ? (
                        <Button
                            variant="secondary"
                            className="mt-6 w-full sm:w-auto"
                            onClick={() => router.post(`/wallet/withdrawals/${order.id}/cancel`)}
                        >
                            {t('Cancel withdrawal')}
                        </Button>
                    ) : null}
                </section>
            </div>
        </UserLayout>
    );
}
