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
    return (
        <TenantAdminLayout>
            <Head title="Top-ups" />
            <PageHeader
                title="Top-ups"
                description="Read-only wallet funding orders and settlement state."
            />
            <div className="mt-6 overflow-x-auto rounded-xl border bg-surface">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Order</TableHead>
                            <TableHead>User</TableHead>
                            <TableHead>Requested</TableHead>
                            <TableHead>Exact amount</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Provider</TableHead>
                            <TableHead>Created</TableHead>
                            <TableHead>Paid</TableHead>
                            <TableHead>Credited</TableHead>
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
                                    <StatusBadge status={tone(order.status)} label={order.status} />
                                </TableCell>
                                <TableCell>{order.provider}</TableCell>
                                <TableCell>{new Date(order.createdAt).toLocaleString()}</TableCell>
                                <TableCell>
                                    {order.paidAt ? new Date(order.paidAt).toLocaleString() : '—'}
                                </TableCell>
                                <TableCell>
                                    {order.creditedAt
                                        ? new Date(order.creditedAt).toLocaleString()
                                        : '—'}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </TenantAdminLayout>
    );
}
