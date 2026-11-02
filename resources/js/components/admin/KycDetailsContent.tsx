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
    const [originalLinks, setOriginalLinks] = useState<Record<string, string>>({});
    const [originalStatuses, setOriginalStatuses] = useState<Record<string, 'copied' | 'manual'>>(
        {},
    );
    const [originalErrors, setOriginalErrors] = useState<Record<string, string>>({});
    const [copyingSide, setCopyingSide] = useState<string | null>(null);
    const copyRequest = useRef<AbortController | null>(null);
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
        setOriginalLinks({});
        setOriginalStatuses({});
        setOriginalErrors({});
        setCopyingSide(null);
        setBusy(false);
        setReviewBusy(false);
        return () => {
            generation.current++;
            copyRequest.current?.abort();
            copyRequest.current = null;
        };
    }, [application.id, company.id, canViewDocuments]);
    useEffect(() => {
        onBusyChange?.(reviewBusy || busy || copyingSide !== null);
    }, [reviewBusy, busy, copyingSide, onBusyChange]);
    async function showPhotos() {
        if (busy || copyingSide !== null) return;
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
    async function copyOriginal(side: 'front' | 'back') {
        if (!canViewDocuments || busy || reviewBusy || copyRequest.current) return;
        const current = generation.current;
        const controller = new AbortController();
        copyRequest.current = controller;
        setCopyingSide(side);
        setOriginalErrors((previous) => ({ ...previous, [side]: '' }));
        setOriginalLinks((previous) => ({ ...previous, [side]: '' }));
        setOriginalStatuses((previous) => {
            const next = { ...previous };
            delete next[side];
            return next;
        });
        let failureMessage = 'Unable to obtain the original image link. Please retry.';
        try {
            const response = await fetch(
                `/platform/tenants/${encodeURIComponent(company.id)}/kyc/${encodeURIComponent(application.id)}/documents/original`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: AbortSignal.any([controller.signal, AbortSignal.timeout(15000)]),
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN':
                            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                                ?.content ?? '',
                    },
                    body: JSON.stringify({ side, password }),
                },
            );
            if (response.status === 401 || response.status === 419 || response.redirected) {
                failureMessage = 'Your session has expired. Refresh the page and sign in again.';
                throw new Error();
            }
            const data = await readEditorResponse(response);
            if (generation.current !== current || controller.signal.aborted) return;
            if (!response.ok) {
                if (
                    response.status === 422 &&
                    data.errors &&
                    typeof data.errors === 'object' &&
                    'password' in data.errors
                )
                    failureMessage = 'Confirm your current administrator password and try again.';
                throw new Error();
            }
            if (
                data.tenantId !== company.id ||
                data.applicationId !== application.id ||
                data.side !== side ||
                typeof data.originalUrl !== 'string' ||
                !data.originalUrl
            )
                throw new Error();
            const originalUrl = data.originalUrl;
            const url = new URL(originalUrl);
            if (
                !['http:', 'https:'].includes(url.protocol) ||
                url.username ||
                url.password ||
                url.searchParams.has('expires')
            )
                throw new Error();
            try {
                await navigator.clipboard.writeText(originalUrl);
                if (generation.current !== current || controller.signal.aborted) return;
                setOriginalStatuses((previous) => ({ ...previous, [side]: 'copied' }));
            } catch {
                if (generation.current !== current || controller.signal.aborted) return;
                setOriginalLinks((previous) => ({ ...previous, [side]: originalUrl }));
                setOriginalStatuses((previous) => ({ ...previous, [side]: 'manual' }));
            }
        } catch {
            if (generation.current !== current || controller.signal.aborted) return;
            setOriginalErrors((previous) => ({ ...previous, [side]: failureMessage }));
        } finally {
            if (generation.current === current && !controller.signal.aborted) {
                setPassword('');
                setCopyingSide(null);
                copyRequest.current = null;
            }
        }
    }
    async function review(decision: 'approve' | 'reject' | 'retry') {
        if (
            reviewBusy ||
            busy ||
            copyingSide !== null ||
            !canReview ||
            !(decision === 'retry' ? retryable : reviewable)
        )
            return;
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
                                    disabled={reviewBusy || busy || copyingSide !== null}
                                    onClick={() => void review('approve')}
                                >
                                    {t('Approve')}
                                </Button>
                                <Button
                                    variant="secondary"
                                    disabled={reviewBusy || busy || copyingSide !== null}
                                    onClick={() => void review('reject')}
                                >
                                    {t('Reject')}
                                </Button>
                            </>
                        )}
                        {retryable && (
                            <Button
                                disabled={reviewBusy || busy || copyingSide !== null}
                                onClick={() => void review('retry')}
                            >
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
                            disabled={busy || copyingSide !== null}
                        >
                            {t('View photos')}
                        </Button>
                    </form>
                    <p className="text-sm text-muted-foreground">
                        {t('Verify your password to view photos or copy original image links.')}
                    </p>
                    {error && (
                        <OperationFeedback role="alert" className="text-sm text-red-600">
                            {error}
                        </OperationFeedback>
                    )}
                    <p className="text-sm text-muted-foreground">
                        {t('Original image links do not expire and need no renewal.')}
                    </p>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {(application.documentType === 'PASSPORT'
                            ? ['front' as const]
                            : ['front' as const, 'back' as const]
                        ).map((side) => (
                            <div
                                key={side}
                                className="min-w-0 space-y-3 rounded-lg border p-4"
                                data-kyc-document-side={side}
                            >
                                <h3>
                                    {t(
                                        application.documentType === 'PASSPORT'
                                            ? 'Passport information page'
                                            : side === 'front'
                                              ? 'ID card front'
                                              : 'ID card back',
                                    )}
                                </h3>
                                {documents[side] && (
                                    <PreviewImage
                                        className="max-h-96 w-full object-contain"
                                        src={documents[side]}
                                        sources={documentSources[side]}
                                        alt={t('Identity document photos')}
                                    />
                                )}
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={busy || reviewBusy || copyingSide !== null}
                                    onClick={() => void copyOriginal(side)}
                                >
                                    {t(
                                        copyingSide === side
                                            ? 'Obtaining original link…'
                                            : 'Copy original link',
                                    )}
                                </Button>
                                {originalStatuses[side] === 'copied' && (
                                    <p role="status" className="text-sm text-muted-foreground">
                                        {t('Original link copied.')}
                                    </p>
                                )}
                                {originalStatuses[side] === 'manual' && (
                                    <label className="block space-y-2 text-sm">
                                        <span role="status">
                                            {t(
                                                'Copy failed. Select and copy the original image link below.',
                                            )}
                                        </span>
                                        <Input
                                            readOnly
                                            autoComplete="off"
                                            aria-label={t('Original image link')}
                                            value={originalLinks[side] ?? ''}
                                            onFocus={(event) => event.currentTarget.select()}
                                            onClick={(event) => event.currentTarget.select()}
                                        />
                                    </label>
                                )}
                                {originalErrors[side] && (
                                    <p role="alert" className="text-sm text-destructive">
                                        {t(originalErrors[side])}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                </section>
            )}
        </div>
    );
}
