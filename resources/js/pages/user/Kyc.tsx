import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, Clock3, FileWarning, ShieldCheck, XCircle } from 'lucide-react';
import { PageHeader } from '@/components/shared/PageHeader';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
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
};

const content = {
    NOT_SUBMITTED: {
        title: 'Identity verification',
        description: 'Verify your identity to continue setting up your account.',
        icon: ShieldCheck,
    },
    PENDING: {
        title: 'Verification under review',
        description:
            'Your documents were submitted securely. We will update this page after manual review.',
        icon: Clock3,
    },
    APPROVED: {
        title: 'Identity verified',
        description: 'Your identity has been manually verified.',
        icon: CheckCircle2,
    },
    REJECTED: {
        title: 'Verification could not be approved',
        description: 'Please contact support for assistance.',
        icon: XCircle,
    },
    RESUBMISSION_REQUIRED: {
        title: 'Additional documents required',
        description: 'Please submit a new set of identity documents.',
        icon: FileWarning,
    },
} satisfies Record<KycStatus, { title: string; description: string; icon: typeof ShieldCheck }>;

export default function Kyc({ kyc, canSubmit, maxDocumentMb }: Props) {
    const form = useForm<{
        document_country: string;
        identity_number: string;
        front: File | null;
        back: File | null;
        form?: string;
    }>({ document_country: 'MY', identity_number: '', front: null, back: null, form: undefined });
    const state = content[kyc.status];
    const Icon = state.icon;
    return (
        <UserLayout>
            <Head title="Identity verification" />
            <div className="mx-auto max-w-3xl space-y-6">
                <PageHeader
                    eyebrow="Account verification"
                    title={state.title}
                    description={state.description}
                />
                <Card>
                    <CardContent className="flex gap-4 p-5 sm:p-6">
                        <div className="grid size-11 shrink-0 place-items-center rounded-full bg-muted">
                            <Icon className="size-5 text-primary" />
                        </div>
                        <div className="min-w-0 space-y-2 text-sm">
                            <p className="font-semibold">
                                Status: {kyc.status.replaceAll('_', ' ')}
                            </p>
                            {kyc.reviewMessage && (
                                <Alert>
                                    <AlertDescription>{kyc.reviewMessage}</AlertDescription>
                                </Alert>
                            )}
                            {kyc.verifiedAt && (
                                <p className="text-muted-foreground">
                                    Verified {new Date(kyc.verifiedAt).toLocaleDateString()}
                                </p>
                            )}
                            {kyc.documentCountry && <p>Document country: {kyc.documentCountry}</p>}
                            {kyc.maskedIdentityNumber && (
                                <p>Identity number: {kyc.maskedIdentityNumber}</p>
                            )}
                        </div>
                    </CardContent>
                </Card>
                {canSubmit && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {kyc.status === 'RESUBMISSION_REQUIRED'
                                    ? 'Resubmit documents'
                                    : 'Submit identity documents'}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {form.errors.form && (
                                <Alert className="mb-5 border-red-200 bg-red-50">
                                    <AlertDescription>{form.errors.form}</AlertDescription>
                                </Alert>
                            )}
                            <form
                                className="space-y-5"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post('/kyc/applications', { forceFormData: true });
                                }}
                            >
                                <FormField
                                    id="document-country"
                                    label="Document country"
                                    error={form.errors.document_country}
                                >
                                    <Input
                                        id="document-country"
                                        value={form.data.document_country}
                                        onChange={(event) =>
                                            form.setData(
                                                'document_country',
                                                event.target.value.toUpperCase(),
                                            )
                                        }
                                        maxLength={2}
                                        autoComplete="country"
                                        placeholder="MY"
                                    />
                                </FormField>
                                <FormField
                                    id="identity-number"
                                    label="Identity number"
                                    description="Enter the number exactly as shown on your national identity document."
                                    error={form.errors.identity_number}
                                >
                                    <Input
                                        id="identity-number"
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
                                        label="ID front"
                                        description={`JPEG, PNG or WEBP · max ${maxDocumentMb} MB`}
                                        error={form.errors.front}
                                    >
                                        <Input
                                            id="id-front"
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
                                    <FormField
                                        id="id-back"
                                        label="ID back"
                                        description={`JPEG, PNG or WEBP · max ${maxDocumentMb} MB`}
                                        error={form.errors.back}
                                    >
                                        <Input
                                            id="id-back"
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
                                </div>
                                <Button className="w-full sm:w-auto" disabled={form.processing}>
                                    {form.processing ? 'Submitting…' : 'Submit for review'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}
                {!canSubmit &&
                    (kyc.status === 'NOT_SUBMITTED' || kyc.status === 'RESUBMISSION_REQUIRED') && (
                        <Alert>
                            <AlertDescription>
                                New submissions are currently unavailable. Your account or Tenant
                                may be restricted, or KYC may be disabled.
                            </AlertDescription>
                        </Alert>
                    )}
            </div>
        </UserLayout>
    );
}
