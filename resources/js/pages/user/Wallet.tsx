import { Head } from '@inertiajs/react';
import { ArrowDownToLine, ArrowUpFromLine } from 'lucide-react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { UserLayout } from '@/layouts/UserLayout';

const transactions = [
    { label: 'Demo funding', date: '8 Sep 2026', amount: '+1,000.00000000', status: 'Completed' },
    {
        label: 'Mock card authorization',
        date: '7 Sep 2026',
        amount: '-42.50000000',
        status: 'Completed',
    },
    { label: 'Demo card load', date: '6 Sep 2026', amount: '-250.00000000', status: 'Pending' },
];
export default function Wallet() {
    return (
        <UserLayout>
            <Head title="Wallet" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Demo wallet"
                    title="Wallet"
                    description="A user-first view of mock balances and activity."
                />
                <Card className="bg-slate-950 text-white">
                    <CardContent className="p-6 sm:p-8">
                        <p className="text-sm text-slate-300">Available balance · MOCK</p>
                        <p className="mt-2 text-4xl font-semibold">
                            <MoneyDisplay amount="12840.25000000" asset="USD" compact />
                        </p>
                        <div className="mt-7 flex gap-3">
                            <Button className="bg-white text-slate-950 hover:bg-slate-100" disabled>
                                <ArrowDownToLine className="size-4" />
                                Top up
                            </Button>
                            <Button
                                className="border border-white/20 bg-white/10 hover:bg-white/15"
                                disabled
                            >
                                <ArrowUpFromLine className="size-4" />
                                Withdraw
                            </Button>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Security deposit</CardTitle>
                    </CardHeader>
                    <CardContent className="flex items-end justify-between gap-4">
                        <div>
                            <p className="text-2xl font-semibold">
                                <MoneyDisplay amount="100.00000000" asset="USD" compact />
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Mock ledger-derived balance preview
                            </p>
                        </div>
                        <StatusBadge status="SUCCESS" label="Requirement met" />
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Recent transactions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-3 md:hidden">
                            {transactions.map((tx) => (
                                <div key={tx.label} className="rounded-lg border p-4">
                                    <div className="flex justify-between gap-3">
                                        <p className="font-medium">{tx.label}</p>
                                        <p className="font-semibold tabular-nums">{tx.amount}</p>
                                    </div>
                                    <div className="mt-2 flex justify-between text-sm text-muted-foreground">
                                        <span>{tx.date}</span>
                                        <span>{tx.status} · DEMO</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                        <div className="hidden md:block">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Description</TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">Amount (USD)</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {transactions.map((tx) => (
                                        <TableRow key={tx.label}>
                                            <TableCell className="font-medium">
                                                {tx.label}
                                            </TableCell>
                                            <TableCell>{tx.date}</TableCell>
                                            <TableCell>{tx.status} · Demo</TableCell>
                                            <TableCell className="text-right font-semibold tabular-nums">
                                                {tx.amount}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </UserLayout>
    );
}
