import { useAdminTranslation, t, errorMessage } from '@/i18n/admin';
import { useState } from 'react';
import {
    AlertDialog,
    AlertDialogContent,
    AlertDialogTitle,
    AlertDialogDescription,
} from '@/components/ui/alert-dialog';
import { Head, router, useForm } from '@inertiajs/react';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { CompanyConfigurationLayout } from '@/components/admin/CompanyConfiguration';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';

type Domain = {
    id: string;
    hostname: string;
    type: 'SYSTEM_SUBDOMAIN' | 'CUSTOM_DOMAIN';
    status: 'PENDING_VERIFICATION' | 'VERIFIED' | 'ACTIVE';
    primary: boolean;
    verificationToken: string | null;
    sslStatus: string;
    companyId: string | null;
    companyName: string | null;
};
export default function Domains({
    domains,
    company,
    companies = [],
}: {
    domains: Domain[];
    company: { id: string; name: string } | null;
    companies?: { id: string; name: string }[];
}) {
    useAdminTranslation();
    const form = useForm({ hostname: '' });
    const base = company ? `/platform/tenants/${company.id}/domains` : '/platform/settings/domains';
    const Layout = company ? CompanyConfigurationLayout : PlatformLayout;
    const availableDomains = domains.filter(
        (domain) =>
            domain.type === 'CUSTOM_DOMAIN' && domain.status === 'ACTIVE' && !domain.companyId,
    );
    const [selectedIds, setSelectedIds] = useState<string[]>([]);
    const [targetCompanyId, setTargetCompanyId] = useState('');
    const [assignOpen, setAssignOpen] = useState(false);
    const [assignmentMode, setAssignmentMode] = useState<'assign' | 'unassign'>('assign');
    const [assignmentIds, setAssignmentIds] = useState<string[]>([]);
    const assignment = useForm({
        domain_ids: [] as string[],
        original_ids: [] as string[],
        confirmed: true,
    });
    const prepareAssignment = (tenantId: string, ids: string[], mode: 'assign' | 'unassign') => {
        const originalIds = domains
            .filter((domain) => domain.type === 'CUSTOM_DOMAIN' && domain.companyId === tenantId)
            .map((domain) => domain.id);
        assignment.setData({
            original_ids: originalIds,
            domain_ids:
                mode === 'assign'
                    ? [...new Set([...originalIds, ...ids])]
                    : originalIds.filter((id) => !ids.includes(id)),
            confirmed: true,
        });
    };
    const openAssignment = (ids: string[], mode: 'assign' | 'unassign', tenantId = '') => {
        assignment.clearErrors();
        setAssignmentIds(ids);
        setAssignmentMode(mode);
        setTargetCompanyId(tenantId);
        prepareAssignment(tenantId, ids, mode);
        setAssignOpen(true);
    };
    const [confirmation, setConfirmation] = useState<{
        domain: Domain;
        action: 'activate' | 'primary' | 'remove';
    } | null>(null);
    const [processing, setProcessing] = useState(false);
    const confirm = () => {
        if (!confirmation || processing) return;
        setProcessing(true);
        const options = {
            onFinish: () => {
                setProcessing(false);
                setConfirmation(null);
            },
        };
        if (confirmation.action === 'remove')
            router.delete(`${base}/${confirmation.domain.id}`, options);
        else if (confirmation.action === 'primary')
            router.post(
                `/platform/tenants/${confirmation.domain.companyId}/domains/${confirmation.domain.id}/primary`,
                {},
                options,
            );
        else router.post(`${base}/${confirmation.domain.id}/${confirmation.action}`, {}, options);
    };
    return (
        <Layout>
            <Head title={t(company ? 'Domains' : 'Domain configurations')} />
            <div className="space-y-6">
                {!company && (
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Add custom domain')}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form
                                className="flex max-w-xl flex-col gap-3 sm:flex-row sm:items-end"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(base, { onSuccess: () => form.reset() });
                                }}
                            >
                                <FormField
                                    id="hostname"
                                    label={t('Hostname')}
                                    description={t('Hostname only; no scheme, port or path.')}
                                    error={errorMessage(form.errors.hostname)}
                                >
                                    <Input
                                        id="hostname"
                                        placeholder={t('cards.example.com')}
                                        value={form.data.hostname}
                                        onChange={(event) =>
                                            form.setData('hostname', event.target.value)
                                        }
                                    />
                                </FormField>
                                <Button disabled={form.processing}>{t('Add domain')}</Button>
                            </form>
                        </CardContent>
                    </Card>
                )}
                <Card>
                    <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                        <CardTitle>
                            {t(company ? 'Configured domains' : 'Domain configurations')}
                        </CardTitle>
                        {!company && (
                            <Button
                                disabled={selectedIds.length === 0 || assignment.processing}
                                onClick={() => openAssignment(selectedIds, 'assign')}
                            >
                                {t('Assign to company')}
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <Table className="min-w-[640px] [&_th]:whitespace-nowrap">
                            <TableHeader>
                                <TableRow>
                                    {!company && (
                                        <TableHead className="w-12">
                                            <Checkbox
                                                aria-label={t('Select all available domains')}
                                                disabled={
                                                    availableDomains.length === 0 ||
                                                    assignment.processing
                                                }
                                                checked={
                                                    availableDomains.length > 0 &&
                                                    availableDomains.every((domain) =>
                                                        selectedIds.includes(domain.id),
                                                    )
                                                }
                                                onCheckedChange={(checked) =>
                                                    setSelectedIds(
                                                        checked
                                                            ? availableDomains.map(
                                                                  (domain) => domain.id,
                                                              )
                                                            : [],
                                                    )
                                                }
                                            />
                                        </TableHead>
                                    )}
                                    <TableHead>{t('Domain')}</TableHead>
                                    {!company && <TableHead>{t('Assigned company')}</TableHead>}
                                    <TableHead>{t('State')}</TableHead>
                                    <TableHead>{t('SSL')}</TableHead>
                                    {!company && <TableHead>{t('Controls')}</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {domains.map((domain) => (
                                    <TableRow key={domain.id}>
                                        {!company && (
                                            <TableCell>
                                                <Checkbox
                                                    aria-label={t('Select domain {{hostname}}', {
                                                        hostname: domain.hostname,
                                                    })}
                                                    checked={selectedIds.includes(domain.id)}
                                                    disabled={
                                                        !availableDomains.some(
                                                            (available) =>
                                                                available.id === domain.id,
                                                        ) || assignment.processing
                                                    }
                                                    onCheckedChange={(checked) =>
                                                        setSelectedIds(
                                                            checked
                                                                ? [...selectedIds, domain.id]
                                                                : selectedIds.filter(
                                                                      (id) => id !== domain.id,
                                                                  ),
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                        )}
                                        <TableCell>
                                            <p className="font-medium">{domain.hostname}</p>
                                            <p className="text-xs text-muted-foreground">
                                                {t(domain.type)}
                                                {domain.primary ? t(' · Primary') : ''}
                                            </p>
                                            {domain.verificationToken &&
                                                domain.status === 'PENDING_VERIFICATION' && (
                                                    <code className="mt-2 block max-w-md break-all rounded bg-muted p-2 text-xs">
                                                        {t(
                                                            'TXT _vc-verification.{{value1}} =  {{value2}}',
                                                            {
                                                                value1: domain.hostname,
                                                                value2: domain.verificationToken,
                                                            },
                                                        )}
                                                    </code>
                                                )}
                                        </TableCell>
                                        {!company && (
                                            <TableCell>
                                                {domain.companyName ?? t('Unassigned')}
                                            </TableCell>
                                        )}
                                        <TableCell>
                                            <StatusBadge
                                                status={
                                                    domain.status === 'ACTIVE'
                                                        ? 'SUCCESS'
                                                        : domain.status === 'VERIFIED'
                                                          ? 'INFO'
                                                          : 'WARNING'
                                                }
                                                label={t(domain.status)}
                                            />
                                        </TableCell>
                                        <TableCell>{t(domain.sslStatus)}</TableCell>
                                        {!company && (
                                            <TableCell>
                                                <div className="flex flex-wrap gap-2">
                                                    {availableDomains.some(
                                                        (available) => available.id === domain.id,
                                                    ) && (
                                                        <Button
                                                            size="sm"
                                                            variant="secondary"
                                                            onClick={() =>
                                                                openAssignment(
                                                                    [domain.id],
                                                                    'assign',
                                                                )
                                                            }
                                                        >
                                                            {t('Assign to company')}
                                                        </Button>
                                                    )}
                                                    {domain.type === 'CUSTOM_DOMAIN' &&
                                                        domain.companyId &&
                                                        !domain.primary && (
                                                            <Button
                                                                size="sm"
                                                                variant="secondary"
                                                                onClick={() =>
                                                                    openAssignment(
                                                                        [domain.id],
                                                                        'unassign',
                                                                        domain.companyId!,
                                                                    )
                                                                }
                                                            >
                                                                {t('Unassign')}
                                                            </Button>
                                                        )}

                                                    {!company &&
                                                        domain.type === 'CUSTOM_DOMAIN' &&
                                                        domain.status ===
                                                            'PENDING_VERIFICATION' && (
                                                            <Button
                                                                size="sm"
                                                                variant="secondary"
                                                                onClick={() =>
                                                                    router.post(
                                                                        `${base}/${domain.id}/verify`,
                                                                    )
                                                                }
                                                            >
                                                                {t('Check verification')}
                                                            </Button>
                                                        )}
                                                    {!company &&
                                                        domain.type === 'CUSTOM_DOMAIN' &&
                                                        domain.status === 'VERIFIED' && (
                                                            <Button
                                                                size="sm"
                                                                onClick={() =>
                                                                    setConfirmation({
                                                                        domain,
                                                                        action: 'activate',
                                                                    })
                                                                }
                                                            >
                                                                {t('Activate')}
                                                            </Button>
                                                        )}
                                                    {!company &&
                                                        domain.companyId &&
                                                        domain.status === 'ACTIVE' &&
                                                        !domain.primary && (
                                                            <Button
                                                                size="sm"
                                                                variant="secondary"
                                                                onClick={() =>
                                                                    setConfirmation({
                                                                        domain,
                                                                        action: 'primary',
                                                                    })
                                                                }
                                                            >
                                                                {t('Make primary')}
                                                            </Button>
                                                        )}
                                                    {!company &&
                                                        domain.type === 'CUSTOM_DOMAIN' &&
                                                        !domain.companyId &&
                                                        !domain.primary && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    setConfirmation({
                                                                        domain,
                                                                        action: 'remove',
                                                                    })
                                                                }
                                                            >
                                                                {t('Remove')}
                                                            </Button>
                                                        )}
                                                </div>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                                {domains.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={company ? 3 : 6}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            {t('No domains yet.')}
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
            <AlertDialog
                open={assignOpen}
                onOpenChange={(open) => {
                    if (!assignment.processing) setAssignOpen(open);
                }}
            >
                <AlertDialogContent className="max-h-[85dvh] overflow-y-auto">
                    <AlertDialogTitle>{t('Confirm domain assignments')}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {t(
                            'Assigned domains will open this company. Removed domains will stop opening it.',
                        )}
                    </AlertDialogDescription>
                    {assignmentMode === 'assign' ? (
                        <FormField id="domain-company" label={t('Assigned company')}>
                            <Select
                                value={targetCompanyId}
                                onValueChange={(value) => {
                                    setTargetCompanyId(value);
                                    prepareAssignment(value, assignmentIds, assignmentMode);
                                }}
                                disabled={assignment.processing}
                            >
                                <SelectTrigger id="domain-company">
                                    <SelectValue placeholder={t('Choose a company')} />
                                </SelectTrigger>
                                <SelectContent>
                                    {companies.map((tenant) => (
                                        <SelectItem key={tenant.id} value={tenant.id}>
                                            {tenant.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FormField>
                    ) : (
                        <p className="font-medium">
                            {companies.find((tenant) => tenant.id === targetCompanyId)?.name}
                        </p>
                    )}
                    <div>
                        <p className="font-medium">
                            {t(assignmentMode === 'assign' ? 'Assign' : 'Unassign')}
                        </p>
                        <ul className="mt-2 space-y-1 break-all text-sm">
                            {domains
                                .filter((domain) => assignmentIds.includes(domain.id))
                                .map((domain) => (
                                    <li key={domain.id}>{domain.hostname}</li>
                                ))}
                        </ul>
                    </div>
                    {Object.values(assignment.errors).map((message, index) => (
                        <p key={index} className="text-sm text-destructive">
                            {errorMessage(message)}
                        </p>
                    ))}
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="secondary"
                            disabled={assignment.processing}
                            onClick={() => setAssignOpen(false)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button
                            disabled={!targetCompanyId || assignment.processing}
                            onClick={() =>
                                assignment.post(
                                    `/platform/settings/domains/assign/${targetCompanyId}`,
                                    {
                                        onSuccess: () => {
                                            setAssignOpen(false);
                                            setSelectedIds([]);
                                        },
                                    },
                                )
                            }
                        >
                            {t('Confirm')}
                        </Button>
                    </div>
                </AlertDialogContent>
            </AlertDialog>
            <AlertDialog
                open={confirmation !== null}
                onOpenChange={(open) => {
                    if (!open && !processing) setConfirmation(null);
                }}
            >
                <AlertDialogContent className="max-h-[85dvh] overflow-y-auto">
                    <AlertDialogTitle>
                        {t('Confirm')} · {confirmation?.domain.hostname}
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {confirmation?.action === 'remove'
                            ? t(
                                  'Removing this domain stops access through this hostname. Its DNS verification must be repeated if added again.',
                              )
                            : t(
                                  'This changes company access routing. Confirm that DNS and HTTPS are ready before continuing.',
                              )}
                    </AlertDialogDescription>
                    <div className="mt-4 flex justify-end gap-2">
                        <Button
                            variant="secondary"
                            disabled={processing}
                            onClick={() => setConfirmation(null)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button disabled={processing} onClick={confirm}>
                            {t('Confirm')}
                        </Button>
                    </div>
                </AlertDialogContent>
            </AlertDialog>
        </Layout>
    );
}
