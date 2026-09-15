import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
import { Head } from '@inertiajs/react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { PageHeader } from '@/components/shared/PageHeader';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';
import type { MoneyAmount } from '@/types/global';
import {
    ManualOperationHistory,
    type ManualOperation,
} from '@/components/admin/ManualOperationHistory';

type Order = {
    manualOperations: ManualOperation[];
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
    useAdminTranslation();
    const rows: [string, string][] = [
        ['External payment status', t(order.externalPaymentStatus)],
        ['Internal credit status', t(order.internalCreditStatus)],
        ['Requested amount', order.requestedAmount ?? '—'],
        ['Expected amount', order.expectedAmount ?? order.amount],
        ['Identification increment', order.identificationIncrement ?? '—'],
        ['Network', order.network ? 'TRON / TRC20' : '—'],
        ['Shared deposit address', order.depositAddress ?? '—'],
        ['Expires', order.expiresAt ? dateTime(order.expiresAt) : '—'],
        ['Matched transaction', order.matchedTxHash ?? '—'],
        ['Transfer event index', order.matchedTransferIndex?.toString() ?? '—'],
        [
            'Blockchain detected',
            order.blockchainDetectedAt ? dateTime(order.blockchainDetectedAt) : '—',
        ],
        [
            'Blockchain confirmed',
            order.blockchainConfirmedAt ? dateTime(order.blockchainConfirmedAt) : '—',
        ],
        ['Provider event exception', t(order.providerException ? 'Review required' : 'None')],
        ['Immutable order status', t(order.status)],
        ['Safe provider reference', order.providerReference ?? '—'],
        ['Ledger entry reference', order.ledgerEntryId ?? '—'],
        ['Created', dateTime(order.createdAt)],
        ['Paid', order.paidAt ? dateTime(order.paidAt) : '—'],
        ['Credited', order.creditedAt ? dateTime(order.creditedAt) : '—'],
    ];
    return (
        <TenantAdminLayout>
            <Head title={t('Top-up {{value1}}', { value1: order.reference })} />
            <PageHeader
                title={t('Top-up {{value1}}', { value1: order.reference })}
                description={t(
                    'Provider and internal settlement timeline. This view is read-only.',
                )}
            />
            <div className="mt-6 grid gap-6 lg:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Amount')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-2xl font-semibold">
                            <MoneyDisplay amount={order.amount} asset={order.asset} compact />
                        </p>
                        <p className="mt-2 font-mono text-xs text-muted-foreground">
                            {t('User {{value1}}', { value1: order.userId })}
                        </p>
                    </CardContent>
                </Card>
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>{t('Settlement details')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="divide-y">
                            {rows.map(([label, value]) => (
                                <div key={label} className="grid gap-1 py-3 sm:grid-cols-2">
                                    <dt className="text-sm text-muted-foreground">{t(label)}</dt>
                                    <dd className="break-all text-sm font-medium">{value}</dd>
                                </div>
                            ))}
                        </dl>
                    </CardContent>
                </Card>
            </div>
            <div className="mt-6">
                <ManualOperationHistory operations={order.manualOperations} />
            </div>
        </TenantAdminLayout>
    );
}
