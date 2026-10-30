import {
    kycDisplayStatus,
    kycReviewable,
    kycRetryable,
    type KycActionEligibility,
} from './user-kyc-state';
import { showOperationResult } from './operation-result';
import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { router } from '@inertiajs/react';
import { readEditorResponse } from './editor-response';
import { PreviewImage } from '@/components/shared/PreviewImage';
import { useEffect, useRef, useState } from 'react';
import { useAdminTranslation, t, dateTime, countryName, errorMessage } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const processingErrorMessages: Record<string, string> = {
    IDENTITY_ACCOUNT_LIMIT_REACHED:
        'The document has reached the company account limit. Check existing verified accounts before reviewing.',
    KYC_OCR_MISMATCH:
        'Document number recognition failed validation. Check the documents before manual review or retry verification.',
    KYC_BACK_VALIDITY_UNRECOGNIZED:
        'The ID card back validity period could not be recognized or validated. Check the document before manual review or retry verification.',
    KYC_DOCUMENT_EXPIRED:
        'The ID card has expired. Check the document validity and any later submissions before reviewing.',
    KYC_DOCUMENT_NOT_YET_VALID:
        'The ID card is not yet valid. Check the document validity and any later submissions before reviewing.',
    KYC_OCR_UNAVAILABLE:
        'Document recognition is temporarily unavailable. Check the retained documents or retry verification.',
    KYC_DOCUMENT_STORAGE_FAILED:
        'The retained document images could not be read. Check document storage before retrying verification.',
    KYC_DOCUMENT_INTEGRITY_FAILED:
        'The retained document images failed integrity checks. Check the originals before retrying verification.',
    KYC_SUBMISSION_UNAVAILABLE:
        'Identity verification submission is unavailable. Check company, account and verification settings.',
    KYC_PROCESSING_UNAVAILABLE: 'Verification processing failed',
};

