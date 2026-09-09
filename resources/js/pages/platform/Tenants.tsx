import { Head, Link, router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { EmptyState } from '@/components/shared/EmptyState';
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
}: {
    tenants: Paginator;
    filters: { search?: string; status?: string };
}) {
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
            <Head title="Tenants" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Platform directory"
                    title="Tenants"
                    description="Create tenant foundations and control their lifecycle without deleting historical records."
                    actions={
                        canManage ? (
                            <Button asChild>
                                <Link href="/platform/tenants/create">Create tenant</Link>
                            </Button>
                        ) : undefined
                    }
                />
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
                                placeholder="Search name, slug or domain"
                                aria-label="Search tenants"
                            />
                        </div>
                        <Select value={status} onValueChange={setStatus}>
                            <SelectTrigger aria-label="Filter by lifecycle status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="ALL">All statuses</SelectItem>
                                <SelectItem value="DRAFT">Draft</SelectItem>
                                <SelectItem value="ACTIVE">Active</SelectItem>
                                <SelectItem value="SUSPENDED">Suspended</SelectItem>
                                <SelectItem value="CLOSED">Closed</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button type="submit" variant="secondary">
                            Apply
                        </Button>
                    </form>
                    {tenants.data.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                title="No tenants found"
                                description="Adjust the filters or create a tenant foundation."
                            />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Tenant</TableHead>
                                        <TableHead>Primary domain</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Created</TableHead>
                                        <TableHead>
                                            <span className="sr-only">Actions</span>
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
                                            <TableCell>
                                                <StatusBadge
                                                    status={tones[tenant.status]}
                                                    label={tenant.status}
                                                />
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap">
                                                {new Date(tenant.createdAt).toLocaleDateString()}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button asChild variant="ghost" size="sm">
                                                    <Link href={`/platform/tenants/${tenant.id}`}>
                                                        View
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
                            Page {tenants.current_page} of {tenants.last_page} · {tenants.total}{' '}
                            total
                        </p>
                        <div className="flex gap-2">
                            {tenants.prev_page_url ? (
                                <Button asChild variant="secondary" size="sm">
                                    <Link href={tenants.prev_page_url}>Previous</Link>
                                </Button>
                            ) : (
                                <Button variant="secondary" size="sm" disabled>
                                    Previous
                                </Button>
                            )}
                            {tenants.next_page_url ? (
                                <Button asChild variant="secondary" size="sm">
                                    <Link href={tenants.next_page_url}>Next</Link>
                                </Button>
                            ) : (
                                <Button variant="secondary" size="sm" disabled>
                                    Next
                                </Button>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </PlatformLayout>
    );
}
