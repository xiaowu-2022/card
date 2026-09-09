import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Landmark, ShieldCheck } from 'lucide-react';
import { EmptyState } from '@/components/shared/EmptyState';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount } from '@/types/global';

type Money = { amount: MoneyAmount; asset: string };
type Eligibility = {
    userStatus: string;
    tenantStatus: string;
    kycStatus: string;
    walletStatus: string | null;
    canActivate: boolean;
    depositSatisfied: boolean;
    reasonCodes: string[];
    wallet: { id: string; asset: string; status: string } | null;
    available: Money | null;
    depositCurrent: Money;
    depositRequired: Money;
    depositRemaining: Money;
};
type Props = {
    eligibility: Eligibility;
    activity: Array<{ id: string; eventType: string; asset: string; postedAt: string }>;
};

function WalletBlock({ eligibility }: { eligibility: Eligibility }) {
    if (eligibility.kycStatus === 'APPROVED' && eligibility.canActivate) {
        return (
            <Card>
                <CardContent className="flex flex-col items-start gap-5 p-6 sm:p-8">
                    <div className="grid size-12 place-items-center rounded-full bg-emerald-50 text-emerald-700">
                        <CheckCircle2 className="size-6" />
                    </div>
                    <div>
                        <h2 className="text-xl font-semibold">Your identity is verified.</h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Activate your wallet to create your account in{' '}
                            {eligibility.depositRequired.asset}. No funds move during activation.
                        </p>
                    </div>
                    <Button onClick={() => router.post('/wallet/activate')}>Activate wallet</Button>
                </CardContent>
            </Card>
        );
    }

    const copy: Record<string, string> = {
        NOT_SUBMITTED: 'Verify your identity before activating a wallet.',
        PENDING: 'Your identity review is still in progress.',
        REJECTED: 'Your identity application was not approved. Contact support for next steps.',
        RESUBMISSION_REQUIRED: 'Please submit the requested replacement identity documents.',
    };
    return (
        <Card>
            <CardContent className="flex flex-col items-start gap-5 p-6 sm:p-8">
                <div className="grid size-12 place-items-center rounded-full bg-slate-100 text-slate-700">
                    <ShieldCheck className="size-6" />
                </div>
                <div>
                    <h2 className="text-xl font-semibold">Wallet is not activated</h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {copy[eligibility.kycStatus] ??
                            'Wallet activation is currently unavailable.'}
                    </p>
                </div>
                {(eligibility.kycStatus === 'NOT_SUBMITTED' ||
                    eligibility.kycStatus === 'RESUBMISSION_REQUIRED') && (
                    <Button asChild variant="secondary">
                        <Link href="/kyc">Go to identity verification</Link>
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

export default function Wallet({ eligibility, activity }: Props) {
    const activated = eligibility.wallet !== null;
    return (
        <UserLayout>
            <Head title="Wallet" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Account"
                    title="Wallet"
                    description="Your balances come directly from the immutable account ledger."
                />
                {!activated ? (
                    <WalletBlock eligibility={eligibility} />
                ) : (
                    <>
                        {(eligibility.userStatus !== 'ACTIVE' ||
                            eligibility.tenantStatus !== 'ACTIVE') && (
                            <Alert>
                                <AlertDescription>
                                    This wallet is available in read-only mode while the account or
                                    tenant is restricted.
                                </AlertDescription>
                            </Alert>
                        )}
                        <Card className="bg-slate-950 text-white">
                            <CardContent className="p-6 sm:p-8">
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <p className="text-sm text-slate-300">Available balance</p>
                                        <p className="mt-2 text-3xl font-semibold sm:text-4xl">
                                            {eligibility.available && (
                                                <MoneyDisplay {...eligibility.available} />
                                            )}
                                        </p>
                                    </div>
                                    <StatusBadge
                                        status={
                                            eligibility.walletStatus === 'ACTIVE'
                                                ? 'SUCCESS'
                                                : 'WARNING'
                                        }
                                        label={eligibility.walletStatus ?? 'UNKNOWN'}
                                    />
                                </div>
                                <p className="mt-7 text-xs text-slate-400">
                                    Account asset · {eligibility.wallet?.asset}
                                </p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Landmark className="size-5" />
                                    Security deposit
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-5 sm:grid-cols-3">
                                {[
                                    ['Current', eligibility.depositCurrent],
                                    ['Required', eligibility.depositRequired],
                                    ['Remaining', eligibility.depositRemaining],
                                ].map(([label, money]) => (
                                    <div key={label as string} className="rounded-lg border p-4">
                                        <p className="text-sm text-muted-foreground">
                                            {label as string}
                                        </p>
                                        <p className="mt-2 text-lg font-semibold">
                                            <MoneyDisplay {...(money as Money)} />
                                        </p>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Recent activity</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {activity.length === 0 ? (
                                    <EmptyState
                                        title="No activity yet"
                                        description="Completed financial activity will appear here."
                                    />
                                ) : (
                                    <div className="divide-y">
                                        {activity.map((entry) => (
                                            <div
                                                key={entry.id}
                                                className="flex items-center justify-between gap-4 py-4"
                                            >
                                                <div>
                                                    <p className="font-medium">Account activity</p>
                                                    <p className="text-sm text-muted-foreground">
                                                        {new Date(entry.postedAt).toLocaleString()}
                                                    </p>
                                                </div>
                                                <span className="text-sm text-muted-foreground">
                                                    {entry.asset}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </UserLayout>
    );
}
