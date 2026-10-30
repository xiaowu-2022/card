import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { useEffect, useState, type RefObject } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { DetailDrawerContent } from './DetailDrawer';
import { KycDetailsContent, type KycApplication } from './KycDetailsContent';
import { readEditorResponse } from './editor-response';
import { t } from '@/i18n/admin';

type Detail = {
    application: KycApplication;
    company: { id: string; name: string };
    canViewDocuments: boolean;
    canReview: boolean;
};

export function KycApplicationDialog({
    companyId,
    applicationId,
    review,
    trigger,
    onClose,
    onChanged,
}: {
    companyId: string;
    applicationId: string;
    review: boolean;
    trigger: RefObject<HTMLButtonElement | null>;
    onClose: () => void;
    onChanged: () => void;
}) {
    const [selectedId, setSelectedId] = useState(applicationId);
    const [reviewing, setReviewing] = useState(review);
    const [detail, setDetail] = useState<Detail | null>(null);
    const [failed, setFailed] = useState(false);
    const [revision, setRevision] = useState(0);
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        setSelectedId(applicationId);
        setReviewing(review);
        setRevision(0);
    }, [companyId, applicationId, review]);
    useEffect(() => {
        const controller = new AbortController();
        setDetail(null);
        setFailed(false);
        void fetch(
            `/platform/tenants/${encodeURIComponent(companyId)}/kyc/${encodeURIComponent(selectedId)}`,
            {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            },
        )
            .then(async (response) => {
                const data = await readEditorResponse(response);
                if (!response.ok || !data.application || !data.company)
                    throw new Error('Invalid response');
                if (!controller.signal.aborted) setDetail(data as Detail);
            })
            .catch(() => {
                if (!controller.signal.aborted) setFailed(true);
            });
        return () => controller.abort();
    }, [companyId, selectedId, revision]);
    const Content = reviewing ? DialogContent : DetailDrawerContent;
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
        >
            <Content
                className={reviewing ? 'max-w-3xl' : undefined}
                closeLabel={t('Close')}
                closeDisabled={busy}
                onEscapeKeyDown={(event) => {
                    if (busy) event.preventDefault();
                }}
                onInteractOutside={(event) => event.preventDefault()}
                onCloseAutoFocus={(event) => {
                    if (trigger.current?.isConnected) {
                        event.preventDefault();
                        trigger.current.focus();
                    }
                }}
            >
                <DialogHeader className="shrink-0 pr-12">
                    <DialogTitle>
                        {t(reviewing ? 'Review' : 'Identity verification details')}
                    </DialogTitle>
                    <DialogDescription>
                        {detail
                            ? `${detail.company.name} · ${detail.application.user.contact ?? '—'}`
                            : t('KYC')}
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 flex-1 overflow-y-auto">
                    {failed ? (
                        <div role="alert" className="space-y-3">
                            <OperationFeedback>
                                {t('Unable to load. Please retry.')}
                            </OperationFeedback>
                            <Button onClick={() => setRevision((value) => value + 1)}>
                                {t('Retry')}
                            </Button>
                        </div>
                    ) : !detail ? (
                        <p role="status">{t('Loading...')}</p>
                    ) : (
                        <KycDetailsContent
                            {...detail}
                            canReview={reviewing && detail.canReview}
                            onBusyChange={setBusy}
                            onOpenApplication={(id) => {
                                setReviewing(true);
                                setSelectedId(id);
                                setRevision((value) => value + 1);
                            }}
                            onChanged={(id) => {
                                setBusy(false);
                                setSelectedId(id);
                                setRevision((value) => value + 1);
                                onChanged();
                            }}
                        />
                    )}
                </div>
            </Content>
        </Dialog>
    );
}
