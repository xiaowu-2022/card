import { Head, useForm } from '@inertiajs/react';
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
};
export default function OssSettings({
    configuration,
    serverStorage,
    driverRevision,
}: {
    configuration: Configuration | null;
    serverStorage: boolean;
    driverRevision: number;
}) {
    useAdminTranslation();
    const mode = useForm({ storage_driver: serverStorage ? 'server' : 'oss', expected_revision: driverRevision });
    const [modeSaved, setModeSaved] = useState(false);
    const [result, setResult] = useState('');
    const form = useForm({
        expected_id: configuration?.id ?? null,
        region: configuration?.region ?? 'cn-beijing',
        bucket: configuration?.bucket ?? '',
        endpoint: configuration?.endpoint ?? '',
        public_url: configuration?.public_url ?? '',
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
    const submit = (test: boolean) => {
        setResult('');
        form.post(test ? '/platform/settings/oss/test' : '/platform/settings/oss', {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!test) {
                    form.setData({
                        ...form.data,
                        expected_id: (page.props.configuration as Configuration | null)?.id ?? null,
                        access_key_id: '',
                        access_key_secret: '',
                    });
                }
                setResult(t(test ? 'OSS connection verified.' : 'OSS configuration saved.'));
            },
        });
    };
    return (
        <PlatformSettingsLayout>
            <Head title={t('OSS storage')} />
            <Card className="max-w-3xl">
                <CardHeader>
                    <CardTitle>{t('OSS storage')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <p className="mb-4 text-sm text-muted-foreground">
                        {t(
                            'Edit this configuration directly. Test checks the current form without saving; save applies it without a separate activation step.',
                        )}
                    </p>
                    <form className="mb-6 space-y-3 border-b pb-6" onSubmit={(e) => {
                        e.preventDefault();
                        setModeSaved(false);
                        mode.post('/platform/settings/oss/driver', {
                            preserveScroll: true,
                            onSuccess: (page) => {
                                mode.setData({
                                    storage_driver: page.props.serverStorage ? 'server' : 'oss',
                                    expected_revision: page.props.driverRevision as number,
                                });
                                setModeSaved(true);
                            },
                        });
                    }}>
                        <FormField id="storage_driver" label={t('Image storage mode')} error={errorMessage(mode.errors.storage_driver)}>
                            <select id="storage_driver" className="w-full rounded-md border bg-background p-2"
                                value={mode.data.storage_driver} disabled={mode.processing || form.processing}
                                onChange={(e) => { mode.setData('storage_driver', e.target.value); setModeSaved(false); }}>
                                <option value="server">{t('Local server')}</option>
                                <option value="oss">{t('OSS')}</option>
                            </select>
                        </FormField>
                        <p className="text-sm text-muted-foreground">{t('The saved mode applies to new uploads. Existing image records and OSS settings are retained.')}</p>
                        <Button type="submit" disabled={mode.processing || form.processing}>{t('Save storage mode')}</Button>
                        {modeSaved && <p role="status" className="text-sm text-emerald-700">{t('Storage mode saved.')}</p>}
                    </form>
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            submit(false);
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
                                        type={key.startsWith('access_key') ? 'password' : 'text'}
                                        autoComplete="off"
                                        disabled={form.processing || mode.processing}
                                        required={!key.startsWith('access_key') || !configuration}
                                        value={form.data[field]}
                                        onChange={(e) => {
                                            form.setData(field, e.target.value);
                                            setResult('');
                                        }}
                                        placeholder={
                                            key.startsWith('access_key') && configuration
                                                ? t('Leave blank to keep the saved value')
                                                : key === 'endpoint'
                                                  ? 'https://oss-cn-beijing.aliyuncs.com'
                                                  : ''
                                        }
                                    />
                                </FormField>
                            );
                        })}
                        <p role="alert" className="text-sm text-destructive">
                            {errorMessage((form.errors as Record<string, string>).form)}
                        </p>
                        {result && (
                            <p role="status" className="text-sm text-emerald-700">
                                {result}
                            </p>
                        )}
                        <div className="flex gap-3">
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={form.processing || mode.processing}
                                onClick={() => submit(true)}
                            >
                                {t('Test connection')}
                            </Button>
                            <Button type="submit" disabled={form.processing || mode.processing}>
                                {t('Save configuration')}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </PlatformSettingsLayout>
    );
}
