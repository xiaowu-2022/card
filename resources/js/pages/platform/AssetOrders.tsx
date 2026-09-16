import type { SharedProps } from '@/types/global';
import {
    ManualOperationHistory,
    type ManualOperation,
} from '@/components/admin/ManualOperationHistory';
import { useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PageHeader } from '@/components/shared/PageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t, useAdminTranslation, errorMessage } from '@/i18n/admin';
import { dateTime } from '@/i18n';

type Order = {
    operations?: ManualOperation[];
    legacy?: boolean;
    id: string;
    tenant_id: string;
    company: string;
    user_id: string;
    asset: string;
    network: string;
    amount: string;
    status: string;
    created_at: string;
    operator: string | null;
    operated_at: string | null;
    fee: string | null;
    address: string;
    tx_hash: string;
};
type Props = {
    mode: 'deposit' | 'withdrawal';
    orders: { data: Order[]; next_page_url: string | null; prev_page_url: string | null };
    observations: {
        network: string;
        event_id: string;
        rail_code: string;
        amount: string;
        occurred_at: string;
    }[];
};
export default function AssetOrders({ mode, orders, observations }: Props) {
    useAdminTranslation();
    const title = mode === 'deposit' ? 'Multi-currency deposits' : 'Multi-currency withdrawals';
    return (
        <PlatformLayout>
            <Head title={t(title)} />
            <div className="space-y-6">
                <PageHeader title={t(title)} />
                <div className="flex flex-wrap gap-4 text-sm">
                    <Link className="underline" href="/platform/asset-tron-withdrawals">
                        {'USDT / TRON'}
                    </Link>
                    <Link className="underline" href="/platform/asset-deposits">
                        {t('Multi-currency deposits')}
                    </Link>
                    <Link className="underline" href="/platform/asset-withdrawals">
                        {t('Multi-currency withdrawals')}
                    </Link>
                    <Link className="underline" href="/platform/settings/assets">
                        {t('Multi-currency settings')}
                    </Link>
                </div>
                {orders.data.map((o) => (
                    <OrderRow key={o.id} order={o} mode={mode} />
                ))}
                {orders.data.length === 0 && <p>{t('No records')}</p>}
                <div className="flex justify-between">
                    {orders.prev_page_url && (
                        <Link href={orders.prev_page_url}>{t('Previous')}</Link>
                    )}
                    {orders.next_page_url && <Link href={orders.next_page_url}>{t('Next')}</Link>}
                </div>
                {observations.length > 0 && (
                    <section className="rounded-xl border p-5">
                        <h2 className="font-semibold">{t('Transfers requiring review')}</h2>
                        {observations.map((o) => (
                            <div
                                className="space-y-1 break-all border-b py-3 text-sm"
                                key={o.event_id}
                            >
                                <p>
                                    {o.network} · {o.rail_code}
                                </p>
                                <p>{o.event_id}</p>
                                <p>
                                    {o.amount} · {dateTime(o.occurred_at)}
                                </p>
                            </div>
                        ))}
                    </section>
                )}
            </div>
        </PlatformLayout>
    );
}
function OrderRow({ order: o, mode }: { order: Order; mode: string }) {
    const permissions = usePage<SharedProps>().props.auth.admin?.permissions ?? [];
    const canReview = permissions.includes('withdrawals.review');
    const form = useForm({
        request_id: crypto.randomUUID(),
        tx_hash: o.tx_hash,
        confirmed: false,
        approve: true,
        reason: '',
        password: '',
    });
    const [action, setAction] = useState('');
    const [password, setPassword] = useState('');
    const [address, setAddress] = useState<string | null>(null);
    const [revealError, setRevealError] = useState(false);
    const post = () =>
        form.post(
            `/platform/tenants/${o.tenant_id}/${o.legacy ? 'asset-tron-withdrawals' : 'asset-orders'}/${o.id}/${action}`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAction('');
                    form.reset('confirmed', 'password');
                },
            },
        );
    async function reveal() {
        setRevealError(false);
        try {
            const csrf = decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find((c) => c.startsWith('XSRF-TOKEN='))
                    ?.slice(11) ?? '',
            );
            const response = await fetch(
                `/platform/tenants/${o.tenant_id}/${o.legacy ? 'asset-tron-withdrawals' : 'asset-orders'}/${o.id}/reveal`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ password }),
                },
            );
            if (!response.ok) throw Error();
            const data: unknown = await response.json();
            if (
                !data ||
                typeof data !== 'object' ||
                !('address' in data) ||
                typeof data.address !== 'string'
            )
                throw Error();
            setAddress(data.address);
            setPassword('');
            setTimeout(() => setAddress(null), 30000);
        } catch {
            setRevealError(true);
            setPassword('');
        }
    }
    return (
        <section className="space-y-4 rounded-xl border bg-surface p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="font-semibold">
                        {o.company} · {o.asset} · {o.network}
                    </h2>
                    <p className="mt-1 break-all text-xs text-muted-foreground">{o.id}</p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {dateTime(o.created_at)} · {t(o.status)}
                    </p>
                </div>
                <p className="break-all text-xl font-semibold">
                    {o.amount} {o.asset}
                </p>
            </div>
            <p className="break-all text-sm">{o.address}</p>
            {o.operator && o.operated_at && (
                <p className="text-xs text-muted-foreground">
                    {o.operator} · {dateTime(o.operated_at)}
                </p>
            )}
            {o.fee !== null && (
                <p className="text-sm">
                    {t('Fee')}: {o.fee} {o.asset}
                </p>
            )}
            {mode === 'deposit' &&
                o.status !== 'CREDITED' &&
                (permissions.includes('wallet_topups.verify') ||
                    permissions.includes('wallet_topups.confirm')) && (
                    <div className="flex flex-wrap gap-2">
                        {permissions.includes('wallet_topups.verify') && (
                            <Button variant="secondary" onClick={() => setAction('recheck')}>
                                {t('Recheck transfer')}
                            </Button>
                        )}
                        {permissions.includes('wallet_topups.confirm') &&
                            ['PENDING', 'CONFIRMING'].includes(o.status) && (
                                <Button variant="secondary" onClick={() => setAction('confirm')}>
                                    {t('Manual receipt confirmation')}
                                </Button>
                            )}
                    </div>
                )}
            {canReview && mode === 'withdrawal' && o.status === 'PENDING' && (
                <div className="flex gap-2">
                    <Button
                        onClick={() => {
                            form.setData('approve', true);
                            setAction('review');
                        }}
                    >
                        {t('Approve')}
                    </Button>
                    <Button
                        variant="secondary"
                        onClick={() => {
                            form.setData('approve', false);
                            setAction('review');
                        }}
                    >
                        {t('Reject')}
                    </Button>
                </div>
            )}
            {canReview &&
                mode === 'withdrawal' &&
                ['APPROVED', 'PROCESSING', 'UNKNOWN'].includes(o.status) && (
                    <>
                        <div className="flex flex-wrap gap-2">
                            <Input
                                type="password"
                                className="max-w-xs"
                                value={password}
                                placeholder={t('Current password')}
                                onChange={(e) => setPassword(e.target.value)}
                            />
                            <Button
                                variant="secondary"
                                onClick={() => {
                                    void reveal();
                                }}
                            >
                                {t('Reveal destination')}
                            </Button>
                            <Button onClick={() => setAction('verify')}>
                                {t('Verify payout')}
                            </Button>
                        </div>
                        {address && (
                            <p className="break-all rounded-lg bg-muted p-3 font-mono text-sm">
                                {address}
                            </p>
                        )}
                        {revealError && (
                            <p className="text-destructive">
                                {t('Unable to complete this request.')}
                            </p>
                        )}
                    </>
                )}
            {action && (
                <div className="space-y-3 rounded-xl bg-muted p-4">
                    <p className="text-sm">
                        {t(
                            action === 'confirm'
                                ? 'Confirm receipt of this exact asset and amount. This action credits the account and cannot be undone.'
                                : 'Review the order details before confirming. Unverified payouts remain on hold.',
                        )}
                    </p>
                    {['verify', 'recheck'].includes(action) && (
                        <Input
                            value={form.data.tx_hash}
                            placeholder={t('Transaction hash')}
                            onChange={(e) => form.setData('tx_hash', e.target.value)}
                        />
                    )}
                    {o.legacy && action === 'review' && !form.data.approve && (
                        <Input
                            value={form.data.reason}
                            placeholder={t('Review reason')}
                            onChange={(e) => form.setData('reason', e.target.value)}
                        />
                    )}
                    {action === 'confirm' && (
                        <Input
                            type="password"
                            autoComplete="current-password"
                            value={form.data.password}
                            placeholder={t('Current password')}
                            onChange={(e) => form.setData('password', e.target.value)}
                        />
                    )}
                    <label className="flex min-h-11 items-center gap-3 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.confirmed}
                            onChange={(e) => form.setData('confirmed', e.target.checked)}
                        />
                        {t('I confirm the order details.')}
                    </label>
                    <Button disabled={!form.data.confirmed || form.processing} onClick={post}>
                        {t('Confirm')}
                    </Button>
                </div>
            )}
            {!!o.operations?.length && <ManualOperationHistory operations={o.operations} />}
            {Object.values(form.errors).map((m, i) => (
                <p key={i} role="alert" className="text-sm text-destructive">
                    {errorMessage(m)}
                </p>
            ))}
        </section>
    );
}
