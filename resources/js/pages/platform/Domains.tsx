import { useEditorRouter } from '@/components/admin/useEditorRouter';
import { useForm } from '@/components/admin/editor-context';
import { useAdminTranslation, t, errorMessage } from '@/i18n/admin';
import { useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/admin/InlineEditorDialog';
import {
    AlertDialog,
    AlertDialogContent,
    AlertDialogTitle,
    AlertDialogDescription,
} from '@/components/ui/alert-dialog';
import { Head } from '@inertiajs/react';
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
import { PlatformSettingsLayout } from '@/layouts/PlatformSettingsLayout';
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
    status: 'PENDING_VERIFICATION' | 'VERIFIED' | 'ACTIVE' | 'FAILED' | 'DISABLED';
    sslStatus: string;
    companyId: string | null;
    companyName: string | null;
};
export default function Domains({
    domains,
    company,
    companies = [],
    availableDomains: pool = [],
}: {
    domains: Domain[];
    company: { id: string; name: string } | null;
    companies?: { id: string; name: string }[];
    availableDomains?: Domain[];
}) {
    const router = useEditorRouter();
    useAdminTranslation();
    const form = useForm({ hostname: '' });
    const [addOpen, setAddOpen] = useState(false);
    const closeAdd = () => {
        if (form.processing || (form.isDirty && !window.confirm(t('Discard unsaved changes?'))))
            return;
        setAddOpen(false);
        form.reset();
        form.clearErrors();
    };
    const base = company ? `/platform/tenants/${company.id}/domains` : '/platform/settings/domains';
    const Layout = company ? CompanyConfigurationLayout : PlatformSettingsLayout;
    const availableDomains = domains.filter(
        (domain) => domain.status === 'ACTIVE' && !domain.companyId,
    );
    const [candidate, setCandidate] = useState('');
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
            .filter((domain) => domain.companyId === tenantId)
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
        action: 'activate' | 'remove';
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
        else router.post(`${base}/${confirmation.domain.id}/${confirmation.action}`, {}, options);
    };
    return (
        <Layout>
            <Head title={t(company ? 'Domains' : 'Domain configurations')} />
            <div className="min-w-0 space-y-4">
                <Card>
                    <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                        <CardTitle>
                            {t(company ? 'Configured domains' : 'Domain configurations')}
                        </CardTitle>
                        {company && (
                            <div className="flex flex-wrap items-center gap-2">
                                <select
                                    aria-label={t('Domains')}
                                    className="h-9 rounded border px-3"
                                    value={candidate}
                                    onChange={(e) => setCandidate(e.target.value)}
                                >
                                    <option value="">{t('Unassigned')}</option>
                                    {pool.map((domain) => (
                                        <option key={domain.id} value={domain.id}>
                                            {domain.hostname}
                                        </option>
                                    ))}
                                </select>
                                <Button
                                    disabled={!candidate || assignment.processing}
                                    onClick={() =>
                                        openAssignment([candidate], 'assign', company.id)
                                    }
                                >
                                    {t('Assign to company')}
                                </Button>
                            </div>
                        )}
                        {!company && (
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="secondary"
                                    onClick={() => {
                                        form.reset();
                                        form.clearErrors();
                                        setAddOpen(true);
                                    }}
                                >
                                    {t('Add custom domain')}
                                </Button>
                                <Button
                                    disabled={selectedIds.length === 0 || assignment.processing}
                                    onClick={() => openAssignment(selectedIds, 'assign')}
                                >
                                    {t('Assign to company')}
                                </Button>
                            </div>
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
                                    <TableHead>{t('Controls')}</TableHead>
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
                                            </p>
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
                                                label={t(
                                                    ['PENDING_VERIFICATION', 'VERIFIED'].includes(
                                                        domain.status,
                                                    )
                                                        ? 'Not activated'
                                                        : domain.status,
                                                )}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap gap-2">
                                                {availableDomains.some(
                                                    (available) => available.id === domain.id,
                                                ) && (
                                                    <Button
                                                        size="sm"
                                                        variant="secondary"
                                                        onClick={() =>
                                                            openAssignment([domain.id], 'assign')
                                                        }
                                                    >
                                                        {t('Assign to company')}
                                                    </Button>
                                                )}
                                                {domain.companyId && (
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
                                                    ['PENDING_VERIFICATION', 'VERIFIED'].includes(
                                                        domain.status,
                                                    ) && (
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
                                                    domain.type === 'CUSTOM_DOMAIN' &&
                                                    !domain.companyId && (
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
                                    </TableRow>
                                ))}
                                {domains.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={company ? 3 : 5}
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
            {!company && (
                <Dialog
                    open={addOpen}
                    onOpenChange={(open) => {
                        if (!open) closeAdd();
                    }}
                >
                    <DialogContent
                        className="flex flex-col overflow-hidden p-0"
                        closeLabel={t('Close')}
                        closeDisabled={form.processing}
                        aria-describedby={undefined}
                    >
                        <DialogHeader className="mb-0 shrink-0 border-b p-4 pr-14">
                            <DialogTitle>{t('Add custom domain')}</DialogTitle>
                        </DialogHeader>
                        <form
                            className="flex min-h-0 flex-col"
                            onSubmit={(event) => {
                                event.preventDefault();
                                if (form.processing) return;
                                form.post(base, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        form.reset();
                                        form.clearErrors();
                                        setAddOpen(false);
                                    },
                                });
                            }}
                        >
                            <div className="min-h-0 overflow-y-auto p-4">
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
                                        disabled={form.processing}
                                        onChange={(event) =>
                                            form.setData('hostname', event.target.value)
                                        }
                                    />
                                </FormField>
                            </div>
                            <div className="flex shrink-0 justify-end gap-2 border-t p-4">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={form.processing}
                                    onClick={closeAdd}
                                >
                                    {t('Cancel')}
                                </Button>
                                <Button type="submit" disabled={form.processing}>
                                    {t('Add domain')}
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            )}
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
                    {assignmentMode === 'assign' && !company ? (
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
                            {company?.name ??
                                companies.find((tenant) => tenant.id === targetCompanyId)?.name}
                        </p>
                    )}
                    <div>
                        <p className="font-medium">
                            {t(assignmentMode === 'assign' ? 'Assign' : 'Unassign')}
                        </p>
                        <ul className="mt-2 space-y-1 break-all text-sm">
                            {[...domains, ...pool]
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
                                    company
                                        ? `/platform/tenants/${company.id}/configuration/domains`
                                        : `/platform/settings/domains/assign/${targetCompanyId}`,
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
                            ? t('Removing this domain stops access through this hostname.')
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
