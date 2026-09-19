import { DepositInstructions } from '@/components/user/DepositInstructions';
import { t, useClientTranslation } from '@/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Clock3, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Button } from '@/components/ui/button';

import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount } from '@/types/global';

type Status =
    | 'WAITING'
    | 'CONFIRMING'
    | 'ADDING_FUNDS'
    | 'PROCESSING'
    | 'COMPLETED'
    | 'FAILED'
    | 'CANCELLED'
    | 'EXPIRED';
type Props = {
    depositFlow?: boolean;
    order: {
        id: string;
        requestedAmount: MoneyAmount;
        expectedAmount: MoneyAmount;
        amount: MoneyAmount;
        asset: string;
        status: Status;
        paymentDetected: boolean;
        network: string | null;
        depositAddress: string | null;
        expiresAt: string | null;
        mockSimulationAvailable: boolean;
    };
};

function remaining(expiresAt: string | null): string {
    if (!expiresAt) return '';
    const seconds = Math.max(0, Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000));
    return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
}

export default function TopupStatus({ order, depositFlow = false }: Props) {
    useClientTranslation();
    const [countdown, setCountdown] = useState(() => remaining(order.expiresAt));
    const [simulating, setSimulating] = useState(false);
    const processing = ['WAITING', 'CONFIRMING', 'ADDING_FUNDS', 'PROCESSING'].includes(
        order.status,
    );
    useEffect(() => {
        if (!processing) return;
        const timer = window.setInterval(() => {
            setCountdown(remaining(order.expiresAt));
            router.reload({ only: ['order'] });
        }, 4000);
        return () => window.clearInterval(timer);
    }, [order.expiresAt, processing]);

    const completed = order.status === 'COMPLETED';
    const expired = order.status === 'EXPIRED';
    const failed = ['FAILED', 'CANCELLED'].includes(order.status);
    const trc20 = order.network === 'TRON' && order.depositAddress;
    const backHref = depositFlow ? '/security-deposit' : '/dashboard';

    if (trc20 && !completed && !failed) {
        return (
            <UserLayout>
                <Head title={t('Top up')} />
                <div className="mx-auto max-w-lg space-y-6">
                    <UserPageHeader title={t('Top up')} backHref={backHref} />
                    <DepositInstructions
                        asset={order.asset}
                        amount={order.expectedAmount}
                        network={order.network}
                        address={order.depositAddress}
                        state={
                            expired || countdown === '00:00'
                                ? 'Top-up expired'
                                : order.paymentDetected
                                  ? 'Payment detected'
                                  : 'Waiting for payment'
                        }
                        expiresAt={order.expiresAt}
                        payable={!expired && !order.paymentDetected && countdown !== '00:00'}
                        newHref={
                            depositFlow
                                ? '/security-deposit'
                                : '/assets/operate?asset=USDT&mode=deposit'
                        }
                    >
                        {order.mockSimulationAvailable && (
                            <Button
                                disabled={simulating}
                                onClick={() => {
                                    setSimulating(true);
                                    router.post(
                                        `/__mock/topups/${order.id}/complete`,
                                        {},
                                        {
                                            preserveScroll: true,
                                            onFinish: () => setSimulating(false),
                                        },
                                    );
                                }}
                            >
                                {simulating ? t('Simulating…') : t('Simulate demo payment')}
                            </Button>
                        )}
                    </DepositInstructions>
                </div>
            </UserLayout>
        );
    }

    const Icon = completed ? CheckCircle2 : failed || expired ? XCircle : Clock3;
    return (
        <UserLayout>
            <Head title={t('Top-up status')} />
            <div className="space-y-6">
                <UserPageHeader title={t('Top-up status')} backHref={backHref} />
                <section className="rounded-[var(--user-radius-lg)] border bg-surface p-6 text-center sm:p-10">
                    <Icon
                        className={`mx-auto size-12 ${completed ? 'text-success' : failed || expired ? 'text-danger' : 'text-warning'}`}
                    />
                    <h1 className="mt-5 text-xl font-semibold">
                        {completed
                            ? t('Top-up complete')
                            : expired
                              ? t('Top-up expired')
                              : failed
                                ? t("Payment wasn't completed")
                                : t('Adding funds to wallet')}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {completed
                            ? t('The full exact transfer is now available in your wallet.')
                            : expired
                              ? t(
                                    'Create a new top-up to continue. No new amount was generated automatically.',
                                )
                              : failed
                                ? t('No funds were added to your wallet.')
                                : t(
                                      'Your payment is confirmed and the wallet credit is being completed.',
                                  )}
                    </p>
                    <p className="mt-5 text-lg font-semibold">
                        <MoneyDisplay
                            amount={order.expectedAmount ?? order.amount}
                            asset={order.asset}
                            compact
                        />
                    </p>
                    <Button asChild className="mt-6 w-full sm:w-auto">
                        <Link
                            href={
                                depositFlow
                                    ? '/security-deposit'
                                    : expired
                                      ? '/wallet/top-up'
                                      : '/dashboard'
                            }
                        >
                            {depositFlow
                                ? t('Security deposit')
                                : expired
                                  ? t('Create new top-up')
                                  : t('Back to home')}
                        </Link>
                    </Button>
                </section>
            </div>
        </UserLayout>
    );
}
