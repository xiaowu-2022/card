import { t, useClientTranslation, errorMessage } from '@/i18n';
import { Head, useForm } from '@inertiajs/react';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserStatusBanner } from '@/components/user/UserStatusBanner';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { SearchSelect } from '@/components/ui/search-select';
import { countryOptions, useCardGeography, type Country } from '@/hooks/useCardGeography';
import { UserLayout } from '@/layouts/UserLayout';

type KycStatus = 'NOT_SUBMITTED' | 'PENDING' | 'APPROVED' | 'REJECTED' | 'RESUBMISSION_REQUIRED';
type Props = {
    kyc: {
        status: KycStatus;
        reviewMessage: string | null;
        submittedAt: string | null;
        verifiedAt: string | null;
        documentCountry: string | null;
        maskedIdentityNumber: string | null;
    };
    canSubmit: boolean;
    maxDocumentMb: number;
    backHref: '/account' | '/account/security';
};

const content = {
    NOT_SUBMITTED: {
        title: 'Identity verification',
        description: 'Verify your identity to continue.',
        tone: 'neutral',
    },
    PENDING: {
        title: 'Verification under review',
        description: 'Your information has been submitted.',
        tone: 'pending',
    },
    APPROVED: {
        title: 'Identity verified',
        description: 'Your identity verification is complete.',
        tone: 'success',
    },
    REJECTED: {
        title: 'Verification could not be approved',
        description: 'Please contact support for assistance.',
        tone: 'warning',
    },
    RESUBMISSION_REQUIRED: {
        title: 'Action required',
        description: 'Please submit a new set of identity documents.',
        tone: 'warning',
    },
} satisfies Record<
    KycStatus,
    { title: string; description: string; tone: 'neutral' | 'pending' | 'success' | 'warning' }
>;

