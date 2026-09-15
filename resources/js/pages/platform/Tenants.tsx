import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { EmptyState } from '@/components/shared/EmptyState';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge, type StatusTone } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import type { SharedProps } from '@/types/global';

type TenantRow = {
    id: string;
    name: string;
    slug: string;
    domain: string | null;
    status: 'DRAFT' | 'ACTIVE' | 'SUSPENDED' | 'CLOSED';
    createdAt: string;
    inflow?: string;
    outflow?: string;
};
type Paginator = {
    data: TenantRow[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
};
const tones: Record<TenantRow['status'], StatusTone> = {
    DRAFT: 'NEUTRAL',
    ACTIVE: 'SUCCESS',
    SUSPENDED: 'WARNING',
    CLOSED: 'DANGER',
};

export default function Tenants({
    tenants,
    filters,
    totals,
    financialAccess,
}: {
    tenants: Paginator;
    filters: { search?: string; status?: string };
    totals: { inflow?: string; outflow?: string };
    financialAccess: { inflow: boolean; outflow: boolean };
}) {
    useAdminTranslation();
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? 'ALL');
    const canManage =
        usePage<SharedProps>().props.auth.admin?.permissions.includes('tenant.manage');
    const apply = (event?: FormEvent) => {
        event?.preventDefault();
        router.get(
            '/platform/tenants',
            { search: search || undefined, status: status === 'ALL' ? undefined : status },
            { preserveState: true, replace: true },
        );
    };

    return (
        <PlatformLayout>
            <Head title={t('Tenants')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('Platform directory')}
                    title={t('Tenants')}
                    description={t(
                        'Create tenant foundations and control their lifecycle without deleting historical records.',
                    )}
                    actions={
                        canManage ? (
                            <Button asChild>
                                <Link href="/platform/tenants/create">{t('Create tenant')}</Link>
                            </Button>
                        ) : undefined
                    }
                />
                {(financialAccess.inflow || financialAccess.outflow) && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {financialAccess.inflow && (
                            <div className="rounded-xl border bg-surface p-5">
                                <p className="text-sm text-muted-foreground">{t('Total inflow')}</p>
                                <p className="mt-2 break-all text-2xl font-semibold">
                                    <MoneyDisplay amount={totals.inflow!} asset="USDT" />
                                </p>
                            </div>
                        )}
                        {financialAccess.outflow && (
                            <div className="rounded-xl border bg-surface p-5">
                                <p className="text-sm text-muted-foreground">
                                    {t('Total outflow')}
                                </p>
                                <p
                                    className="mt-2 break-all text-2xl font-semibold"
                                    title={t('Successful withdrawal amounts including fees.')}
                                >
                                    <MoneyDisplay amount={totals.outflow!} asset="USDT" />
                                </p>
                            </div>
                        )}
                    </div>
                )}
                <div className="overflow-hidden rounded-xl border bg-surface">
                    <form
                        className="grid gap-3 border-b p-4 sm:grid-cols-[minmax(0,1fr)_12rem_auto]"
                        onSubmit={apply}
                    >
                        <div className="relative">
                            <Search className="absolute left-3 top-3 size-4 text-muted-foreground" />
                            <Input
                                className="pl-9"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder={t('Search name, slug or domain')}
                                aria-label={t('Search tenants')}
                            />
                        </div>
                        <Select value={status} onValueChange={setStatus}>
                            <SelectTrigger aria-label={t('Filter by lifecycle status')}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="ALL">{t('All statuses')}</SelectItem>
                                <SelectItem value="DRAFT">{t('Draft')}</SelectItem>
                                <SelectItem value="ACTIVE">{t('Active')}</SelectItem>
                                <SelectItem value="SUSPENDED">{t('Suspended')}</SelectItem>
                                <SelectItem value="CLOSED">{t('Closed')}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button type="submit" variant="secondary">
                            {t('Apply')}
                        </Button>
                    </form>
                    {tenants.data.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                title={t('No tenants found')}
                                description={t('Adjust the filters or create a tenant foundation.')}
                            />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('Tenant')}</TableHead>
                                        <TableHead>{t('Primary domain')}</TableHead>
                                        {financialAccess.inflow && (
                                            <TableHead className="text-right">
                                                {t('Inflow (top-ups)')}
                                            </TableHead>
                                        )}
                                        {financialAccess.outflow && (
                                            <TableHead className="text-right">
                                                {t('Outflow (withdrawals)')}
                                            </TableHead>
                                        )}
                                        <TableHead>{t('Status')}</TableHead>
                                        <TableHead>{t('Created')}</TableHead>
                                        <TableHead>
                                            <span className="sr-only">{t('Actions')}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {tenants.data.map((tenant) => (
                                        <TableRow key={tenant.id}>
                                            <TableCell>
                                                <p className="font-medium">{tenant.name}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {tenant.slug}
                                                </p>
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap text-muted-foreground">
                                                {tenant.domain ?? '—'}
                                            </TableCell>
                                            {financialAccess.inflow && (
                                                <TableCell className="text-right whitespace-nowrap">
                                                    <MoneyDisplay
                                                        amount={tenant.inflow!}
                                                        asset="USDT"
                                                    />
                                                </TableCell>
                                            )}
                                            {financialAccess.outflow && (
                                                <TableCell
                                                    className="text-right whitespace-nowrap"
                                                    title={t(
                                                        'Successful withdrawal amounts including fees.',
                                                    )}
                                                >
                                                    <MoneyDisplay
                                                        amount={tenant.outflow!}
                                                        asset="USDT"
                                                    />
                                                </TableCell>
                                            )}
                                            <TableCell>
                                                <StatusBadge
                                                    status={tones[tenant.status]}
                                                    label={t(tenant.status)}
                                                />
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap">
                                                {dateTime(tenant.createdAt)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button asChild variant="ghost" size="sm">
                                                    <Link href={`/platform/tenants/${tenant.id}`}>
                                                        {t('View')}
                                                    </Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                    <div className="flex items-center justify-between gap-3 border-t p-4">
                        <p className="text-sm text-muted-foreground">
                            {t('Page {{value1}} of {{value2}} · {{value3}}  total', {
                                value1: tenants.current_page,
                                value2: tenants.last_page,
                                value3: tenants.total,
                            })}
                        </p>
                        <div className="flex gap-2">
                            {tenants.prev_page_url ? (
                                <Button asChild variant="secondary" size="sm">
                                    <Link href={tenants.prev_page_url}>{t('Previous')}</Link>
                                </Button>
                            ) : (
                                <Button variant="secondary" size="sm" disabled>
                                    {t('Previous')}
                                </Button>
                            )}
                            {tenants.next_page_url ? (
                                <Button asChild variant="secondary" size="sm">
                                    <Link href={tenants.next_page_url}>{t('Next')}</Link>
                                </Button>
                            ) : (
                                <Button variant="secondary" size="sm" disabled>
                                    {t('Next')}
                                </Button>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </PlatformLayout>
    );
}
