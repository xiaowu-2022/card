import { useEffect, useState, type RefObject } from 'react';
import { Dialog, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { DetailDrawerContent } from './DetailDrawer';
import { KycDetailsContent, type KycApplication } from './KycDetailsContent';
import { readEditorResponse } from './editor-response';
import { Button } from '@/components/ui/button';
import { t, dateTime } from '@/i18n/admin';

import { kycLocation, type KycTarget } from './user-kyc-state';
function initialSelection() {
    const query = new URL(location.href).searchParams;
    const page = Number(query.get('kyc_page') ?? 1);
    return {
        application: query.get('kyc_application') ?? '',
        page: Number.isInteger(page) && page > 0 ? page : 1,
    };
}
type Detail = {
    company: { id: string; name: string };
    user: { id: string; displayName: string | null; email: string | null };
    applications: {
        items: { id: string; reviewStatus: string; submittedAt: string }[];
        page: number;
        lastPage: number;
        total: number;
    };
    application: KycApplication | null;
    canViewDocuments: boolean;
};
export function UserKycDrawer({
    target,
    trigger,
    onClose,
}: {
    target: KycTarget;
    trigger: RefObject<HTMLElement | null>;
    onClose: () => void;
}) {
    const [selection, setSelection] = useState(initialSelection);
    const [detail, setDetail] = useState<Detail | null>(null);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        const sync = () => setSelection(initialSelection());
        window.addEventListener('popstate', sync);
        return () => window.removeEventListener('popstate', sync);
    }, []);
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setFailed(false);
        setDetail(null);
        const params = new URLSearchParams({ page: String(selection.page) });
        if (selection.application) params.set('application', selection.application);
        void fetch(
            `/platform/tenants/${encodeURIComponent(target.company)}/users/${encodeURIComponent(target.user)}/kyc?${params}`,
            {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            },
        )
            .then(async (response) => {
                const data = await readEditorResponse(response);
                if (!response.ok || !data.user || !data.company || !data.applications)
                    throw new Error('Invalid response');
                if (!controller.signal.aborted) setDetail(data as Detail);
            })
            .catch(() => {
                if (!controller.signal.aborted) setFailed(true);
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [target.company, target.user, selection, retry]);
    const select = (application: string, page: number) => {
        kycLocation(target, application, page);
        setSelection({ application, page });
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DetailDrawerContent
                className="p-0"
                closeLabel={t('Close')}
                onCloseAutoFocus={(event) => {
                    if (trigger.current?.isConnected) {
                        event.preventDefault();
                        trigger.current.focus();
                    }
                }}
            >
                <DialogHeader className="mb-0 shrink-0 border-b px-4 py-4 pr-14">
                    <DialogTitle>
                        {t('Identity verification details')}
                        {detail ? ` · ${detail.user.displayName || '—'}` : ''}
                    </DialogTitle>
                    <DialogDescription>
                        {detail
                            ? `${detail.company.name} · ${detail.user.email || '—'}`
                            : t('View identity information and document photos.')}
                    </DialogDescription>
                </DialogHeader>
                <div
                    className="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain p-4"
                    data-detail-body
                    scroll-region="true"
                    aria-busy={loading}
                >
                    {loading ? (
                        <p role="status">{t('Loading…')}</p>
                    ) : failed ? (
                        <div role="alert" className="space-y-3">
                            <p>{t('Unable to load. Please retry.')}</p>
                            <Button onClick={() => setRetry((n) => n + 1)}>{t('Retry')}</Button>
                        </div>
                    ) : (
                        detail && (
                            <>
                                {detail.applications.total > 1 && (
                                    <div className="flex flex-wrap items-end gap-3">
                                        <label className="min-w-0 flex-1 space-y-2">
                                            <span>{t('Verification history')}</span>
                                            <select
                                                className="h-9 w-full rounded-md border bg-surface px-3"
                                                value={detail.application?.id ?? ''}
                                                onChange={(event) =>
                                                    select(event.target.value, selection.page)
                                                }
                                            >
                                                {detail.application &&
                                                    !detail.applications.items.some(
                                                        (item) =>
                                                            item.id === detail.application?.id,
                                                    ) && (
                                                        <option value={detail.application.id}>
                                                            {dateTime(
                                                                detail.application.submittedAt,
                                                            )}{' '}
                                                            · {t(detail.application.reviewStatus)}
                                                        </option>
                                                    )}
                                                {detail.applications.items.map((item) => (
                                                    <option key={item.id} value={item.id}>
                                                        {dateTime(item.submittedAt)} ·{' '}
                                                        {t(item.reviewStatus)}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                        {detail.applications.lastPage > 1 && (
                                            <>
                                                <Button
                                                    variant="secondary"
                                                    disabled={selection.page <= 1}
                                                    onClick={() => select('', selection.page - 1)}
                                                >
                                                    {t('Previous')}
                                                </Button>
                                                <span>
                                                    {selection.page} /{' '}
                                                    {detail.applications.lastPage}
                                                </span>
                                                <Button
                                                    variant="secondary"
                                                    disabled={
                                                        selection.page >=
                                                        detail.applications.lastPage
                                                    }
                                                    onClick={() => select('', selection.page + 1)}
                                                >
                                                    {t('Next')}
                                                </Button>
                                            </>
                                        )}
                                    </div>
                                )}
                                {detail.application ? (
                                    <KycDetailsContent
                                        key={detail.application.id}
                                        application={detail.application}
                                        company={detail.company}
                                        canViewDocuments={detail.canViewDocuments}
                                    />
                                ) : (
                                    <p>{t('No identity verification submitted yet.')}</p>
                                )}
                            </>
                        )
                    )}
                </div>
            </DetailDrawerContent>
        </Dialog>
    );
}
