import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { useAdminTranslation, t, dateTime, countryName, errorMessage } from '@/i18n/admin';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PageHeader } from '@/components/shared/PageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Application = {
    id: string;
    user: { displayName: string | null; contact: string | null };
    documentType: string;
    documentCountry: string;
    maskedIdentityNumber: string;
    reviewStatus: string;
    submittedAt: string;
    reviewedAt: string | null;
};
export default function KycDetail({
    application,
    company,
    canViewDocuments,
}: {
    application: Application;
    company: { id: string; name: string };
    canViewDocuments: boolean;
}) {
    useAdminTranslation();
    const [password, setPassword] = useState('');
    const [documents, setDocuments] = useState<Record<string, string>>({});
    const [failed, setFailed] = useState<Record<string, boolean>>({});
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    async function showPhotos() {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            const response = await axios.post(
                `/platform/tenants/${company.id}/kyc/${application.id}/documents`,
                { password },
            );
            setDocuments(response.data.documents);
            setFailed({});
        } catch (e) {
            const data = axios.isAxiosError(e) ? e.response?.data : null;
            setError(
                errorMessage(
                    data?.errors?.password?.[0] ??
                        data?.error?.message ??
                        'Unable to load. Please try again.',
                ),
            );
        } finally {
            setPassword('');
            setBusy(false);
        }
    }
    return (
        <PlatformLayout>
            <Head title={t('Identity verification details')} />
            <div className="space-y-6">
                <PageHeader title={t('Identity verification details')} description={company.name} />
                <Link
                    className="text-primary underline"
                    href={`/platform/kyc?company=${company.id}`}
                >
                    {t('Back')}
                </Link>
                <dl className="grid grid-cols-2 gap-5 rounded-lg border bg-white p-6">
                    {[
                        ['User', application.user.displayName ?? '—'],
                        ['Email', application.user.contact ?? '—'],
                        ['Document type', t(application.documentType)],
                        ['Document country', countryName(application.documentCountry)],
                        ['Identity number', application.maskedIdentityNumber],
                        ['Status', t(application.reviewStatus)],
                        ['Submitted', dateTime(application.submittedAt)],
                        [
                            'Reviewed at',
                            application.reviewedAt ? dateTime(application.reviewedAt) : '—',
                        ],
                    ].map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-sm text-muted-foreground">{t(label)}</dt>
                            <dd className="mt-1 break-all">{value}</dd>
                        </div>
                    ))}
                </dl>
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
                                aria-label={t('Current password')}
                                placeholder={t('Current password')}
                                type="password"
                                autoComplete="current-password"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                            />
                            <Button type="submit" disabled={busy}>
                                {t('View photos')}
                            </Button>
                        </form>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'Verify your password to view photos. Access is temporary and audited.',
                            )}
                        </p>
                        {error && (
                            <p role="alert" className="text-sm text-red-600">
                                {error}
                            </p>
                        )}
                        <div className="grid grid-cols-2 gap-6">
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
                                    {failed[side] ? (
                                        <p className="text-sm text-red-600">
                                            {t(
                                                'Photo unavailable. Verify access again or check image storage.',
                                            )}
                                        </p>
                                    ) : (
                                        <a href={url} target="_blank" rel="noreferrer">
                                            <img
                                                className="max-h-96 w-full object-contain"
                                                src={url}
                                                alt={t('Identity document photos')}
                                                onError={() =>
                                                    setFailed((old) => ({ ...old, [side]: true }))
                                                }
                                            />
                                        </a>
                                    )}
                                </div>
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </PlatformLayout>
    );
}
