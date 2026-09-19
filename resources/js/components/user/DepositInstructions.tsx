import { assetNetworkLabel } from '@/lib/asset-network';
import { t, dateTime } from '@/i18n';
import { Link } from '@inertiajs/react';
import { QRCodeSVG } from 'qrcode.react';
import { toast } from 'sonner';
import { AssetIcon } from './AssetCenter';
import { Button } from '@/components/ui/button';
import { exactAmount } from '@/lib/exact-amount';
import { useState, useEffect, type ReactNode } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogTrigger,
} from '@/components/ui/dialog';

export function DepositInstructions({
    asset,
    amount,
    network,
    address,
    state,
    expiresAt,
    payable,
    newHref,
    children,
}: {
    asset: string;
    amount: string;
    network?: string | null;
    address?: string | null;
    state: string;
    expiresAt?: string | null;
    payable: boolean;
    newHref: string;
    children?: ReactNode;
}) {
    const [open, setOpen] = useState(true);
    const [now, setNow] = useState(Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);
    const seconds = expiresAt
        ? Math.max(0, Math.floor((new Date(expiresAt).getTime() - now) / 1000))
        : 0;
    const countdown = `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
    const copy = async (value: string, label: string) => {
        try {
            await navigator.clipboard.writeText(value);
            toast.success(t('{{label}} copied', { label: t(label) }));
        } catch {
            toast.error(t('Could not copy. Please select and copy the value manually.'));
        }
    };
    const networkLabel = assetNetworkLabel(network, asset);
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <section className="space-y-4 rounded-2xl bg-surface p-5">
                <p className="font-semibold">{t(state)}</p>
                <p>
                    {exactAmount(amount)} {asset} · {networkLabel}
                </p>
                <DialogTrigger asChild>
                    <Button className="w-full">{t('View payment instructions')}</Button>
                </DialogTrigger>
                <Link href={newHref} className="block text-center text-sm underline">
                    {t('Start a new request')}
                </Link>
            </section>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('Top up')} · {asset}
                    </DialogTitle>
                    <DialogDescription>
                        {t(
                            'Send the exact amount shown, including all decimals, using {{network}} only. The full amount will be credited with no identification fee; other amounts cannot be credited automatically.',
                            { network: networkLabel ?? '' },
                        )}
                    </DialogDescription>
                </DialogHeader>
                <section className="space-y-4">
                    <div className="flex items-center gap-3">
                        <AssetIcon asset={asset} />
                        <div>
                            <h2 className="font-semibold">{t(state)}</h2>
                            <p className="text-sm text-muted-foreground">
                                {asset} · {networkLabel}
                            </p>
                        </div>
                    </div>
                    <p className="text-xs text-muted-foreground">{t('Amount to send')}</p>
                    <p className="break-all text-3xl font-semibold">
                        {exactAmount(amount)} <span className="text-base">{asset}</span>
                    </p>
                    {payable && seconds > 0 && address ? (
                        <>
                            <div className="mx-auto w-fit rounded-xl bg-white p-3">
                                <QRCodeSVG value={address} size={176} marginSize={4} />
                            </div>
                            <p className="break-all rounded-xl bg-muted p-3 font-mono text-sm">
                                {address}
                            </p>
                            <div className="grid grid-cols-2 gap-3">
                                <Button
                                    variant="secondary"
                                    onClick={() => void copy(address, 'Address')}
                                >
                                    {t('Copy address')}
                                </Button>
                                <Button
                                    variant="secondary"
                                    onClick={() => void copy(exactAmount(amount), 'Amount')}
                                >
                                    {t('Copy amount')}
                                </Button>
                            </div>
                            {children}
                        </>
                    ) : null}
                    <dl className="divide-y text-sm">
                        <div className="flex justify-between py-3">
                            <dt>{t('Network')}</dt>
                            <dd>{networkLabel}</dd>
                        </div>
                        <div className="flex justify-between py-3">
                            <dt>{t('Wallet credit amount')}</dt>
                            <dd>
                                {exactAmount(amount)} {asset}
                            </dd>
                        </div>
                        <div className="flex justify-between py-3">
                            <dt>{t('Time remaining')}</dt>
                            <dd>{countdown}</dd>
                        </div>
                    </dl>
                    {expiresAt && (
                        <p className="text-xs text-muted-foreground">
                            {t('Valid until {{time}}', { time: dateTime(expiresAt) })}
                        </p>
                    )}
                    <Link href={newHref} className="block py-3 text-center text-sm underline">
                        {t('Start a new request')}
                    </Link>
                </section>
            </DialogContent>
        </Dialog>
    );
}
