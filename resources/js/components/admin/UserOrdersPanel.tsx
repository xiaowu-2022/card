import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { t, dateTime } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import { Dialog, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { DetailDrawerContent } from './DetailDrawer';
import { OperationFeedback } from './OperationFeedback';
import { readEditorResponse } from './editor-response';
import { MoneyDisplay } from './MoneyDisplay';
import type { UserInfo } from './UserInfoCell';
import type { AccountPage } from '@/components/shared/PlatformAccountTable';
import { OrderRow, type Order } from '@/pages/platform/AssetOrders';

export function UserOrdersPanel({
    user,
    mode,
}: {
    user: UserInfo;
    mode: 'deposit' | 'withdrawal';
}) {
    const [page, setPage] = useState(1);
    const [status, setStatus] = useState('');
    const [result, setResult] = useState<{ orders: AccountPage<Order>; statuses: string[] } | null>(
        null,
    );
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const [revision, setRevision] = useState(0);
    const [selected, setSelected] = useState<string | null>(null);
    useEffect(() => router.on('finish', () => setRevision((n) => n + 1)), []);
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError(false);
        const params = new URLSearchParams({ page: String(page), status });
        void fetch(
            `/platform/tenants/${encodeURIComponent(user.companyId)}/users/${encodeURIComponent(user.id)}/${mode}-orders?${params}`,
            {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            },
        )
            .then(async (response) => {
                const data = await readEditorResponse(response);
                if (!response.ok || !data.orders || !data.statuses) throw new Error();
                if (!controller.signal.aborted)
                    setResult(data as { orders: AccountPage<Order>; statuses: string[] });
            })
            .catch(() => {
                if (!controller.signal.aborted) setError(true);
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [user.companyId, user.id, mode, page, status, revision]);
    const order = result?.orders.data.find((row) => `${row.source}:${row.id}` === selected);
    return (
        <>
            <div className="min-h-0 flex-1 space-y-4 overflow-auto p-5" aria-busy={loading}>
                <label className="flex items-center gap-3 text-sm">
                    {t('Status')}
                    <select
                        className="h-9 rounded-md border bg-surface px-3"
                        value={status}
                        onChange={(event) => {
                            setStatus(event.target.value);
                            setPage(1);
                            setSelected(null);
                        }}
                    >
                        <option value="">{t('All statuses')}</option>
                        {result?.statuses.map((value) => (
                            <option key={value} value={value}>
                                {t(value)}
                            </option>
                        ))}
                    </select>
                </label>
                {error ? (
                    <>
                        <OperationFeedback role="alert">
                            {t('Unable to load. Please retry.')}
                        </OperationFeedback>
                        <Button onClick={() => setRevision((n) => n + 1)}>{t('Retry')}</Button>
                    </>
                ) : loading ? (
                    <p role="status">{t('Loading…')}</p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b bg-muted/30">
                                <tr>
                                    {['Order', 'Exact amount', 'Status', 'Created', 'Actions'].map(
                                        (label) => (
                                            <th
                                                className="whitespace-nowrap px-3 py-2.5"
                                                key={label}
                                            >
                                                {t(label)}
                                            </th>
                                        ),
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {result?.orders.data.map((row) => (
                                    <tr
                                        className="border-b last:border-0"
                                        key={`${row.source}:${row.id}`}
                                    >
                                        <td className="px-3 py-2.5" title={row.id}>
                                            {row.reference}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2.5">
                                            <MoneyDisplay amount={row.amount} asset={row.asset} />
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2.5">
                                            {t(row.status)}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2.5">
                                            {dateTime(row.created_at)}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <Button
                                                variant="secondary"
                                                onClick={() =>
                                                    setSelected(`${row.source}:${row.id}`)
                                                }
                                            >
                                                {t('View details')}
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                                {result?.orders.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="p-5 text-center text-muted-foreground"
                                        >
                                            {t('No matching records.')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
            {result && (
                <div className="flex shrink-0 items-center justify-between border-t px-5 py-3">
                    <Button
                        variant="secondary"
                        disabled={loading || page <= 1}
                        onClick={() => setPage((n) => n - 1)}
                    >
                        {t('Previous')}
                    </Button>
                    <span className="text-sm">
                        {result.orders.current_page} / {result.orders.last_page} ·{' '}
                        {t('Total records')}: {result.orders.total}
                    </span>
                    <Button
                        variant="secondary"
                        disabled={loading || page >= result.orders.last_page}
                        onClick={() => setPage((n) => n + 1)}
                    >
                        {t('Next')}
                    </Button>
                </div>
            )}
            <Dialog
                open={!!order}
                onOpenChange={(open) => {
                    if (!open) setSelected(null);
                }}
            >
                <DetailDrawerContent className="w-[min(92vw,48rem)]" closeLabel={t('Close')}>
                    <DialogHeader className="shrink-0 pr-10">
                        <DialogTitle>{t('Order details')}</DialogTitle>
                        <DialogDescription>{order?.reference}</DialogDescription>
                    </DialogHeader>
                    <div className="min-h-0 flex-1 overflow-y-auto">
                        {order && <OrderRow key={selected} order={order} mode={mode} />}
                    </div>
                </DetailDrawerContent>
            </Dialog>
        </>
    );
}
