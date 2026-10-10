import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { partnerJournalKind } from '@/lib/partner-journal-note';
import { PartnerHierarchyPanel } from '@/components/admin/PartnerHierarchyPanel';
import { DetailDrawerContent } from '@/components/admin/DetailDrawer';
import { PartnerInvitationsReport } from '@/components/admin/PartnerInvitationsReport';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { invitationLocation } from '@/components/admin/partner-invitations-state';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { PartnerUserSelect } from '@/components/admin/PartnerUserSelect';
import { exactAmount } from '@/lib/exact-amount';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { t, useAdminTranslation } from '@/i18n/admin';
import {
    PartnerStockReport,
    type JournalRow,
    type StockReport,
} from '@/components/user/PartnerStockReport';
import '../../../css/partner-stock.css';
type Page<T> = { data: T[]; current_page: number; last_page: number; total: number };
type Partner = {
    id: string;
    tenant_id: string;
    company_name: string;
    enabled: boolean;
    share_percent: string;
    account_id: string;
    display_name: string | null;
    email: string | null;
};
type Fee = {
    id: string;
    tenant_id: string;
    company_name: string;
    withdrawal_id: string;
    asset_code: string;
    original_amount: string;
    created_at: string;
};
const requestId = () => crypto.randomUUID();
const date = () => new Date().toISOString().slice(0, 10);
export default function Partners({
    companies,
    companyId,
    reportCompanyId,
    partners,
    report,
    pendingFees,
}: {
    companies: { id: string; name: string }[];
    companyId: string | null;
    reportCompanyId: string | null;
    partners: Page<Partner> | null;
    report: StockReport | null;
    pendingFees: Page<Fee> | null;
}) {
    useAdminTranslation();
    const configuration = useForm({ account_id: '', enabled: true, share_percent: '40' });
    const journal = useForm({
        kind: 'REIMBURSEMENT',
        amount: '',
        business_date: date(),
        note: '',
        request_id: requestId(),
        reverses_id: null as string | null,
    });
    const [targetCompany, setTargetCompany] = useState(companyId ?? '');
    const [journalPartner, setJournalPartner] = useState('');
    const [journalAccount, setJournalAccount] = useState('');
    const [journalOpen, setJournalOpen] = useState(false);
    const [configurationOpen, setConfigurationOpen] = useState(false);
    const [editing, setEditing] = useState<Partner | null>(null);
    const [feeId, setFeeId] = useState('');
    const [feeCompany, setFeeCompany] = useState('');
    const fx = useForm({ rate: '', observed_at: '', evidence: '', request_id: requestId() });
    const { url } = usePage();
    const [invitationPartner, setInvitationPartner] = useState(() =>
        new URL(location.href).searchParams.get('invitation_partner'),
    );
    useEffect(() => {
        const sync = () =>
            setInvitationPartner(new URL(location.href).searchParams.get('invitation_partner'));
        sync();
        window.addEventListener('popstate', sync);
        return () => window.removeEventListener('popstate', sync);
    }, [url]);
    const [reportOpen, setReportOpen] = useState(Boolean(report));
    const [hierarchyPartner, setHierarchyPartner] = useState<string | null>(null);
    const hierarchyScroll = useRef(0);
    const hierarchyTrigger = useRef<HTMLButtonElement | null>(null);
    useEffect(() => {
        setHierarchyPartner(null);
    }, [reportOpen, report?.partnerId]);
    const [reportLoading, setReportLoading] = useState(false);
    const [reportError, setReportError] = useState(false);
    const reportRequest = useRef<(() => void) | null>(null);
    const reportTrigger = useRef<HTMLElement | null>(null);
    const reportTarget = useRef<[string | undefined, number, string | null | undefined, number]>([
        undefined,
        1,
        null,
        1,
    ]);
    useEffect(() => {
        const query = new URL(url, location.origin).searchParams;
        setReportOpen(query.has('partner') || query.has('invitation_partner'));
    }, [url]);
    const visit = (partner?: string, page = 1, flow?: string | null, flowPage = 1) => {
        reportRequest.current?.();
        reportTarget.current = [partner, page, flow, flowPage];
        const query = new URL(location.href).searchParams;
        for (const key of ['partner', 'report_page', 'flow', 'flow_page']) query.delete(key);
        if (partner) {
            query.set('partner', partner);
            query.set('report_page', String(page));
            if (flow) {
                query.set('flow', flow);
                query.set('flow_page', String(flowPage));
            }
        } else {
            query.set('page', String(page));
            for (const key of [
                'invitation_partner',
                'invitation_kind',
                'invitation_rank',
                'invitation_page',
            ])
                query.delete(key);
            setInvitationPartner(null);
        }
        setReportOpen(Boolean(partner));
        setReportLoading(Boolean(partner));
        setReportError(false);
        router.get('/platform/partners', Object.fromEntries(query), {
            preserveScroll: true,
            preserveState: true,
            ...(partner ? { only: ['report', 'reportCompanyId'] } : {}),
            onCancelToken: (token) => {
                reportRequest.current = () => token.cancel();
            },
            onError: () => setReportError(true),
            onHttpException: () => {
                setReportError(true);
                return false;
            },
            onNetworkError: () => {
                setReportError(true);
                return false;
            },
            onFinish: () => {
                setReportLoading(false);
                reportRequest.current = null;
            },
        });
    };
    const edit = (p: Partner) => {
        setTargetCompany(p.tenant_id);
        const values = {
            account_id: p.account_id,
            enabled: p.enabled,
            share_percent: p.share_percent,
        };
        configuration.setDefaults(values);
        configuration.setData(values);
        setEditing(p);
        configuration.clearErrors();
        setConfigurationOpen(true);
    };
    const add = () => {
        setTargetCompany(companyId ?? '');
        setEditing(null);
        const values = { account_id: '', enabled: true, share_percent: '40' };
        configuration.setDefaults(values);
        configuration.setData(values);
        configuration.clearErrors();
        setConfigurationOpen(true);
    };
    const record = (p: Partner) => {
        setTargetCompany(p.tenant_id);
        setJournalPartner(p.id);
        setJournalAccount(`${p.display_name || '—'} · ${p.email || '—'}`);
        const values = {
            kind: 'REIMBURSEMENT',
            amount: '',
            business_date: date(),
            note: '',
            request_id: requestId(),
            reverses_id: null,
        };
        journal.setDefaults(values);
        journal.setData(values);
        journal.clearErrors();
        setJournalOpen(true);
    };
    const reverse = (row: JournalRow) => {
        setTargetCompany(reportCompanyId ?? '');
        setJournalPartner(row.partner_id);
        setJournalAccount(`${row.display_name || '—'} · ${row.email || '—'}`);
        journal.clearErrors();
        setJournalOpen(true);
        const values = {
            kind: row.kind,
            amount: row.amount,
            business_date: date(),
            note: '',
            request_id: requestId(),
            reverses_id: row.id,
        };
        journal.setDefaults(values);
        journal.setData(values);
    };
    const submitJournal = (e: FormEvent) => {
        e.preventDefault();
        const adjustment = journal.data.kind.startsWith('ADJUSTMENT_');
        if (
            adjustment &&
            !confirm(
                `${journalAccount} · ${t(partnerJournalKind(journal.data.kind))} · ${journal.data.amount} USDT${journal.data.reverses_id ? ` · ${t('Reversal')}` : ''}\n${t('Confirm this theoretical balance adjustment?')}`,
            )
        )
            return;
        journal.transform((data) => ({ ...data, ...(adjustment ? { confirmed: true } : {}) }));
        if (targetCompany && (journalPartner || report?.partnerId))
            journal.post(
                `/platform/tenants/${targetCompany}/partners/${journalPartner || report?.partnerId}/journal`,
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        journal.reset();
                        journal.setData('request_id', requestId());
                        setJournalPartner('');
                        setJournalOpen(false);
                    },
                },
            );
    };
    return (
        <PlatformLayout
            title={t('Partners')}
            actions={
                <button type="button" className="partner-admin-action" onClick={add}>
                    + {t('Add partner')}
                </button>
            }
        >
            <Head title={t('Partners')} />
            <div className="partner-admin partner-admin-platform">
                <div className="partner-form">
                    <label>
                        {t('Company')}
                        <select
                            value={companyId ?? ''}
                            onChange={(e) =>
                                router.get('/platform/partners', { company: e.target.value })
                            }
                        >
                            <option value="">{t('All companies')}</option>
                            {companies.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                {
                    <>
                        <section className="partner-list-panel" aria-label={t('Partner list')}>
                            <div className="partner-list-count">
                                {t('Partner list')} <span>{partners?.total ?? 0}</span>
                            </div>
                            {partners?.data.length ? (
                                <table className="partner-table">
                                    <thead>
                                        <tr>
                                            <th>{t('Company')}</th>
                                            <th>{t('Username')}</th>
                                            <th>{t('Email')}</th>
                                            <th>{t('Partner share')}</th>
                                            <th>{t('Report access')}</th>
                                            <th>{t('Actions')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {partners.data.map((p) => (
                                            <tr key={p.id}>
                                                <td>{p.company_name}</td>
                                                <td data-label={t('Username')}>
                                                    <strong>{p.display_name || '—'}</strong>
                                                </td>
                                                <td data-label={t('Email')} className="break-all">
                                                    {p.email || '—'}
                                                </td>
                                                <td data-label={t('Partner share')}>
                                                    {exactAmount(p.share_percent)}%
                                                </td>
                                                <td data-label={t('Report access')}>
                                                    <span
                                                        className={`partner-status ${p.enabled ? 'is-enabled' : ''}`}
                                                    >
                                                        {t(p.enabled ? 'Enabled' : 'Disabled')}
                                                    </span>
                                                </td>
                                                <td>
                                                    <div className="partner-row-actions">
                                                        <button
                                                            type="button"
                                                            onClick={(event) => {
                                                                reportTrigger.current =
                                                                    event.currentTarget;
                                                                visit(p.id);
                                                            }}
                                                        >
                                                            {t('View report')}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => record(p)}
                                                        >
                                                            {t('Record entry')}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => edit(p)}
                                                        >
                                                            {t('Edit')}
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            ) : (
                                <div className="partner-list-empty">
                                    <strong>{t('No partners yet')}</strong>
                                    <p>
                                        {t('Add a partner by selecting a member of this company.')}
                                    </p>
                                    <button
                                        type="button"
                                        className="partner-admin-action"
                                        onClick={add}
                                    >
                                        {t('Add partner')}
                                    </button>
                                </div>
                            )}
                            <Pager page={partners} onPage={(page) => visit(undefined, page)} />
                        </section>
                        <Dialog
                            open={configurationOpen}
                            onOpenChange={(open) => {
                                if (
                                    !configuration.processing &&
                                    (open ||
                                        !configuration.isDirty ||
                                        confirm(t('Discard unsaved changes?')))
                                )
                                    setConfigurationOpen(open);
                            }}
                        >
                            <DialogContent
                                className="partner-dialog"
                                closeLabel={t('Close')}
                                closeDisabled={configuration.processing}
                                aria-describedby={undefined}
                            >
                                <DialogHeader>
                                    <DialogTitle>
                                        {t(editing ? 'Edit partner' : 'Add partner')}
                                    </DialogTitle>
                                </DialogHeader>
                                <form
                                    className="partner-form"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        configuration.post(
                                            `/platform/tenants/${targetCompany}/partners`,
                                            {
                                                preserveScroll: true,
                                                onSuccess: () => setConfigurationOpen(false),
                                            },
                                        );
                                    }}
                                >
                                    <label>
                                        {t('Company')}
                                        <select
                                            required
                                            disabled={!!editing || configuration.processing}
                                            value={targetCompany}
                                            onChange={(e) => {
                                                setTargetCompany(e.target.value);
                                                configuration.setData('account_id', '');
                                            }}
                                        >
                                            <option value="">{t('Choose a company')}</option>
                                            {companies.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <div className="partner-member-field">
                                        <label htmlFor="partner-user">{t('Select member')}</label>
                                        {editing ? (
                                            <div className="partner-selected-member">
                                                <strong>{editing.display_name || '—'}</strong>
                                                <span className="break-all">
                                                    {editing.email || '—'}
                                                </span>
                                            </div>
                                        ) : (
                                            <PartnerUserSelect
                                                key={targetCompany + String(configurationOpen)}
                                                companyId={targetCompany}
                                                value={configuration.data.account_id}
                                                onChange={(value) =>
                                                    configuration.setData('account_id', value)
                                                }
                                            />
                                        )}
                                    </div>
                                    <label>
                                        {t('Partner share')} (%)
                                        <input
                                            required
                                            inputMode="decimal"
                                            value={configuration.data.share_percent}
                                            onChange={(e) =>
                                                configuration.setData(
                                                    'share_percent',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                    <label>
                                        {t('Report access')}
                                        <select
                                            value={configuration.data.enabled ? 'yes' : 'no'}
                                            onChange={(e) =>
                                                configuration.setData(
                                                    'enabled',
                                                    e.target.value === 'yes',
                                                )
                                            }
                                        >
                                            <option value="yes">{t('Enabled')}</option>
                                            <option value="no">{t('Disabled')}</option>
                                        </select>
                                    </label>
                                    <button
                                        disabled={
                                            configuration.processing ||
                                            !targetCompany ||
                                            !configuration.data.account_id
                                        }
                                    >
                                        {t('Save')}
                                    </button>
                                    <Errors values={configuration.errors} />
                                </form>
                            </DialogContent>
                        </Dialog>
                        <Dialog
                            open={journalOpen}
                            onOpenChange={(open) => {
                                if (
                                    !journal.processing &&
                                    (open ||
                                        !journal.isDirty ||
                                        confirm(t('Discard unsaved changes?')))
                                )
                                    setJournalOpen(open);
                            }}
                        >
                            <DialogContent
                                className="partner-dialog"
                                closeLabel={t('Close')}
                                closeDisabled={journal.processing}
                                aria-describedby={undefined}
                            >
                                <DialogHeader>
                                    <DialogTitle>
                                        {t(
                                            journal.data.reverses_id
                                                ? 'Record reversal'
                                                : 'Record entry',
                                        )}{' '}
                                        · {journalAccount}
                                    </DialogTitle>
                                </DialogHeader>
                                <form
                                    id="partner-journal-form"
                                    className="partner-form"
                                    onSubmit={submitJournal}
                                >
                                    <label>
                                        {t('Type')}
                                        <select
                                            disabled={!!journal.data.reverses_id}
                                            value={journal.data.kind}
                                            onChange={(e) =>
                                                journal.setData('kind', e.target.value)
                                            }
                                        >
                                            <option value="REIMBURSEMENT">
                                                {t('Reimbursement')}
                                            </option>
                                            <option value="ADVANCE">{t('Advance')}</option>
                                            <option value="ADJUSTMENT_INCREASE">
                                                {t('Increase theoretical balance')}
                                            </option>
                                            <option value="ADJUSTMENT_DECREASE">
                                                {t('Decrease theoretical balance')}
                                            </option>
                                        </select>
                                    </label>
                                    <label>
                                        {t('Amount')} (USDT)
                                        <input
                                            required
                                            readOnly={!!journal.data.reverses_id}
                                            inputMode="decimal"
                                            value={journal.data.amount}
                                            onChange={(e) =>
                                                journal.setData('amount', e.target.value)
                                            }
                                        />
                                    </label>
                                    <label>
                                        {t('Business date')}
                                        <input
                                            required
                                            type="date"
                                            value={journal.data.business_date}
                                            onChange={(e) =>
                                                journal.setData('business_date', e.target.value)
                                            }
                                        />
                                    </label>
                                    <label>
                                        {journal.data.reverses_id
                                            ? t('Reversal reason')
                                            : t('Note')}
                                        <textarea
                                            required
                                            maxLength={2000}
                                            value={journal.data.note}
                                            onChange={(e) =>
                                                journal.setData('note', e.target.value)
                                            }
                                        />
                                    </label>
                                    <button disabled={journal.processing}>
                                        {t(
                                            journal.data.reverses_id
                                                ? 'Record reversal'
                                                : 'Record entry',
                                        )}
                                    </button>
                                    <Errors values={journal.errors} />
                                    <p className="stock-muted">
                                        {t(
                                            'Offline cooperation records only; these entries do not represent system payments.',
                                        )}
                                    </p>
                                </form>
                            </DialogContent>
                        </Dialog>
                        <details className="partner-rate-panel">
                            <summary>
                                {t('Rates pending')} ({pendingFees?.total ?? 0})
                            </summary>
                            {pendingFees?.data.map((f) => (
                                <div className="partner-admin-card" key={f.id}>
                                    <span>
                                        {exactAmount(f.original_amount)} {f.asset_code}
                                    </span>
                                    <small>
                                        {f.company_name} · {f.withdrawal_id}
                                    </small>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setFeeId(f.id);
                                            setFeeCompany(f.tenant_id);
                                            const values = {
                                                rate: '',
                                                observed_at: '',
                                                evidence: '',
                                                request_id: requestId(),
                                            };
                                            fx.setDefaults(values);
                                            fx.setData(values);
                                        }}
                                    >
                                        {t('Complete fixed rate')}
                                    </button>
                                </div>
                            ))}
                            <Pager
                                page={pendingFees}
                                onPage={(p) =>
                                    router.get(
                                        '/platform/partners',
                                        {
                                            company: companyId,
                                            partner: report?.partnerId,
                                            page: partners?.current_page,
                                            fees_page: p,
                                        },
                                        { preserveScroll: true, preserveState: true },
                                    )
                                }
                            />
                            <Dialog
                                open={!!feeId}
                                onOpenChange={(open) => {
                                    if (
                                        !open &&
                                        !fx.processing &&
                                        (!fx.isDirty || confirm(t('Discard unsaved changes?')))
                                    )
                                        setFeeId('');
                                }}
                            >
                                <DialogContent
                                    className="max-w-2xl"
                                    closeDisabled={fx.processing}
                                    aria-describedby={undefined}
                                >
                                    <DialogHeader>
                                        <DialogTitle>
                                            {t('Complete fixed rate')} ·{' '}
                                            {companies.find((c) => c.id === feeCompany)?.name}
                                        </DialogTitle>
                                    </DialogHeader>
                                    <form
                                        className="partner-form"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            fx.transform((data) => ({
                                                ...data,
                                                observed_at: new Date(
                                                    data.observed_at,
                                                ).toISOString(),
                                            }));
                                            fx.post(
                                                `/platform/tenants/${feeCompany}/fee-valuations/${feeId}`,
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () => setFeeId(''),
                                                },
                                            );
                                        }}
                                    >
                                        <label>
                                            {t('Fixed USDT rate')}
                                            <input
                                                required
                                                inputMode="decimal"
                                                value={fx.data.rate}
                                                onChange={(e) => fx.setData('rate', e.target.value)}
                                            />
                                        </label>
                                        <label>
                                            {t('Market observation time')}
                                            <input
                                                required
                                                type="datetime-local"
                                                value={fx.data.observed_at}
                                                onChange={(e) =>
                                                    fx.setData('observed_at', e.target.value)
                                                }
                                            />
                                        </label>
                                        <label>
                                            {t('Rate evidence')}
                                            <textarea
                                                required
                                                maxLength={2000}
                                                value={fx.data.evidence}
                                                onChange={(e) =>
                                                    fx.setData('evidence', e.target.value)
                                                }
                                            />
                                        </label>
                                        <button disabled={fx.processing}>
                                            {t('Save fixed rate')}
                                        </button>
                                        <Errors values={fx.errors} />
                                    </form>
                                </DialogContent>
                            </Dialog>
                        </details>
                        <Dialog
                            open={reportOpen && !journalOpen}
                            onOpenChange={(open) => {
                                if (!open) visit(undefined, partners?.current_page ?? 1);
                            }}
                        >
                            <DetailDrawerContent
                                className="p-0"
                                closeLabel={t('Close report')}
                                onCloseAutoFocus={(event) => {
                                    if (reportTrigger.current?.isConnected) {
                                        event.preventDefault();
                                        reportTrigger.current.focus();
                                    }
                                }}
                            >
                                <Tabs
                                    value={invitationPartner ? 'invitations' : 'stock'}
                                    onValueChange={(value) => {
                                        const partner =
                                            value === 'invitations'
                                                ? (report?.partnerId ?? null)
                                                : null;
                                        invitationLocation(partner, null, report?.partnerId);
                                        setInvitationPartner(partner);
                                    }}
                                    className="flex min-h-0 flex-1 flex-col"
                                >
                                    <DialogHeader className="mb-0 shrink-0 border-b px-4 py-4 pr-14">
                                        <DialogTitle>
                                            {t(
                                                invitationPartner
                                                    ? 'Invitation data'
                                                    : 'Stock data',
                                            )}
                                            {report && !reportLoading && !reportError
                                                ? ` · ${report.displayName || '—'} · ${t(report.version === 'partner' ? 'Partner version' : 'Standard version')}`
                                                : ''}
                                        </DialogTitle>
                                        <DialogDescription>
                                            {reportError
                                                ? t('Unable to load. Please retry.')
                                                : report && !reportLoading
                                                  ? `${companies.find((c) => c.id === reportCompanyId)?.name ?? ''} · ${report.email || '—'}`
                                                  : t('Loading…')}
                                        </DialogDescription>
                                    </DialogHeader>
                                    <TabsList
                                        className="mx-4 my-3 shrink-0 self-start"
                                        aria-label={t('View report')}
                                    >
                                        <TabsTrigger value="stock">{t('Stock data')}</TabsTrigger>
                                        <TabsTrigger
                                            value="invitations"
                                            disabled={reportLoading || !report}
                                        >
                                            {t('Invitation data')}
                                        </TabsTrigger>
                                    </TabsList>
                                    <TabsContent
                                        value="stock"
                                        className="mt-0 min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pb-4"
                                        data-detail-body
                                        scroll-region="true"
                                        aria-busy={reportLoading}
                                    >
                                        {reportLoading ? (
                                            <p className="py-10" role="status">
                                                {t('Loading…')}
                                            </p>
                                        ) : reportError ? (
                                            <div className="space-y-4 py-10" role="alert">
                                                <OperationFeedback>
                                                    {t('Unable to load. Please retry.')}
                                                </OperationFeedback>
                                                <button
                                                    className="partner-admin-action"
                                                    onClick={() => visit(...reportTarget.current)}
                                                >
                                                    {t('Retry')}
                                                </button>
                                            </div>
                                        ) : (
                                            report && (
                                                <div>
                                                    {hierarchyPartner && (
                                                        <PartnerHierarchyPanel
                                                            partner={hierarchyPartner}
                                                            company={reportCompanyId}
                                                            onClose={() => {
                                                                setHierarchyPartner(null);
                                                                requestAnimationFrame(() => {
                                                                    const el =
                                                                        hierarchyTrigger.current?.closest(
                                                                            '[data-detail-body]',
                                                                        );
                                                                    if (el)
                                                                        el.scrollTop =
                                                                            hierarchyScroll.current;
                                                                    hierarchyTrigger.current?.focus(
                                                                        { preventScroll: true },
                                                                    );
                                                                });
                                                            }}
                                                        />
                                                    )}
                                                    <div hidden={!!hierarchyPartner}>
                                                        <PartnerStockReport
                                                            onPartners={() => {
                                                                const el = document.querySelector(
                                                                    '[data-detail-body][scroll-region]',
                                                                );
                                                                hierarchyScroll.current =
                                                                    el?.scrollTop ?? 0;
                                                                hierarchyTrigger.current =
                                                                    document.activeElement as HTMLButtonElement;
                                                                setHierarchyPartner(
                                                                    report.partnerId,
                                                                );
                                                            }}
                                                            compactDecimals
                                                            compactHeader
                                                            showUserIdentity
                                                            report={report}
                                                            onPage={(page) =>
                                                                visit(report.partnerId, page)
                                                            }
                                                            onReverse={reverse}
                                                            onFlow={(flow, page) =>
                                                                visit(
                                                                    report.partnerId,
                                                                    report.journal.page,
                                                                    flow,
                                                                    page,
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                            )
                                        )}
                                    </TabsContent>
                                    <TabsContent
                                        value="invitations"
                                        className="mt-0 flex min-h-0 flex-1 flex-col data-[state=inactive]:hidden"
                                    >
                                        {invitationPartner && report && (
                                            <PartnerInvitationsReport
                                                key={report.partnerId}
                                                partner={report.partnerId}
                                                company={reportCompanyId}
                                            />
                                        )}
                                    </TabsContent>
                                </Tabs>
                            </DetailDrawerContent>
                        </Dialog>
                    </>
                }
            </div>
        </PlatformLayout>
    );
}
function Errors({ values }: { values: Record<string, string> }) {
    return (
        <div className="partner-error" role="alert">
            {Object.values(values).map((value, i) => (
                <OperationFeedback key={i}>{value}</OperationFeedback>
            ))}
        </div>
    );
}
function Pager({ page, onPage }: { page: Page<unknown> | null; onPage: (page: number) => void }) {
    return page && page.last_page > 1 ? (
        <nav className="stock-pagination">
            <button disabled={page.current_page <= 1} onClick={() => onPage(page.current_page - 1)}>
                {t('Previous')}
            </button>
            <span>
                {page.current_page} / {page.last_page}
            </span>
            <button
                disabled={page.current_page >= page.last_page}
                onClick={() => onPage(page.current_page + 1)}
            >
                {t('Next')}
            </button>
        </nav>
    ) : null;
}
