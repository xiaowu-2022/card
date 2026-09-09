import { Head } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PaginationControls } from '@/components/ui/pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { PlatformLayout } from '@/layouts/PlatformLayout';

const tenants = [
    { name: 'Tenant A', domain: 'a.localhost', users: '1,420', status: 'Active' },
    { name: 'Tenant B', domain: 'b.localhost', users: '998', status: 'Active' },
    { name: 'Sandbox North', domain: 'north.example.test', users: '—', status: 'Draft' },
];
export default function Tenants() {
    return (
        <PlatformLayout>
            <Head title="Tenants" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Platform directory"
                    title="Tenants"
                    description="Platform-owned tenant lifecycle controls. Tenants are suspended or closed, never deleted."
                    actions={<Button disabled>Create tenant</Button>}
                />
                <div className="overflow-hidden rounded-xl border bg-surface">
                    <div className="relative border-b p-4">
                        <Search className="absolute left-7 top-7 size-4 text-muted-foreground" />
                        <Input
                            className="max-w-md pl-9"
                            placeholder="Search tenants or domains"
                            aria-label="Search demo tenants"
                        />
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Tenant</TableHead>
                                <TableHead>Domain</TableHead>
                                <TableHead>Users</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tenants.map((tenant) => (
                                <TableRow key={tenant.name}>
                                    <TableCell className="font-medium">{tenant.name}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {tenant.domain}
                                    </TableCell>
                                    <TableCell>{tenant.users}</TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={
                                                tenant.status === 'Active' ? 'SUCCESS' : 'NEUTRAL'
                                            }
                                            label={`${tenant.status} · Demo`}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Button variant="ghost" size="sm">
                                            View
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <div className="border-t p-4">
                        <PaginationControls />
                    </div>
                </div>
            </div>
        </PlatformLayout>
    );
}
