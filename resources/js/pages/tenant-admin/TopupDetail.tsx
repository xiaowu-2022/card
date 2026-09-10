import { Head } from '@inertiajs/react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { PageHeader } from '@/components/shared/PageHeader';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';
import type { MoneyAmount } from '@/types/global';

type Order = {
    reference: string;
    userId: string;
    amount: MoneyAmount;
    asset: string;
    status: string;
    provider: string;
    providerStatus: string | null;
    providerReference: string | null;
    ledgerEntryId: string | null;
    createdAt: string;
    paidAt: string | null;
    creditedAt: string | null;
};
export default function TopupDetail({ order }: { order: Order }) {
    const rows = [
        ['Internal order status', order.status],
        ['Provider transaction status', order.providerStatus ?? 'Pending'],
        ['Safe provider reference', order.providerReference ?? '—'],
        ['Ledger entry reference', order.ledgerEntryId ?? '—'],
        ['Created', new Date(order.createdAt).toLocaleString()],
        ['Paid', order.paidAt ? new Date(order.paidAt).toLocaleString() : '—'],
        ['Credited', order.creditedAt ? new Date(order.creditedAt).toLocaleString() : '—'],
    ];
    return (
        <TenantAdminLayout>
            <Head title={`Top-up ${order.reference}`} />
            <PageHeader
                title={`Top-up ${order.reference}`}
                description="Provider and internal settlement timeline. This view is read-only."
            />
            <div className="mt-6 grid gap-6 lg:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle>Amount</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-2xl font-semibold">
                            <MoneyDisplay amount={order.amount} asset={order.asset} compact />
                        </p>
                        <p className="mt-2 font-mono text-xs text-muted-foreground">
                            User {order.userId}
                        </p>
                    </CardContent>
                </Card>
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Settlement details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="divide-y">
                            {rows.map(([label, value]) => (
                                <div key={label} className="grid gap-1 py-3 sm:grid-cols-2">
                                    <dt className="text-sm text-muted-foreground">{label}</dt>
                                    <dd className="break-all text-sm font-medium">{value}</dd>
                                </div>
                            ))}
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </TenantAdminLayout>
    );
}