export default function Kyc({ kyc, canSubmit, maxDocumentMb, backHref }: Props) {
    const { i18n } = useClientTranslation();
    const countries = useCardGeography<Country[]>('countries');
    const countryItems = countryOptions(countries.data ?? [], i18n.language);
    const form = useForm<{
        document_type: string;
        document_country: string;
        identity_number: string;
        front: File | null;
        back: File | null;
        form?: string;
    }>({
        document_type: 'NATIONAL_ID',
        document_country: 'CN',
        identity_number: '',
        front: null,
        back: null,
        form: undefined,
    });
    const state = content[kyc.status];
    return (
        <UserLayout>
            <Head title={t('Identity verification')} />
            <div className="mx-auto max-w-3xl space-y-6 sm:space-y-8">
                <UserPageHeader title={t('Identity verification')} backHref={backHref} />
                <UserStatusBanner
                    title={t(state.title)}
                    description={
                        kyc.reviewMessage && kyc.status === 'RESUBMISSION_REQUIRED'
                            ? kyc.reviewMessage
                            : t(state.description)
                    }
                    tone={state.tone}
                />
                {canSubmit && (
                    <Card className="rounded-[var(--user-radius-lg)] shadow-none">
                        <CardHeader>
                            <CardTitle>
                                {kyc.status === 'RESUBMISSION_REQUIRED'
                                    ? t('Resubmit documents')
                                    : t('Submit identity documents')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {form.errors.form && (
                                <Alert className="mb-5 border-red-200 bg-red-50">
                                    <AlertDescription>
                                        {errorMessage(form.errors.form)}
                                    </AlertDescription>
                                </Alert>
                            )}
                            <form
                                className="space-y-5"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(
                                        backHref === '/account/security'
                                            ? '/kyc/applications?from=account-security'
                                            : '/kyc/applications',
                                        { forceFormData: true },
                                    );
                                }}
                            >
                                <FormField
                                    id="document-type"
                                    label={t('Document type')}
                                    error={errorMessage(form.errors.document_type)}
                                >
                                    <select
                                        id="document-type"
                                        className="w-full rounded-lg border p-3"
                                        value={form.data.document_type}
                                        disabled={form.processing}
                                        onChange={(event) =>
                                            form.setData((data) => ({
                                                ...data,
                                                document_type: event.target.value,
                                                document_country: 'CN',
                                                front: null,
                                                back: null,
                                            }))
                                        }
                                    >
                                        <option value="NATIONAL_ID">
                                            {t('Mainland China identity card')}
                                        </option>
                                        <option value="PASSPORT">{t('Passport')}</option>
                                    </select>
                                </FormField>
                                <FormField
                                    id="document-country"
                                    label={t('Document country')}
                                    error={errorMessage(form.errors.document_country)}
                                >
                                    <SearchSelect
                                        id="document-country"
                                        label={t('Document country')}
                                        value={form.data.document_country}
                                        options={
                                            form.data.document_type === 'NATIONAL_ID'
                                                ? countryItems.filter((item) => item.value === 'CN')
                                                : countryItems
                                        }
                                        placeholder={t('Please select')}
                                        searchLabel={t('Search options')}
                                        emptyLabel={t('No matching options')}
                                        disabled={form.processing || !countryItems.length}
                                        invalid={!!form.errors.document_country}
                                        onValueChange={(value) => {
                                            form.setData('document_country', value);
                                            form.clearErrors('document_country');
                                        }}
                                    />
                                    {countries.failed && (
                                        <div role="alert" className="text-sm text-destructive">
                                            {t('Location options could not be loaded.')}{' '}
                                            <button
                                                type="button"
                                                className="underline"
                                                onClick={countries.retry}
                                            >
                                                {t('Try again')}
                                            </button>
                                        </div>
                                    )}
                                </FormField>
                                <FormField
                                    id="identity-number"
                                    label={t('Identity number')}
                                    description={t(
                                        'Enter the document number exactly as shown in the uploaded image.',
                                    )}
                                    error={errorMessage(form.errors.identity_number)}
                                >
                                    <Input
                                        id="identity-number"
                                        required
                                        value={form.data.identity_number}
                                        onChange={(event) =>
                                            form.setData('identity_number', event.target.value)
                                        }
                                        autoComplete="off"
                                    />
                                </FormField>
                                <div className="grid gap-5 sm:grid-cols-2">
                                    <FormField
                                        id="id-front"
                                        label={t(
                                            form.data.document_type === 'PASSPORT'
                                                ? 'Passport information page'
                                                : 'ID front',
                                        )}
                                        description={t('JPEG, PNG or WEBP · max {{value1}} MB', {
                                            value1: maxDocumentMb,
                                        })}
                                        error={errorMessage(form.errors.front)}
                                    >
                                        <Input
                                            id="id-front"
                                            key={form.data.document_type}
                                            required
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            onChange={(event) =>
                                                form.setData(
                                                    'front',
                                                    event.target.files?.[0] ?? null,
                                                )
                                            }
                                        />
                                    </FormField>
                                    {form.data.document_type === 'NATIONAL_ID' && (
                                        <FormField
                                            id="id-back"
                                            label={t('ID back')}
                                            description={t(
                                                'JPEG, PNG or WEBP · max {{value1}} MB',
                                                {
                                                    value1: maxDocumentMb,
                                                },
                                            )}
                                            error={errorMessage(form.errors.back)}
                                        >
                                            <Input
                                                id="id-back"
                                                required
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp"
                                                onChange={(event) =>
                                                    form.setData(
                                                        'back',
                                                        event.target.files?.[0] ?? null,
                                                    )
                                                }
                                            />
                                        </FormField>
                                    )}
                                </div>
                                <Button
                                    className="w-full sm:w-auto"
                                    disabled={form.processing || !countryItems.length}
                                >
                                    {form.processing ? t('Submitting…') : t('Submit for review')}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}
                {!canSubmit &&
                    (kyc.status === 'NOT_SUBMITTED' || kyc.status === 'RESUBMISSION_REQUIRED') && (
                        <Alert>
                            <AlertDescription>
                                {t(
                                    'New submissions are currently unavailable. Your account or Tenant may be restricted, or KYC may be disabled.',
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
            </div>
        </UserLayout>
    );
}
