import { useForm } from '@/components/admin/editor-context';
import { ConfigurationForm } from '@/components/admin/CompanyConfiguration';
import { useCompanyConfigurationUrl } from '@/hooks/useCompanyConfigurationUrl';
import { t, errorMessage } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';

export type AndroidReleaseSettings = {
    current: { appId: string; versionName: string; versionCode: number } | null;
    revision: string;
    available: boolean;
    downloadUrl: string;
};

export function AndroidReleaseForm({ release }: { release: AndroidReleaseSettings }) {
    const configurationUrl = useCompanyConfigurationUrl();
    const form = useForm({
        appId: release.current?.appId ?? '',
        versionName: '',
        versionCode: '',
        revision: release.revision,
        apk: null as File | null,
        confirmed: false,
    });
    return (
        <Card className="max-w-3xl">
            <CardHeader>
                <CardTitle>{t('Android release')}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <p className="text-sm">
                    {t('Current Android release')}:{' '}
                    {release.current
                        ? `${release.current.versionName} (${release.current.versionCode})`
                        : t('Not published')}
                    {' · '}
                    {t(release.available ? 'Available' : 'Version check unavailable')}
                </p>
                <p className="text-sm text-muted-foreground">
                    {t(
                        'Upload the signed APK and enter its exact packaging metadata. Publishing a newer version requires older apps to update.',
                    )}
                </p>
                <p className="break-all text-sm text-muted-foreground">
                    {t(
                        'Place the same APK at the fixed download address before publishing. This form does not upload to the download host.',
                    )}{' '}
                    <a
                        className="underline"
                        href={release.downloadUrl}
                        target="_blank"
                        rel="noreferrer"
                    >
                        {release.downloadUrl}
                    </a>
                </p>
                <ConfigurationForm
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(configurationUrl('/admin/settings/android-release'), {
                            forceFormData: true,
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                form.clearErrors();
                            },
                        });
                    }}
                >
                    <fieldset disabled={form.processing} className="space-y-4">
                        <FormField
                            id="android-apk"
                            label={t('Signed APK file')}
                            error={errorMessage(form.errors.apk)}
                        >
                            <Input
                                id="android-apk"
                                type="file"
                                accept=".apk"
                                required
                                onChange={(event) =>
                                    form.setData('apk', event.target.files?.[0] ?? null)
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                {t('APK files only, up to 250 MB.')}
                            </p>
                        </FormField>
                        <FormField
                            id="android-appid"
                            label={t('DCloud AppID')}
                            error={errorMessage(form.errors.appId)}
                        >
                            <Input
                                id="android-appid"
                                value={form.data.appId}
                                placeholder="__UNI__…"
                                required
                                maxLength={100}
                                readOnly={Boolean(release.current?.appId)}
                                onChange={(event) => form.setData('appId', event.target.value)}
                            />
                        </FormField>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                id="android-version-name"
                                label={t('Version name')}
                                error={errorMessage(form.errors.versionName)}
                            >
                                <Input
                                    id="android-version-name"
                                    value={form.data.versionName}
                                    placeholder="2.3.59"
                                    required
                                    maxLength={100}
                                    onChange={(event) =>
                                        form.setData('versionName', event.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                id="android-version-code"
                                label={t('Version code')}
                                error={errorMessage(form.errors.versionCode)}
                            >
                                <Input
                                    id="android-version-code"
                                    type="number"
                                    min={1}
                                    max={2100000000}
                                    step={1}
                                    value={form.data.versionCode}
                                    placeholder="2359"
                                    required
                                    onChange={(event) =>
                                        form.setData('versionCode', event.target.value)
                                    }
                                />
                            </FormField>
                        </div>
                        <FormField
                            id="android-confirmed"
                            label={t('Publish confirmation')}
                            error={errorMessage(form.errors.confirmed)}
                        >
                            <label className="flex items-start gap-2 text-sm">
                                <Checkbox
                                    id="android-confirmed"
                                    checked={form.data.confirmed}
                                    onCheckedChange={(checked) =>
                                        form.setData('confirmed', checked === true)
                                    }
                                />
                                {t(
                                    'I confirm the version details match this APK and the same APK is ready at the download address.',
                                )}
                            </label>
                        </FormField>
                        {form.errors.revision && (
                            <p role="alert" className="text-sm text-destructive">
                                {errorMessage(form.errors.revision)}
                            </p>
                        )}
                        {form.progress && (
                            <p role="status" className="text-sm">
                                {t('Uploading APK')}: {form.progress.percentage}%
                            </p>
                        )}
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.confirmed || !form.data.apk}
                        >
                            {t(
                                form.processing
                                    ? 'Publishing Android release…'
                                    : 'Publish Android release',
                            )}
                        </Button>
                    </fieldset>
                </ConfigurationForm>
            </CardContent>
        </Card>
    );
}
