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
    requestedAmount: MoneyAmount | null;
    expectedAmount: MoneyAmount | null;
    identificationIncrement: MoneyAmount | null;
    asset: string;
    network: string | null;
    status: string;
    provider: string;
    providerStatus: string | null;
    providerReference: string | null;
    ledgerEntryId: string | null;
    createdAt: string;
    paidAt: string | null;
    creditedAt: string | null;
    externalPaymentStatus: string;
    internalCreditStatus: string;
    providerException: boolean;
    depositAddress: string | null;
    expiresAt: string | null;
    matchedTxHash: string | null;
    matchedTransferIndex: number | null;
    blockchainDetectedAt: string | null;
    blockchainConfirmedAt: string | null;
};
export default function TopupDetail({ order }: { order: Order }) {
    const rows = [
        ['External payment status', order.externalPaymentStatus],
        ['Internal credit status', order.internalCreditStatus],
        ['Requested amount', order.requestedAmount ?? '—'],
        ['Expected amount', order.expectedAmount ?? order.amount],
        ['Identification increment', order.identificationIncrement ?? '—'],
        ['Network', order.network ? 'TRON / TRC20' : '—'],
        ['Shared deposit address', order.depositAddress ?? '—'],
        ['Expires', order.expiresAt ? new Date(order.expiresAt).toLocaleString() : '—'],
        ['Matched transaction', order.matchedTxHash ?? '—'],
        ['Transfer event index', order.matchedTransferIndex?.toString() ?? '—'],
        [
            'Blockchain detected',
            order.blockchainDetectedAt
                ? new Date(order.blockchainDetectedAt).toLocaleString()
                : '—',
        ],
        [
            'Blockchain confirmed',
            order.blockchainConfirmedAt
                ? new Date(order.blockchainConfirmedAt).toLocaleString()
                : '—',
        ],
        ['Provider event exception', order.providerException ? 'Review required' : 'None'],
        ['Immutable order status', order.status],
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
