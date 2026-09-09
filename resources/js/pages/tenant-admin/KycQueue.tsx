import { Head, Link, router } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge, type StatusTone } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';

type Application = {
    id: string;
    user: { id: string; displayName: string | null; contact: string | null };
    documentCountry: string;
    ocrStatus: string;
    reviewStatus: string;
    submittedAt: string;
};
type Props = {
    applications: {
        data: Application[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { search?: string; status?: string; date?: string };
};
const tone = (status: string): StatusTone =>
    status === 'APPROVED' || status === 'SUCCEEDED'
        ? 'SUCCESS'
        : status === 'REJECTED' || status === 'FAILED'
          ? 'DANGER'
          : status === 'PENDING' || status === 'PROCESSING' || status === 'RESUBMISSION_REQUIRED'
            ? 'WARNING'
            : 'NEUTRAL';

export default function KycQueue({ applications, filters }: Props) {
    const apply = (values: Record<string, string>) =>
        router.get('/admin/kyc', { ...filters, ...values }, { preserveState: true, replace: true });
    return (
        <TenantAdminLayout>
            <Head title="KYC review" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Identity operations"
                    title="KYC review queue"
                    description="Tenant-scoped applications awaiting or completing manual review."
                />
                <Card>
                    <CardContent className="grid gap-3 p-4 sm:grid-cols-3">
                        <Input
                            aria-label="Search users"
                            placeholder="Search email, phone or user ID"
                            defaultValue={filters.search ?? ''}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter')
                                    apply({ search: event.currentTarget.value });
                            }}
                        />
                        <Select
                            value={filters.status || 'ALL'}
                            onValueChange={(value) =>
                                apply({ status: value === 'ALL' ? '' : value })
                            }
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                {[
                                    'ALL',
                                    'PENDING',
                                    'APPROVED',
                                    'REJECTED',
                                    'RESUBMISSION_REQUIRED',
                                ].map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {status.replaceAll('_', ' ')}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Input
                            aria-label="Submission date"
                            type="date"
                            defaultValue={filters.date ?? ''}
                            onChange={(event) => apply({ date: event.target.value })}
                        />
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="overflow-x-auto p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>User</TableHead>
                                    <TableHead>Submitted</TableHead>
                                    <TableHead>Country</TableHead>
                                    <TableHead>OCR</TableHead>
                                    <TableHead>Review</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {applications.data.map((application) => (
                                    <TableRow key={application.id}>
                                        <TableCell>
                                            <Link
                                                className="font-semibold text-primary"
                                                href={`/admin/kyc/${application.id}`}
                                            >
                                                {application.user.displayName ?? 'Unnamed user'}
                                            </Link>
                                            <p className="text-xs text-muted-foreground">
                                                {application.user.contact}
                                            </p>
                                        </TableCell>
                                        <TableCell>
                                            {new Date(application.submittedAt).toLocaleString()}
                                        </TableCell>
                                        <TableCell>{application.documentCountry}</TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                status={tone(application.ocrStatus)}
                                                label={application.ocrStatus}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                status={tone(application.reviewStatus)}
                                                label={application.reviewStatus.replaceAll(
                                                    '_',
                                                    ' ',
                                                )}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        {!applications.data.length && (
                            <p className="p-10 text-center text-sm text-muted-foreground">
                                No KYC applications match these filters.
                            </p>
                        )}
                    </CardContent>
                </Card>
                <div className="flex items-center justify-between">
                    <Button
                        variant="secondary"
                        disabled={!applications.prev_page_url}
                        onClick={() =>
                            applications.prev_page_url && router.get(applications.prev_page_url)
                        }
                    >
                        Previous
                    </Button>
                    <span className="text-sm text-muted-foreground">
                        Page {applications.current_page} of {applications.last_page}
                    </span>
                    <Button
                        variant="secondary"
                        disabled={!applications.next_page_url}
                        onClick={() =>
                            applications.next_page_url && router.get(applications.next_page_url)
                        }
                    >
                        Next
                    </Button>
                </div>
            </div>
        </TenantAdminLayout>
    );
}
