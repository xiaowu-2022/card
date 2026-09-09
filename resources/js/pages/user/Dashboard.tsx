import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, CircleDashed, ShieldCheck } from 'lucide-react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { UserLayout } from '@/layouts/UserLayout';

type Props = {
    account: { displayName: string | null; status: string; verifiedChannel: string } | null;
    kycStatus: 'NOT_SUBMITTED' | 'PENDING' | 'APPROVED' | 'REJECTED' | 'RESUBMISSION_REQUIRED';
};

export default function Dashboard({ account, kycStatus }: Props) {
    return (
        <UserLayout>
            <Head title="Dashboard" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Account"
                    title={account?.displayName ? `Welcome, ${account.displayName}` : 'Welcome'}
                    description="Your contact is verified. Complete identity verification when you are ready."
                />
                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Account status</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center justify-between">
                                <span className="text-sm text-muted-foreground">Access</span>
                                <StatusBadge
                                    status="SUCCESS"
                                    label={account?.status ?? 'Preview'}
                                />
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-sm text-muted-foreground">
                                    Verified contact
                                </span>
                                <span className="text-sm font-semibold">
                                    {account?.verifiedChannel ?? '—'}
                                </span>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Next step</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex gap-3">
                                <ShieldCheck className="mt-0.5 size-5 text-primary" />
                                <div>
                                    <p className="font-semibold">Identity verification</p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {kycStatus.replaceAll('_', ' ')}
                                    </p>
                                    <Button asChild size="sm" className="mt-4">
                                        <Link href="/kyc">View verification</Link>
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Getting started</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {[
                            ['Account created', true],
                            ['Contact verified', true],
                            ['Identity verification', kycStatus === 'APPROVED'],
                            ['Wallet', false],
                            ['Security deposit', false],
                            ['Virtual card', false],
                        ].map(([label, done]) => (
                            <div key={String(label)} className="flex items-center gap-3">
                                {done ? (
                                    <CheckCircle2 className="size-5 text-success" />
                                ) : (
                                    <CircleDashed className="size-5 text-muted-foreground" />
                                )}
                                <span className={done ? 'font-medium' : 'text-muted-foreground'}>
                                    {label}
                                </span>
                                {!done && (
                                    <span className="ml-auto text-xs font-medium text-muted-foreground">
                                        Coming later
                                    </span>
                                )}
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </UserLayout>
    );
}
