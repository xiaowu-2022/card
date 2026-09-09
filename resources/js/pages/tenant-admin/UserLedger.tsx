import { Head, Link } from '@inertiajs/react';
import { EmptyState } from '@/components/shared/EmptyState';
import { PageHeader } from '@/components/shared/PageHeader';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';

type Entry = {
    id: string;
    reference: string;
    eventType: string;
    asset: string;
    delta: string;
    postedAt: string;
};
type Entries = { data: Entry[] };

export default function UserLedger({ userId, entries }: { userId: string; entries: Entries }) {
    return (
        <TenantAdminLayout>
            <Head title="User ledger" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="User account"
                    title="Ledger activity"
                    description="Read-only financial history relevant to this user."
                    actions={
                        <Button asChild variant="secondary">
                            <Link href={`/admin/users/${userId}/wallet`}>View wallet</Link>
                        </Button>
                    }
                />
                <Card>
                    <CardContent className="p-0">
                        {entries.data.length === 0 ? (
                            <div className="p-6">
                                <EmptyState
                                    title="No ledger activity"
                                    description="No completed financial events have been posted for this user."
                                />
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Date</TableHead>
                                            <TableHead>Reference</TableHead>
                                            <TableHead>Business event</TableHead>
                                            <TableHead>Asset</TableHead>
                                            <TableHead className="text-right">User delta</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {entries.data.map((entry) => (
                                            <TableRow key={entry.id}>
                                                <TableCell>
                                                    {new Date(entry.postedAt).toLocaleString()}
                                                </TableCell>
                                                <TableCell className="font-mono text-xs">
                                                    {entry.reference}
                                                </TableCell>
                                                <TableCell>
                                                    {entry.eventType.replaceAll('_', ' ')}
                                                </TableCell>
                                                <TableCell>{entry.asset}</TableCell>
                                                <TableCell className="text-right font-medium tabular-nums">
                                                    {entry.delta}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </TenantAdminLayout>
    );
}
