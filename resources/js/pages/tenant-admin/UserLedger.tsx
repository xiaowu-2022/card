import { displayMoney } from '@/lib/exact-amount';
import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
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
    useAdminTranslation();
    return (
        <TenantAdminLayout>
            <Head title={t('User ledger')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('User account')}
                    title={t('Ledger activity')}
                    description={t('Read-only financial history relevant to this user.')}
                    actions={
                        <Button asChild variant="secondary">
                            <Link href={`/admin/users/${userId}/wallet`}>{t('View wallet')}</Link>
                        </Button>
                    }
                />
                <Card>
                    <CardContent className="p-0">
                        {entries.data.length === 0 ? (
                            <div className="p-6">
                                <EmptyState
                                    title={t('No ledger activity')}
                                    description={t(
                                        'No completed financial events have been posted for this user.',
                                    )}
                                />
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t('Date')}</TableHead>
                                            <TableHead>{t('Reference')}</TableHead>
                                            <TableHead>{t('Business event')}</TableHead>
                                            <TableHead>{t('Asset')}</TableHead>
                                            <TableHead className="text-right">
                                                {t('User delta')}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {entries.data.map((entry) => (
                                            <TableRow key={entry.id}>
                                                <TableCell>{dateTime(entry.postedAt)}</TableCell>
                                                <TableCell className="font-mono text-xs">
                                                    {entry.reference}
                                                </TableCell>
                                                <TableCell>{t(entry.eventType)}</TableCell>
                                                <TableCell>{entry.asset}</TableCell>
                                                <TableCell className="text-right font-medium tabular-nums">
                                                    {displayMoney(entry.delta)}
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
