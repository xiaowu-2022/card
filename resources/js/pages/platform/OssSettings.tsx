import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { PlatformSettingsLayout } from '@/layouts/PlatformSettingsLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { t, errorMessage, useAdminTranslation } from '@/i18n/admin';

type Configuration = {
    id: string;
    region: string;
    bucket: string;
    endpoint: string;
    public_url: string;
    verified_at: string | null;
};
export default function OssSettings({
    configurations,
    activeId,
    counts,
}: {
    configurations: Configuration[];
    activeId: string | null;
    counts: Record<string, number>;
}) {
    useAdminTranslation();
    const [busy, setBusy] = useState<string | null>(null);
    const [result, setResult] = useState<{ id: string; message: string; failed: boolean } | null>(
        null,
    );
    const form = useForm({
        region: '',
        bucket: '',
        endpoint: '',
        public_url: '',
        access_key_id: '',
        access_key_secret: '',
    });
    const fields = {
        region: 'OSS region',
        bucket: 'Storage bucket',
        endpoint: 'OSS endpoint',
        public_url: 'Image domain',
        access_key_id: 'AccessKey ID',
        access_key_secret: 'AccessKey Secret',
    } as const;
    const action = (id: string, kind: 'check' | 'activate') => {
        setBusy(id + ':' + kind);
        setResult(null);
        router.post(
            `/platform/settings/oss/${id}/${kind}`,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    setResult({
                        id,
                        failed: false,
                        message: t(
                            kind === 'check'
                                ? 'OSS connection verified.'
                                : 'OSS enabled for new uploads.',
                        ),
                    }),
                onError: (errors) =>
                    setResult({
                        id,
                        failed: true,
                        message:
                            errorMessage(errors.form ?? Object.values(errors)[0]) ??
                            t(
                                'OSS test failed. Check credentials, endpoint, public access and image domain.',
                            ),
                    }),
                onNetworkError: () => {
                    setResult({ id, failed: true, message: t('Connection failed. Please retry.') });
                    return false;
                },
                onHttpException: () => {
                    setResult({ id, failed: true, message: t('Connection failed. Please retry.') });
                    return false;
                },
                onFinish: () => setBusy(null),
            },
        );
    };
    return (
        <PlatformSettingsLayout>
            <Head title={t('OSS storage')} />
            <div className="space-y-6">
                <p className="text-sm text-muted-foreground">
                    {t(
                        'Images use public read access, including identity documents. Anyone with an image URL can view it. Upload and delete require server credentials.',
                    )}
                </p>
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>{t('New storage configuration')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="mb-4 text-sm text-muted-foreground">
                            {t(
                                'Saving creates a new version. Test it, then enable it for new uploads. Existing images keep their original storage configuration.',
                            )}
                        </p>
                        <form
                            className="space-y-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post('/platform/settings/oss', {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        form.reset('access_key_id', 'access_key_secret'),
                                });
                            }}
                        >
                            {Object.entries(fields).map(([key, label]) => {
                                const field = key as keyof typeof fields;
                                return (
                                    <FormField
                                        key={key}
                                        id={key}
                                        label={t(label)}
                                        error={errorMessage(form.errors[field])}
                                    >
                                        <Input
                                            id={key}
                                            type={
                                                key.startsWith('access_key') ? 'password' : 'text'
                                            }
                                            autoComplete="off"
                                            required
                                            value={form.data[field]}
                                            onChange={(e) => form.setData(field, e.target.value)}
                                            placeholder={
                                                key === 'region'
                                                    ? 'cn-beijing'
                                                    : key === 'endpoint'
                                                      ? 'https://oss-ap-southeast-1.aliyuncs.com'
                                                      : key === 'public_url'
                                                        ? 'https://images.example.com'
                                                        : ''
                                            }
                                        />
                                    </FormField>
                                );
                            })}
                            <p className="text-sm text-destructive">
                                {errorMessage((form.errors as Record<string, string>).form)}
                            </p>
                            <Button disabled={form.processing}>{t('Save configuration')}</Button>
                        </form>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Storage versions')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {!configurations.length && <p>{t('No storage configuration yet.')}</p>}
                        {configurations.map((config) => (
                            <div
                                className="flex flex-wrap items-center justify-between gap-4 rounded-lg border p-4"
                                key={config.id}
                            >
                                <div className="min-w-0">
                                    <div className="font-medium">
                                        {config.bucket} · {config.region}
                                    </div>
                                    <div className="break-all text-sm text-muted-foreground">
                                        {config.public_url}
                                    </div>
                                    <div className="text-sm">
                                        {config.id === activeId
                                            ? t('Enabled for new uploads')
                                            : config.verified_at
                                              ? t('Connection verified')
                                              : t('Not tested')}
                                    </div>
                                </div>
                                <div className="flex flex-col items-end gap-2">
                                    <div className="flex gap-2">
                                        <Button
                                            variant="secondary"
                                            disabled={busy !== null}
                                            onClick={() => action(config.id, 'check')}
                                        >
                                            {t(
                                                busy === config.id + ':check'
                                                    ? 'Testing connection…'
                                                    : 'Test connection',
                                            )}
                                        </Button>
                                        <Button
                                            disabled={
                                                busy !== null ||
                                                !config.verified_at ||
                                                config.id === activeId
                                            }
                                            onClick={() => action(config.id, 'activate')}
                                        >
                                            {t('Enable configuration')}
                                        </Button>
                                    </div>
                                    {result?.id === config.id && (
                                        <p
                                            role={result.failed ? 'alert' : 'status'}
                                            className={`max-w-lg text-sm ${result.failed ? 'text-destructive' : 'text-emerald-700'}`}
                                        >
                                            {result.message}
                                        </p>
                                    )}
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Image migration')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        <p>
                            {t(
                                'Preview historical images before migration. Verified copies switch to OSS; local backups are retained.',
                            )}
                        </p>
                        <p>
                            {t('Ready images')}: {counts.ready ?? 0} · {t('Pending cleanup')}:{' '}
                            {counts.cleanup_pending ?? 0}
                        </p>
                        <pre className="overflow-x-auto rounded bg-muted p-3">
                            {
                                'php artisan images:migrate-oss\nphp artisan images:migrate-oss --execute --limit=100\nphp artisan images:migrate-oss --execute --retry-failed --limit=100'
                            }
                        </pre>
                    </CardContent>
                </Card>
            </div>
        </PlatformSettingsLayout>
    );
}
