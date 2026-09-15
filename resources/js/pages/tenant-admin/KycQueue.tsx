import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
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
    useAdminTranslation();
    const apply = (values: Record<string, string>) =>
        router.get('/admin/kyc', { ...filters, ...values }, { preserveState: true, replace: true });
    return (
        <TenantAdminLayout>
            <Head title={t('KYC review')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('Identity operations')}
                    title={t('KYC review queue')}
                    description={t(
                        'Tenant-scoped applications awaiting or completing manual review.',
                    )}
                />
                <Card>
                    <CardContent className="grid gap-3 p-4 sm:grid-cols-3">
                        <Input
                            aria-label={t('Search users')}
                            placeholder={t('Search email, phone or user ID')}
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
                                <SelectValue placeholder={t('All statuses')} />
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
                                        {t(status)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Input
                            aria-label={t('Submission date')}
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
                                    <TableHead>{t('User')}</TableHead>
                                    <TableHead>{t('Submitted')}</TableHead>
                                    <TableHead>{t('Country')}</TableHead>
                                    <TableHead>{t('OCR')}</TableHead>
                                    <TableHead>{t('Review')}</TableHead>
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
                                                {application.user.displayName ?? t('Unnamed user')}
                                            </Link>
                                            <p className="text-xs text-muted-foreground">
                                                {application.user.contact}
                                            </p>
                                        </TableCell>
                                        <TableCell>{dateTime(application.submittedAt)}</TableCell>
                                        <TableCell>{application.documentCountry}</TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                status={tone(application.ocrStatus)}
                                                label={t(application.ocrStatus)}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                status={tone(application.reviewStatus)}
                                                label={t(application.reviewStatus)}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        {!applications.data.length && (
                            <p className="p-10 text-center text-sm text-muted-foreground">
                                {t('No KYC applications match these filters.')}
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
                        {t('Previous')}
                    </Button>
                    <span className="text-sm text-muted-foreground">
                        {t('Page {{value1}} of {{value2}}', {
                            value1: applications.current_page,
                            value2: applications.last_page,
                        })}
                    </span>
                    <Button
                        variant="secondary"
                        disabled={!applications.next_page_url}
                        onClick={() =>
                            applications.next_page_url && router.get(applications.next_page_url)
                        }
                    >
                        {t('Next')}
                    </Button>
                </div>
            </div>
        </TenantAdminLayout>
    );
}
