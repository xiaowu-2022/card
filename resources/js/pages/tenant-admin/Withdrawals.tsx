import { displayMoney, exactAmount } from '@/lib/exact-amount';
import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';

type Order = {
    id: string;
    userId: string;
    amount: string;
    feeAmount: string;
    receiveAmount: string;
    asset: string;
    network: string;
    maskedAddress: string;
    status: string;
    requestedAt: string;
};
const tone = (status: string) =>
    status === 'SUCCEEDED'
        ? 'SUCCESS'
        : ['REJECTED', 'CANCELLED'].includes(status)
          ? 'DANGER'
          : 'WARNING';

export default function Withdrawals({ orders }: { orders: { data: Order[] } }) {
    useAdminTranslation();
    return (
        <TenantAdminLayout>
            <Head title={t('Withdrawals')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('Wallet operations')}
                    title={t('Withdrawals')}
                    description={t('Review manual USDT transfers and verify them on-chain.')}
                />
                <div className="overflow-x-auto rounded-xl border bg-surface">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('User')}</TableHead>
                                <TableHead>{t('Amount')}</TableHead>
                                <TableHead>{t('Withdrawal fee')}</TableHead>
                                <TableHead>{t('Amount to send')}</TableHead>
                                <TableHead>{t('TRC20 address')}</TableHead>
                                <TableHead>{t('Status')}</TableHead>
                                <TableHead>{t('Requested')}</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {orders.data.map((order) => (
                                <TableRow key={order.id}>
                                    <TableCell className="font-mono text-xs">
                                        {order.userId.slice(0, 8)}…
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        {displayMoney(order.amount)} {order.asset}
                                    </TableCell>
                                    <TableCell>
                                        {displayMoney(order.feeAmount)} {order.asset}
                                    </TableCell>
                                    <TableCell>
                                        {exactAmount(order.receiveAmount)} {order.asset}
                                    </TableCell>
                                    <TableCell>{order.maskedAddress}</TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={tone(order.status)}
                                            label={t(order.status)}
                                        />
                                    </TableCell>
                                    <TableCell>{dateTime(order.requestedAt)}</TableCell>
                                    <TableCell>
                                        <Button asChild variant="ghost" size="sm">
                                            <Link href={`/admin/withdrawals/${order.id}`}>
                                                {t('Review')}
                                            </Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </TenantAdminLayout>
    );
}
