import { Head, Link } from '@inertiajs/react';
import { EmptyState } from '@/components/shared/EmptyState';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';
import type { MoneyAmount } from '@/types/global';

type Money = { amount: MoneyAmount; asset: string };
type Wallet = {
    id: string;
    status: string;
    asset: string;
    available: Money;
    securityDeposit: Money;
    securityDepositRequired: Money;
    securityDepositRemaining: Money;
    securityDepositSatisfied: boolean;
    holdTotal: Money;
    createdAt: string;
};

export default function UserWallet({ userId, wallet }: { userId: string; wallet: Wallet | null }) {
    return (
        <TenantAdminLayout>
            <Head title="User wallet" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="User account"
                    title="Wallet"
                    description="Read-only ledger-backed account balances."
                    actions={
                        <Button asChild variant="secondary">
                            <Link href={`/admin/users/${userId}/ledger`}>View ledger</Link>
                        </Button>
                    }
                />
                {wallet ? (
                    <>
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <CardTitle>{wallet.asset} wallet</CardTitle>
                                    <StatusBadge
                                        status={wallet.status === 'ACTIVE' ? 'SUCCESS' : 'WARNING'}
                                        label={wallet.status}
                                    />
                                </div>
                            </CardHeader>
                            <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {[
                                    ['Available', wallet.available],
                                    ['Current deposit', wallet.securityDeposit],
                                    ['Required deposit', wallet.securityDepositRequired],
                                    ['Remaining deposit', wallet.securityDepositRemaining],
                                    ['Holds', wallet.holdTotal],
                                ].map(([label, value]) => (
                                    <div key={label as string} className="rounded-lg border p-4">
                                        <p className="text-sm text-muted-foreground">
                                            {label as string}
                                        </p>
                                        <p className="mt-2 text-xl font-semibold">
                                            <MoneyDisplay {...(value as Money)} />
                                        </p>
                                    </div>
                                ))}
                                <div className="rounded-lg border p-4">
                                    <p className="text-sm text-muted-foreground">Deposit status</p>
                                    <div className="mt-2">
                                        <StatusBadge
                                            status={
                                                wallet.securityDepositSatisfied
                                                    ? 'SUCCESS'
                                                    : 'WARNING'
                                            }
                                            label={
                                                wallet.securityDepositSatisfied
                                                    ? 'Satisfied'
                                                    : 'Required'
                                            }
                                        />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                        <p className="text-sm text-muted-foreground">
                            Activated {new Date(wallet.createdAt).toLocaleString()}
                        </p>
                    </>
                ) : (
                    <EmptyState
                        title="Wallet not activated"
                        description="This user has not activated a wallet. Administrators cannot activate it or change balances."
                    />
                )}
            </div>
        </TenantAdminLayout>
    );
}
