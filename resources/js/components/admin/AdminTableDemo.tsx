import { Search } from 'lucide-react';
import { EmptyState } from '@/components/shared/EmptyState';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PaginationControls } from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

const users = [
    {
        id: 'DEMO-1001',
        name: 'Avery Chen',
        email: 'avery@example.test',
        status: 'Active',
        kyc: 'Approved',
        joined: '08 Sep 2026',
    },
    {
        id: 'DEMO-1002',
        name: 'Mika Tan',
        email: 'mika@example.test',
        status: 'Active',
        kyc: 'Pending',
        joined: '07 Sep 2026',
    },
    {
        id: 'DEMO-1003',
        name: 'Jordan Lee',
        email: 'jordan@example.test',
        status: 'Suspended',
        kyc: 'Review needed',
        joined: '05 Sep 2026',
    },
];

export function AdminTableDemo({
    state = 'ready',
}: {
    state?: 'ready' | 'loading' | 'empty' | 'error';
}) {
    return (
        <div className="min-w-0 max-w-full overflow-hidden rounded-xl border bg-surface">
            <div className="flex flex-col gap-3 border-b p-4 md:flex-row">
                <div className="relative flex-1">
                    <Search className="absolute left-3 top-3 size-4 text-muted-foreground" />
                    <Input
                        aria-label="Search demo users"
                        className="pl-9"
                        placeholder="Search name, email or ID"
                    />
                </div>
                <Select defaultValue="all">
                    <SelectTrigger className="md:w-44" aria-label="Filter by status">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All statuses</SelectItem>
                        <SelectItem value="active">Active</SelectItem>
                        <SelectItem value="suspended">Suspended</SelectItem>
                    </SelectContent>
                </Select>
                <Input type="date" aria-label="Filter by join date" className="md:w-44" />
            </div>
            {state === 'loading' && (
                <div className="space-y-3 p-4">
                    {[1, 2, 3].map((row) => (
                        <Skeleton key={row} className="h-12 w-full" />
                    ))}
                </div>
            )}
            {state === 'empty' && (
                <div className="p-5">
                    <EmptyState
                        title="No users match these filters"
                        description="Try changing the search or status filter."
                        primaryAction={<Button variant="secondary">Clear filters</Button>}
                    />
                </div>
            )}
            {state === 'error' && (
                <div className="p-5">
                    <p className="font-semibold text-danger">Users could not be loaded.</p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Try again. Reference: DEMO-REQUEST-ID
                    </p>
                </div>
            )}
            {state === 'ready' && (
                <>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>User</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>KYC</TableHead>
                                <TableHead>Joined</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.map((user) => (
                                <TableRow key={user.id}>
                                    <TableCell>
                                        <div className="font-medium">{user.name}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {user.email} · {user.id}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={user.status === 'Active' ? 'SUCCESS' : 'DANGER'}
                                            label={user.status}
                                        />
                                    </TableCell>
                                    <TableCell>{user.kyc}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {user.joined}
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
                        <PaginationControls page={1} pages={4} />
                    </div>
                </>
            )}
        </div>
    );
}
