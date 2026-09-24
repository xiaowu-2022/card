import { useAdminTranslation, t, errorMessage, dateTime, countryName } from '@/i18n/admin';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Eye, LockKeyhole } from 'lucide-react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge, type StatusTone } from '@/components/shared/StatusBadge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';
import type { SharedProps } from '@/types/global';

type Application = {
    id: string;
    user: { id: string; displayName: string | null; contact: string | null };
    documentType: string;
    documentCountry: string;
    maskedIdentityNumber: string;
    documentsSubmitted: boolean;
    ocrStatus: string;
    ocrSummary: {
        identityMatch: 'MATCH' | 'MISMATCH' | 'UNKNOWN';
        candidateName: string | null;
        confidence: string | null;
    } | null;
    reviewStatus: string;
    reviewReasonCode: string | null;
    reviewMessage: string | null;
    reviewerName: string | null;
    automaticallyApproved?: boolean;
    submittedAt: string;
    reviewedAt: string | null;
};
const tone = (status: string): StatusTone =>
    status === 'APPROVED' || status === 'SUCCEEDED'
        ? 'SUCCESS'
        : status === 'REJECTED' || status === 'FAILED'
          ? 'DANGER'
          : status === 'PENDING' || status === 'PROCESSING' || status === 'RESUBMISSION_REQUIRED'
            ? 'WARNING'
            : 'NEUTRAL';

