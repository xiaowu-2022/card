import { Head, router, useForm } from '@inertiajs/react';
import { Eye, LockKeyhole } from 'lucide-react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
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
    reviewedAt: string | null;
    reviewReason: string | null;
    txHash: string | null;
    confirmedAt: string | null;
    lastVerificationFailure: string | null;
};

export default function WithdrawalDetail({
    order,
    recentlyAuthenticated,
    revealedAddress,
    verificationAvailable,
}: {
    order: Order;
    recentlyAuthenticated: boolean;
    revealedAddress: string | null;
    verificationAvailable: boolean;
}) {
    const recent = useForm<{ password: string; form?: string }>({ password: '', form: undefined });
    const reject = useForm<{ reason: string; form?: string }>({ reason: '', form: undefined });
    const verify = useForm<{ tx_hash: string; form?: string }>({
        tx_hash: order.txHash ?? '',
        form: undefined,
    });
    const canReject = ['PENDING', 'APPROVED'].includes(order.status) && !order.txHash;
    return (
        <TenantAdminLayout>
            <Head title="Withdrawal review" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Withdrawal"
                    title={`${order.amount} ${order.asset}`}
                    description={`USDT (${order.network}) manual transfer`}
                />
                {recent.errors.form || reject.errors.form || verify.errors.form ? (
                    <Alert className="border-red-200 bg-red-50">
                        <AlertDescription>
                            {recent.errors.form ?? reject.errors.form ?? verify.errors.form}
                        </AlertDescription>
                    </Alert>
                ) : null}
                <div className="grid gap-6 xl:grid-cols-[1.2fr_1fr]">
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Transfer details</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-5 sm:grid-cols-2">
                                <Info
                                    label="Amount"
                                    value={
                                        <MoneyDisplay
                                            amount={order.amount}
                                            asset={order.asset}
                                            compact
                                        />
                                    }
                                />
                                <Info label="Network" value="TRC20" />
                                <Info
                                    label="Destination"
                                    value={revealedAddress ?? order.maskedAddress}
                                />
                                <Info
                                    label="Status"
                                    value={
                                        <StatusBadge
                                            status={
                                                order.status === 'SUCCEEDED'
                                                    ? 'SUCCESS'
                                                    : ['REJECTED', 'CANCELLED'].includes(
                                                            order.status,
                                                        )
                                                      ? 'DANGER'
                                                      : 'WARNING'
                                            }
                                            label={order.status}
                                        />
                                    }
                                />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Withdrawal address</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {!recentlyAuthenticated ? (
                                    <form
                                        className="max-w-sm space-y-4"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            recent.post('/admin/withdrawals/recent-auth', {
                                                onSuccess: () => recent.reset(),
                                            });
                                        }}
                                    >
                                        <Alert>
                                            <LockKeyhole className="size-4" />
                                            <AlertDescription>
                                                Confirm your administrator password before revealing
                                                the full transfer address.
                                            </AlertDescription>
                                        </Alert>
                                        <FormField
                                            id="withdrawal-password"
                                            label="Password"
                                            error={recent.errors.password}
                                        >
                                            <Input
                                                id="withdrawal-password"
                                                type="password"
                                                value={recent.data.password}
                                                onChange={(event) =>
                                                    recent.setData('password', event.target.value)
                                                }
                                                autoComplete="current-password"
                                            />
                                        </FormField>
                                        <Button>Confirm identity</Button>
                                    </form>
                                ) : revealedAddress ? (
                                    <p className="break-all rounded-lg bg-muted p-4 font-mono text-sm">
                                        {revealedAddress}
                                    </p>
                                ) : (
                                    <Button
                                        onClick={() =>
                                            router.post(`/admin/withdrawals/${order.id}/reveal`)
                                        }
                                    >
                                        <Eye className="mr-2 size-4" />
                                        Reveal withdrawal address
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Manual review</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                {order.status === 'PENDING' ? (
                                    <Button
                                        className="w-full"
                                        onClick={() =>
                                            router.post(`/admin/withdrawals/${order.id}/approve`)
                                        }
                                    >
                                        Approve withdrawal
                                    </Button>
                                ) : null}
                                {canReject ? (
                                    <form
                                        className="space-y-3"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            reject.post(`/admin/withdrawals/${order.id}/reject`);
                                        }}
                                    >
                                        <FormField
                                            id="reject-reason"
                                            label="Rejection reason"
                                            error={reject.errors.reason}
                                        >
                                            <Input
                                                id="reject-reason"
                                                value={reject.data.reason}
                                                onChange={(event) =>
                                                    reject.setData('reason', event.target.value)
                                                }
                                            />
                                        </FormField>
                                        <Button className="w-full" variant="destructive">
                                            Reject and return hold
                                        </Button>
                                    </form>
                                ) : null}
                                {['APPROVED', 'VERIFYING'].includes(order.status) ? (
                                    <form
                                        className="space-y-3"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            verify.post(`/admin/withdrawals/${order.id}/verify`);
                                        }}
                                    >
                                        <FormField
                                            id="tx-hash"
                                            label="TRON transaction hash"
                                            error={verify.errors.tx_hash}
                                        >
                                            <Input
                                                id="tx-hash"
                                                value={verify.data.tx_hash}
                                                onChange={(event) =>
                                                    verify.setData('tx_hash', event.target.value)
                                                }
                                                disabled={order.status === 'VERIFYING'}
                                            />
                                        </FormField>
                                        {order.lastVerificationFailure ? (
                                            <Alert>
                                                <AlertDescription>
                                                    Verification did not match:{' '}
                                                    {order.lastVerificationFailure
                                                        .replaceAll('_', ' ')
                                                        .toLowerCase()}
                                                    .
                                                </AlertDescription>
                                            </Alert>
                                        ) : null}
                                        <Button
                                            className="w-full"
                                            disabled={!verificationAvailable || verify.processing}
                                        >
                                            {order.status === 'VERIFYING'
                                                ? 'Verify again'
                                                : 'Submit and verify Tx Hash'}
                                        </Button>
                                        {!verificationAvailable ? (
                                            <p className="text-sm text-muted-foreground">
                                                Blockchain verification is unavailable in this
                                                environment.
                                            </p>
                                        ) : null}
                                    </form>
                                ) : null}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </TenantAdminLayout>
    );
}

function Info({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="min-w-0">
            <p className="text-sm text-muted-foreground">{label}</p>
            <div className="mt-1 break-all font-medium">{value}</div>
        </div>
    );
}
