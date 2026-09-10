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
    return (
        <TenantAdminLayout>
            <Head title="Withdrawals" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Wallet operations"
                    title="Withdrawals"
                    description="Review manual USDT transfers and verify them on-chain."
                />
                <div className="overflow-x-auto rounded-xl border bg-surface">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>User</TableHead>
                                <TableHead>Amount</TableHead>
                                <TableHead>TRC20 address</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Requested</TableHead>
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
                                        {order.amount} {order.asset}
                                    </TableCell>
                                    <TableCell>{order.maskedAddress}</TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={tone(order.status)}
                                            label={order.status}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        {new Date(order.requestedAt).toLocaleString()}
                                    </TableCell>
                                    <TableCell>
                                        <Button asChild variant="ghost" size="sm">
                                            <Link href={`/admin/withdrawals/${order.id}`}>
                                                Review
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