export default function KycDetail({
    application,
    recentlyAuthenticated,
}: {
    application: Application;
    recentlyAuthenticated: boolean;
}) {
    useAdminTranslation();
    const permissions = usePage<SharedProps>().props.auth.admin?.permissions ?? [];
    const review = useForm<{ reason_code: string; review_message: string; form?: string }>({
        reason_code: 'DOCUMENT_UNREADABLE',
        review_message: '',
        form: undefined,
    });
    const recent = useForm<{ password: string; form?: string }>({
        password: '',
        form: undefined,
    });
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const submitReview = (action: 'reject' | 'resubmission') =>
        review.post(`/admin/kyc/${application.id}/${action}`);
    return (
        <TenantAdminLayout>
            <Head title={t('KYC application')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('KYC application')}
                    title={application.user.displayName ?? t('Unnamed user')}
                    description={application.user.contact ?? application.user.id}
                />
                {(review.errors.form || recent.errors.form) && (
                    <Alert className="border-red-200 bg-red-50">
                        <AlertDescription>
                            {errorMessage(review.errors.form) ?? errorMessage(recent.errors.form)}
                        </AlertDescription>
                    </Alert>
                )}
                <div className="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(20rem,1fr)]">
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('Submission')}</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-5 sm:grid-cols-2">
                                <Info
                                    label={t('Document type')}
                                    value={t(application.documentType)}
                                />
                                <Info
                                    label={t('Country')}
                                    value={countryName(application.documentCountry)}
                                />
                                <Info
                                    label={t('Identity number')}
                                    value={application.maskedIdentityNumber}
                                />
                                <Info
                                    label={t('Submitted')}
                                    value={dateTime(application.submittedAt)}
                                />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('OCR summary')}</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <StatusBadge
                                    status={tone(application.ocrStatus)}
                                    label={t(application.ocrStatus)}
                                />
                                {application.ocrSummary && (
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <Info
                                            label={t('Identity comparison')}
                                            value={t(application.ocrSummary.identityMatch)}
                                        />
                                        <Info
                                            label={t('Candidate name')}
                                            value={application.ocrSummary.candidateName ?? '—'}
                                        />
                                        <Info
                                            label={t('Confidence')}
                                            value={
                                                application.ocrSummary.confidence
                                                    ? `${application.ocrSummary.confidence} / 1`
                                                    : '—'
                                            }
                                        />
                                    </div>
                                )}
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'OCR is an untrusted review hint and never approves an application automatically.',
                                    )}
                                </p>
                            </CardContent>
                        </Card>
                        {permissions.includes('kyc.document.view') && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>{t('Private documents')}</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {!recentlyAuthenticated ? (
                                        <form
                                            className="max-w-sm space-y-4"
                                            onSubmit={(event) => {
                                                event.preventDefault();
                                                recent.post('/admin/recent-auth', {
                                                    onSuccess: () => recent.reset(),
                                                });
                                            }}
                                        >
                                            <Alert>
                                                <LockKeyhole className="size-4" />
                                                <AlertDescription>
                                                    {t(
                                                        'Confirm your current administrator password to unlock document access for 15 minutes.',
                                                    )}
                                                </AlertDescription>
                                            </Alert>
                                            <FormField
                                                id="recent-password"
                                                label={t('Password')}
                                                error={errorMessage(recent.errors.password)}
                                            >
                                                <Input
                                                    id="recent-password"
                                                    type="password"
                                                    value={recent.data.password}
                                                    onChange={(event) =>
                                                        recent.setData(
                                                            'password',
                                                            event.target.value,
                                                        )
                                                    }
                                                    autoComplete="current-password"
                                                />
                                            </FormField>
                                            <Button disabled={recent.processing}>
                                                {t('Confirm identity')}
                                            </Button>
                                        </form>
                                    ) : (
                                        <div className="flex flex-wrap gap-3">
                                            {(['front', 'back'] as const)
                                                .filter(
                                                    (side) =>
                                                        application.documentType !== 'PASSPORT' ||
                                                        side === 'front',
                                                )
                                                .map((side) => (
                                                    <form
                                                        key={side}
                                                        method="post"
                                                        target="_blank"
                                                        action={`/admin/kyc/${application.id}/documents/${side}/access`}
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="_token"
                                                            value={csrf}
                                                        />
                                                        <Button>
                                                            <Eye className="mr-2 size-4" />
                                                            {t(
                                                                side === 'front'
                                                                    ? 'View ID front'
                                                                    : 'View ID back',
                                                            )}
                                                        </Button>
                                                    </form>
                                                ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('Manual review')}</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                <StatusBadge
                                    status={tone(application.reviewStatus)}
                                    label={t(application.reviewStatus)}
                                />
                                {application.reviewStatus === 'PENDING' &&
                                permissions.includes('kyc.review') ? (
                                    <>
                                        <ReviewDecisionDialog
                                            label={t('Approve identity')}
                                            title={t('Approve this identity?')}
                                            description={t(
                                                "This creates the user's current verified Identity Record. The application cannot be reviewed again.",
                                            )}
                                            onConfirm={() =>
                                                router.post(`/admin/kyc/${application.id}/approve`)
                                            }
                                        />
                                        <div className="border-t pt-5">
                                            <div className="space-y-4">
                                                <FormField
                                                    id="reason-code"
                                                    label={t('Reason')}
                                                    error={errorMessage(review.errors.reason_code)}
                                                >
                                                    <Select
                                                        value={review.data.reason_code}
                                                        onValueChange={(value) =>
                                                            review.setData('reason_code', value)
                                                        }
                                                    >
                                                        <SelectTrigger id="reason-code">
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {[
                                                                'DOCUMENT_UNREADABLE',
                                                                'DOCUMENT_MISMATCH',
                                                                'INFORMATION_MISMATCH',
                                                                'DOCUMENT_EXPIRED',
                                                                'OTHER',
                                                            ].map((reason) => (
                                                                <SelectItem
                                                                    key={reason}
                                                                    value={reason}
                                                                >
                                                                    {t(reason)}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </FormField>
                                                <FormField
                                                    id="review-message"
                                                    label={t('User-facing message')}
                                                    error={errorMessage(
                                                        review.errors.review_message,
                                                    )}
                                                >
                                                    <Textarea
                                                        id="review-message"
                                                        value={review.data.review_message}
                                                        onChange={(event) =>
                                                            review.setData(
                                                                'review_message',
                                                                event.target.value,
                                                            )
                                                        }
                                                        maxLength={500}
                                                    />
                                                </FormField>
                                                <div className="grid gap-2 sm:grid-cols-2">
                                                    <ReviewDecisionDialog
                                                        label={t('Reject')}
                                                        title={t('Reject this application?')}
                                                        description={t(
                                                            'Rejection is terminal. The user cannot self-resubmit in V1.',
                                                        )}
                                                        variant="destructive"
                                                        disabled={
                                                            review.processing ||
                                                            review.data.review_message.trim()
                                                                .length < 3
                                                        }
                                                        onConfirm={() => submitReview('reject')}
                                                    />
                                                    <ReviewDecisionDialog
                                                        label={t('Request resubmission')}
                                                        title={t('Request new documents?')}
                                                        description={t(
                                                            'This application becomes final and the user may submit a linked replacement.',
                                                        )}
                                                        variant="secondary"
                                                        disabled={
                                                            review.processing ||
                                                            review.data.review_message.trim()
                                                                .length < 3
                                                        }
                                                        onConfirm={() =>
                                                            submitReview('resubmission')
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </>
                                ) : (
                                    <div className="space-y-2 text-sm">
                                        <Info
                                            label={t('Reviewer')}
                                            value={
                                                application.automaticallyApproved
                                                    ? t('System automatic approval')
                                                    : (application.reviewerName ?? '—')
                                            }
                                        />
                                        <Info
                                            label={t('Reviewed')}
                                            value={
                                                application.reviewedAt
                                                    ? dateTime(application.reviewedAt)
                                                    : '—'
                                            }
                                        />
                                        {application.reviewReasonCode && (
                                            <Info
                                                label={t('Reason')}
                                                value={t(application.reviewReasonCode)}
                                            />
                                        )}
                                        {application.reviewMessage && (
                                            <Alert>
                                                <AlertDescription>
                                                    {application.reviewMessage}
                                                </AlertDescription>
                                            </Alert>
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </TenantAdminLayout>
    );
}

function Info({ label, value }: { label: string; value: string }) {
    useAdminTranslation();
    return (
        <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {t(label)}
            </p>
            <p className="mt-1 break-words text-sm font-medium">{value}</p>
        </div>
    );
}

function ReviewDecisionDialog({
    label,
    title,
    description,
    onConfirm,
    variant = 'default',
    disabled = false,
}: {
    label: string;
    title: string;
    description: string;
    onConfirm: () => void;
    variant?: 'default' | 'secondary' | 'destructive';
    disabled?: boolean;
}) {
    useAdminTranslation();
    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>
                <Button className="w-full" variant={variant} disabled={disabled}>
                    {t(label)}
                </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <div className="space-y-2">
                    <AlertDialogTitle className="text-lg font-semibold">{title}</AlertDialogTitle>
                    <AlertDialogDescription>{description}</AlertDialogDescription>
                </div>
                <div className="mt-6 flex justify-end gap-2">
                    <AlertDialogCancel asChild>
                        <Button variant="secondary">{t('Cancel')}</Button>
                    </AlertDialogCancel>
                    <AlertDialogAction asChild>
                        <Button variant={variant} onClick={onConfirm}>
                            {t('Confirm')}
                        </Button>
                    </AlertDialogAction>
                </div>
            </AlertDialogContent>
        </AlertDialog>
    );
}
