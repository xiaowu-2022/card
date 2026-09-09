import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CreditCard, ShieldCheck, WalletCards } from 'lucide-react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { UserLayout } from '@/layouts/UserLayout';

export default function Dashboard() {
    return (
        <UserLayout>
            <Head title="Account overview" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Demo account"
                    title="Good evening, Avery"
                    description="Here is the current status of your mock account."
                />
                <Alert>
                    <AlertTitle>Demo data only</AlertTitle>
                    <AlertDescription>
                        No wallet, KYC, deposit or card business records have been created.
                    </AlertDescription>
                </Alert>
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <p className="text-sm text-muted-foreground">Available balance</p>
                            <CardTitle className="text-3xl">
                                <MoneyDisplay amount="12840.25000000" asset="USD" compact />
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex gap-2">
                            <Button disabled>Top up</Button>
                            <Button variant="secondary" disabled>
                                Withdraw
                            </Button>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <p className="text-sm text-muted-foreground">Account status</p>
                            <CardTitle className="flex items-center gap-2">
                                <ShieldCheck className="size-5 text-success" />
                                Ready
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">KYC</span>
                                <StatusBadge status="SUCCESS" label="Approved · Mock" />
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Deposit</span>
                                <StatusBadge status="SUCCESS" label="Qualified · Mock" />
                            </div>
                        </CardContent>
                    </Card>
                </div>
                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Security deposit</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">
                                <MoneyDisplay amount="100.00000000" asset="USD" compact />
                            </p>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Mock amount · requirement met
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle>Virtual cards</CardTitle>
                                <CreditCard className="size-5 text-primary" />
                            </div>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">1 active</p>
                            <Button asChild variant="ghost" className="mt-2 px-0 text-primary">
                                <Link href="/demo/cards">
                                    Manage demo card <ArrowRight className="size-4" />
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Recent activity</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {[
                            { name: 'Demo balance funded', date: 'Today', amount: '+$1,000.00' },
                            {
                                name: 'Mock card authorization',
                                date: 'Yesterday',
                                amount: '-$42.50',
                            },
                        ].map((item) => (
                            <div
                                key={item.name}
                                className="flex items-center gap-3 border-b pb-4 last:border-0 last:pb-0"
                            >
                                <span className="grid size-10 place-items-center rounded-full bg-muted">
                                    <WalletCards className="size-4" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">{item.name}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {item.date} · DEMO
                                    </p>
                                </div>
                                <p className="text-sm font-semibold tabular-nums">{item.amount}</p>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </UserLayout>
    );
}
