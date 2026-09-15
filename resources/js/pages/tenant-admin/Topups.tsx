import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
import { Head, Link } from '@inertiajs/react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge, type StatusTone } from '@/components/shared/StatusBadge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';
import type { MoneyAmount } from '@/types/global';

type Order = {
    id: string;
    reference: string;
    userId: string;
    amount: MoneyAmount;
    requestedAmount: MoneyAmount | null;
    expectedAmount: MoneyAmount | null;
    identificationIncrement: MoneyAmount | null;
    asset: string;
    network: string | null;
    status: string;
    provider: string;
    createdAt: string;
    paidAt: string | null;
    creditedAt: string | null;
};
const tone = (status: string): StatusTone =>
    status === 'CREDITED'
        ? 'SUCCESS'
        : status === 'FAILED'
          ? 'DANGER'
          : status === 'PAID'
            ? 'INFO'
            : 'WARNING';
export default function Topups({ orders }: { orders: { data: Order[] } }) {
    useAdminTranslation();
    return (
        <TenantAdminLayout>
            <Head title={t('Top-ups')} />
            <PageHeader
                title={t('Top-ups')}
                description={t('Read-only wallet funding orders and settlement state.')}
            />
            <div className="mt-6 overflow-x-auto rounded-xl border bg-surface">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('Order')}</TableHead>
                            <TableHead>{t('User')}</TableHead>
                            <TableHead>{t('Requested')}</TableHead>
                            <TableHead>{t('Exact amount')}</TableHead>
                            <TableHead>{t('Status')}</TableHead>
                            <TableHead>{t('Provider')}</TableHead>
                            <TableHead>{t('Created')}</TableHead>
                            <TableHead>{t('Paid')}</TableHead>
                            <TableHead>{t('Credited')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {orders.data.map((order) => (
                            <TableRow key={order.id}>
                                <TableCell>
                                    <Link
                                        className="font-medium text-primary hover:underline"
                                        href={`/admin/topups/${order.id}`}
                                    >
                                        {order.reference}
                                    </Link>
                                </TableCell>
                                <TableCell className="font-mono text-xs">
                                    {order.userId.slice(0, 8)}…
                                </TableCell>
                                <TableCell>
                                    <MoneyDisplay
                                        amount={order.requestedAmount ?? order.amount}
                                        asset={order.asset}
                                        compact
                                    />
                                </TableCell>
                                <TableCell>
                                    <MoneyDisplay
                                        amount={order.expectedAmount ?? order.amount}
                                        asset={order.asset}
                                        compact
                                    />
                                    {order.network ? (
                                        <span className="ml-2 text-xs text-muted-foreground">
                                            TRC20
                                        </span>
                                    ) : null}
                                </TableCell>
                                <TableCell>
                                    <StatusBadge
                                        status={tone(order.status)}
                                        label={t(order.status)}
                                    />
                                </TableCell>
                                <TableCell>{order.provider}</TableCell>
                                <TableCell>{dateTime(order.createdAt)}</TableCell>
                                <TableCell>{order.paidAt ? dateTime(order.paidAt) : '—'}</TableCell>
                                <TableCell>
                                    {order.creditedAt ? dateTime(order.creditedAt) : '—'}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </TenantAdminLayout>
    );
}
