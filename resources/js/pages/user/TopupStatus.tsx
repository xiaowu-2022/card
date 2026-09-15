import { t, useClientTranslation } from '@/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { Check, CheckCircle2, Clock3, Copy, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import { toast } from 'sonner';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserStatusBanner } from '@/components/user/UserStatusBanner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { UserLayout } from '@/layouts/UserLayout';
import { exactAmount } from '@/lib/exact-amount';
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
    const [instructionsOpen, setInstructionsOpen] = useState(true);
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

    const copy = async (value: string, label: string) => {
        try {
            await navigator.clipboard.writeText(value);
            toast.success(t('{{label}} copied', { label: t(label) }));
        } catch {
            toast.error(t('Could not copy. Please select and copy the value manually.'));
        }
    };
    const completed = order.status === 'COMPLETED';
    const expired = order.status === 'EXPIRED';
    const failed = ['FAILED', 'CANCELLED'].includes(order.status);
    const trc20 = order.network === 'TRON' && order.depositAddress;
    const backHref = depositFlow ? '/security-deposit' : '/dashboard';

    if (trc20 && !completed && !expired && !failed) {
        return (
            <UserLayout>
                <Head title={t('Send USDT')} />
                <div className="space-y-5 sm:space-y-6">
                    <UserPageHeader title={t('Send USDT')} backHref={backHref} />
                    <UserStatusBanner
                        tone={order.paymentDetected ? 'pending' : 'warning'}
                        title={
                            order.paymentDetected ? t('Payment detected') : t('Waiting for payment')
                        }
                        description={
                            order.paymentDetected
                                ? t(
                                      'Confirming transaction. Keep this instruction open while the network confirms it.',
                                  )
                                : t(
                                      'Send the exact amount displayed before the instruction expires.',
                                  )
                        }
                    />
                    <Dialog open={instructionsOpen} onOpenChange={setInstructionsOpen}>
                        <DialogTrigger asChild>
                            <Button className="w-full">{t('View payment instructions')}</Button>
                        </DialogTrigger>
                        <DialogContent
                            closeLabel={t('Close')}
                            className="max-h-[90dvh] overflow-y-auto rounded-3xl p-5 sm:p-6"
                        >
                            <DialogHeader className="pr-10">
                                <DialogTitle>{t('Send USDT')}</DialogTitle>
                                <DialogDescription>
                                    {t(
                                        'The full amount, including the identification decimal, will be credited. Enter every decimal digit shown; this is not a fee.',
                                    )}
                                </DialogDescription>
                            </DialogHeader>
                            <section className="overflow-hidden rounded-2xl border bg-surface">
                                <div className="border-b p-5 sm:p-7">
                                    <p className="text-sm text-muted-foreground">
                                        {t('Amount to send')}
                                    </p>
                                    <p className="mt-2 text-3xl font-semibold tracking-tight">
                                        <MoneyDisplay
                                            amount={order.expectedAmount}
                                            asset="USDT"
                                            compact
                                        />
                                    </p>
                                    <p className="mt-2 text-sm font-medium text-danger">
                                        {t(
                                            'Send this exact amount. A different amount cannot be credited automatically.',
                                        )}
                                    </p>
                                </div>
                                {!order.paymentDetected && countdown !== '00:00' ? (
                                    <div className="border-b p-4 text-center">
                                        <QRCodeSVG
                                            value={order.depositAddress!}
                                            size={188}
                                            marginSize={4}
                                            level="M"
                                            title={t('Deposit address QR code')}
                                            className="mx-auto max-w-full"
                                        />
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            {t(
                                                'Scan for the address, then enter the exact amount above. Use USDT on TRON (TRC20) only.',
                                            )}
                                        </p>
                                    </div>
                                ) : (
                                    <p className="border-b p-4 text-sm font-medium text-warning">
                                        {order.paymentDetected
                                            ? t('Payment detected. Do not send again.')
                                            : t(
                                                  'Payment window ended. Wait for the latest status before creating another top-up.',
                                              )}
                                    </p>
                                )}
                                <dl className="divide-y px-5 sm:px-7">
                                    <div className="flex items-center justify-between gap-4 py-4 text-sm">
                                        <dt className="text-muted-foreground">{t('Network')}</dt>
                                        <dd className="font-semibold">TRC20</dd>
                                    </div>
                                    <div className="py-4 text-sm">
                                        <dt className="text-muted-foreground">
                                            {t('Shared deposit address')}
                                        </dt>
                                        <dd className="mt-2 flex min-w-0 items-center gap-2">
                                            <code className="min-w-0 flex-1 break-all text-sm font-semibold">
                                                {order.depositAddress}
                                            </code>
                                            <Button
                                                size="icon"
                                                variant="secondary"
                                                aria-label={t('Copy address')}
                                                onClick={() =>
                                                    void copy(order.depositAddress!, 'Address')
                                                }
                                            >
                                                <Copy className="size-4" />
                                            </Button>
                                        </dd>
                                    </div>
                                    <div className="flex items-center justify-between gap-4 py-4 text-sm">
                                        <dt className="text-muted-foreground">{t('Requested')}</dt>
                                        <dd className="font-medium">
                                            <MoneyDisplay
                                                amount={order.requestedAmount}
                                                asset="USDT"
                                                compact
                                            />
                                        </dd>
                                    </div>
                                    <div className="flex items-center justify-between gap-4 py-4 text-sm">
                                        <dt className="text-muted-foreground">
                                            {t('Wallet receives')}
                                        </dt>
                                        <dd className="font-semibold">
                                            <MoneyDisplay
                                                amount={order.expectedAmount}
                                                asset="USDT"
                                                compact
                                            />
                                        </dd>
                                    </div>
                                    <div className="flex items-center justify-between gap-4 py-4 text-sm">
                                        <dt className="text-muted-foreground">{t('Expires in')}</dt>
                                        <dd className="text-right font-mono font-semibold">
                                            {order.paymentDetected
                                                ? t('Reserved while confirming')
                                                : countdown}
                                        </dd>
                                    </div>
                                </dl>
                                <div className="grid gap-3 border-t p-5 sm:grid-cols-2 sm:p-7">
                                    <Button
                                        variant="secondary"
                                        onClick={() =>
                                            void copy(exactAmount(order.expectedAmount), 'Amount')
                                        }
                                    >
                                        <Copy className="mr-2 size-4" />
                                        {t('Copy amount')}
                                    </Button>
                                    {order.mockSimulationAvailable ? (
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
                                            <Check className="mr-2 size-4" />
                                            {simulating
                                                ? t('Simulating…')
                                                : t('Simulate demo payment')}
                                        </Button>
                                    ) : null}
                                </div>
                            </section>
                        </DialogContent>
                    </Dialog>
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