export type KycApplication = KycActionEligibility & {
    id: string;
    user: { id: string; displayName: string | null; contact: string | null };
    documentType: string;
    documentCountry: string;
    maskedIdentityNumber: string;
    requiresIdentityNumber?: boolean;
    reviewStatus: string;
    submittedAt: string;
    reviewedAt: string | null;
    ocrStatus?: string;
    processingStatus?: string | null;
    processingError?: string | null;
    processingAttempts?: number;
};
export function KycDetailsContent({
    application,
    company,
    canViewDocuments,
    canReview = false,
    onChanged,
    onOpenApplication,
    onBusyChange,
}: {
    application: KycApplication;
    company: { id: string; name: string };
    canViewDocuments: boolean;
    canReview?: boolean;
    onChanged?: (id: string) => void;
    onOpenApplication?: (id: string) => void;
    onBusyChange?: (busy: boolean) => void;
}) {
    useAdminTranslation();
    const [reason, setReason] = useState('');
    const reviewable = kycReviewable(application);
    const retryable = kycRetryable(application);
    const [identityNumber, setIdentityNumber] = useState('');
    const needsIdentityNumber =
        application.requiresIdentityNumber ??
        (application.documentType === 'NATIONAL_ID' && application.maskedIdentityNumber === '—');
    const [reviewBusy, setReviewBusy] = useState(false);
    const retryId = useRef<string | null>(null);
    const [password, setPassword] = useState('');
    const [documents, setDocuments] = useState<Record<string, string>>({});
    const [documentSources, setDocumentSources] = useState<Record<string, string[]>>({});
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const generation = useRef(0);
    useEffect(() => {
        generation.current++;
        retryId.current = null;
        setReason('');
        setIdentityNumber('');
        setDocuments({});
        setPassword('');
        setError('');
        setDocumentSources({});
        setBusy(false);
        setReviewBusy(false);
        return () => {
            generation.current++;
        };
    }, [application.id, company.id]);
    useEffect(() => {
        onBusyChange?.(reviewBusy || busy);
    }, [reviewBusy, busy, onBusyChange]);
    async function showPhotos() {
        if (busy) return;
        const current = generation.current;
        setBusy(true);
        setError('');
        try {
            const response = await fetch(
                `/platform/tenants/${company.id}/kyc/${application.id}/documents`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN':
                            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                                ?.content ?? '',
                    },
                    body: JSON.stringify({ password }),
                },
            );
            const data = (await response.json()) as {
                documents: Record<string, string>;
                documentSources?: Record<string, string[]>;
                errors?: { password?: string[] };
                error?: { message?: string };
            };
            if (generation.current !== current) return;
            if (!response.ok)
                throw new Error(
                    data?.errors?.password?.[0] ??
                        data?.error?.message ??
                        'Unable to load. Please try again.',
                );
            setDocuments(data.documents);
            setDocumentSources(data.documentSources ?? {});
        } catch (e) {
            if (generation.current !== current) return;
            setError(
                errorMessage(
                    e instanceof Error ? e.message : 'Unable to load. Please try again.',
                ) ?? t('Unable to load. Please try again.'),
            );
        } finally {
            if (generation.current === current) {
                setPassword('');
                setBusy(false);
            }
        }
    }
    async function review(decision: 'approve' | 'reject' | 'retry') {
        if (reviewBusy || !canReview || !(decision === 'retry' ? retryable : reviewable)) return;
        if (decision !== 'approve' && reason.trim().length < 3) {
            setError(t('Enter a reason.'));
            return;
        }
        if (decision === 'approve' && needsIdentityNumber && !identityNumber.trim()) {
            setError(t('Enter a valid identity number.'));
            return;
        }
        if (!window.confirm(t('Confirm this verification operation?'))) return;
        setReviewBusy(true);
        setError('');
        const current = generation.current;
        retryId.current ??= crypto.randomUUID();
        try {
            const response = await fetch(
                `/platform/tenants/${company.id}/kyc/${application.id}/${decision === 'retry' ? 'retry' : 'review'}`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN':
                            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                                ?.content ?? '',
                    },
                    body: JSON.stringify(
                        decision === 'retry'
                            ? { request_id: retryId.current, reason: reason.trim() }
                            : decision === 'approve'
                              ? {
                                    decision,
                                    ...(needsIdentityNumber
                                        ? { identity_number: identityNumber.trim() }
                                        : {}),
                                }
                              : { decision, reason_code: 'OTHER', review_message: reason.trim() },
                    ),
                },
            );
            const data = (await readEditorResponse(response)) as {
                applicationId?: string;
                error?: { code?: string; message?: string };
                errors?: Record<string, string[]>;
            };
            if (current !== generation.current) return;
            if (!response.ok && data.error?.code === 'KYC_APPLICATION_SUPERSEDED') {
                setError(
                    t(
                        'This application has been replaced by a newer submission. Open the current application to review it.',
                    ),
                );
                if (onChanged) onChanged(application.id);
                else router.reload();
                return;
            }
            if (!response.ok && data.error?.code === 'KYC_ALREADY_REVIEWED') {
                setError(t('This application has already been reviewed.'));
                if (onChanged) onChanged(application.id);
                else router.reload();
                return;
            }
            if (!response.ok)
                throw new Error(
                    data.error?.message ??
                        Object.values(data.errors ?? {}).flat()[0] ??
                        'Unable to load. Please retry.',
                );
            showOperationResult('success', 'Request completed.');
            setIdentityNumber('');
            if (onChanged) onChanged(data.applicationId ?? application.id);
            else router.reload();
        } catch (error) {
            if (current !== generation.current) return;
            setError(
                errorMessage(error instanceof Error ? error.message : '') ??
                    t('Unable to load. Please retry.'),
            );
        } finally {
            if (current === generation.current) setReviewBusy(false);
        }
    }
    return (
        <div className="space-y-4">
            <dl className="grid grid-cols-2 gap-5 rounded-lg border bg-white p-4">
                {[
                    ['User', application.user.displayName ?? '—'],
                    ['Email', application.user.contact ?? '—'],
                    ['Document type', t(application.documentType)],
                    ['Document country', countryName(application.documentCountry)],
                    ['Identity number', application.maskedIdentityNumber],
                    ['Status', t(kycDisplayStatus(application))],
                    [
                        'Processing status',
                        t(application.processingStatus ?? application.ocrStatus ?? '—'),
                    ],
                    [
                        'Processing error',
                        application.processingError
                            ? t(
                                  processingErrorMessages[application.processingError] ??
                                      'Verification processing failed',
                              )
                            : '—',
                    ],
                    ['Submitted', dateTime(application.submittedAt)],
                    [
                        'Reviewed at',
                        application.reviewedAt ? dateTime(application.reviewedAt) : '—',
                    ],
                ].map(([label, value]) => (
                    <div key={label}>
                        <dt className="text-sm text-muted-foreground">{t(label ?? '')}</dt>
                        <dd className="mt-1 break-all">{value}</dd>
                    </div>
                ))}
            </dl>
            {application.isSuperseded && (
                <section className="space-y-3 rounded-lg border bg-muted p-4">
                    <p>
                        {t(
                            'This application has been replaced by a newer submission. Open the current application to review it.',
                        )}
                    </p>
                    {application.currentApplicationId &&
                        application.currentApplicationId !== application.id && (
                            <Button
                                variant="secondary"
                                disabled={reviewBusy || busy}
                                onClick={() => {
                                    const id = application.currentApplicationId!;
                                    if (onOpenApplication) onOpenApplication(id);
                                    else
                                        router.visit(
                                            `/platform/tenants/${encodeURIComponent(company.id)}/kyc/${encodeURIComponent(id)}`,
                                        );
                                }}
                            >
                                {t('Open current application')}
                            </Button>
                        )}
                </section>
            )}
            <Button
                variant="secondary"
                disabled={reviewBusy || busy}
                onClick={() => (onChanged ? onChanged(application.id) : router.reload())}
            >
                {t('Refresh status')}
            </Button>
            {canReview && (reviewable || retryable) && (
                <section className="space-y-3 rounded-lg border p-4">
                    {reviewable && needsIdentityNumber && (
                        <label className="block space-y-2">
                            <span>{t('Identity number')}</span>
                            <Input
                                value={identityNumber}
                                onChange={(event) =>
                                    setIdentityNumber(event.target.value.toUpperCase())
                                }
                                maxLength={18}
                                autoComplete="off"
                                disabled={reviewBusy}
                                aria-label={t('Identity number')}
                            />
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'Enter the identity number before approving. Existing numbers cannot be changed.',
                                )}
                            </p>
                        </label>
                    )}
                    <label className="block space-y-2">
                        <span>{t('Reason')}</span>
                        <textarea
                            className="w-full rounded-md border p-2"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            maxLength={500}
                            disabled={reviewBusy}
                        />
                    </label>
                    <div className="flex flex-wrap gap-2">
                        {reviewable && (
                            <>
                                <Button
                                    disabled={reviewBusy}
                                    onClick={() => void review('approve')}
                                >
                                    {t('Approve')}
                                </Button>
                                <Button
                                    variant="secondary"
                                    disabled={reviewBusy}
                                    onClick={() => void review('reject')}
                                >
                                    {t('Reject')}
                                </Button>
                            </>
                        )}
                        {retryable && (
                            <Button disabled={reviewBusy} onClick={() => void review('retry')}>
                                {t('Retry verification')}
                            </Button>
                        )}
                    </div>
                    {error && (
                        <OperationFeedback role="alert" className="text-red-600">
                            {error}
                        </OperationFeedback>
                    )}
                </section>
            )}
            {canViewDocuments && (
                <section className="space-y-4">
                    <h2 className="font-semibold">{t('Identity document photos')}</h2>
                    <form
                        className="flex max-w-xl gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void showPhotos();
                        }}
                    >
                        <Input
                            className="min-w-0 flex-1"
                            aria-label={t('Current password')}
                            placeholder={t('Current password')}
                            type="password"
                            autoComplete="current-password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                        />
                        <Button
                            className="shrink-0 whitespace-nowrap"
                            type="submit"
                            disabled={busy}
                        >
                            {t('View photos')}
                        </Button>
                    </form>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Verify your password to view photos. Photos load directly from the configured storage.',
                        )}
                    </p>
                    {error && (
                        <OperationFeedback role="alert" className="text-sm text-red-600">
                            {error}
                        </OperationFeedback>
                    )}
                    <div className="grid grid-cols-2 gap-4">
                        {Object.entries(documents).map(([side, url]) => (
                            <div key={side} className="space-y-3 rounded-lg border p-4">
                                <h3>
                                    {t(
                                        application.documentType === 'PASSPORT'
                                            ? 'Passport information page'
                                            : side === 'front'
                                              ? 'ID card front'
                                              : 'ID card back',
                                    )}
                                </h3>
                                <PreviewImage
                                    className="max-h-96 w-full object-contain"
                                    src={url}
                                    sources={documentSources[side]}
                                    alt={t('Identity document photos')}
                                />
                            </div>
                        ))}
                    </div>
                </section>
            )}
        </div>
    );
}
