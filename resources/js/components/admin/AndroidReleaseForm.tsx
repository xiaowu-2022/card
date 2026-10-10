import { OperationFeedback } from '@/components/admin/OperationFeedback';
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
    debugEnabled?: boolean;
    downloadUrl: string;
    androidDownloadUrl: string;
    iosDistributionUrl: string | null;
};

export function AndroidReleaseForm({ release }: { release: AndroidReleaseSettings }) {
    const configurationUrl = useCompanyConfigurationUrl();
    const form = useForm({
        appId: release.current?.appId ?? '',
        androidDownloadUrl: release.androidDownloadUrl ?? release.downloadUrl,
        iosDistributionUrl: release.iosDistributionUrl ?? '',
        versionName: release.current?.versionName ?? '',
        versionCode: release.current ? String(release.current.versionCode) : '',
        revision: release.revision,
        confirmed: false,
        debugEnabled: release.debugEnabled ?? false,
    });
    return (
        <Card className="max-w-3xl">
            <CardHeader>
                <CardTitle>{t('App release')}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <p className="text-sm">
                    {t('Current app release')}:{' '}
                    {release.current
                        ? `${release.current.versionName} (${release.current.versionCode})`
                        : t('Not published')}
                    {' · '}
                    {t(release.available ? 'Available' : 'Version check unavailable')}
                </p>
                <p className="text-sm text-muted-foreground">
                    {t(
                        'Version details and download URLs can be edited directly. Updating a URL does not require a new version or repackaging.',
                    )}
                </p>
                <ConfigurationForm
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(configurationUrl('/admin/settings/android-release'), {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                form.clearErrors();
                            },
                        });
                    }}
                >
                    <fieldset disabled={form.processing} className="space-y-4">
                        {!release.current?.appId && (
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
                        )}
                        {release.current?.appId && form.errors.appId && (
                            <OperationFeedback role="alert" className="text-sm text-destructive">
                                {errorMessage(form.errors.appId)}
                            </OperationFeedback>
                        )}
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
                        {(['androidDownloadUrl', 'iosDistributionUrl'] as const).map((field) => (
                            <FormField
                                key={field}
                                id={field}
                                label={t(
                                    field === 'androidDownloadUrl'
                                        ? 'Android download URL'
                                        : 'iOS distribution page URL',
                                )}
                                error={errorMessage(form.errors[field])}
                            >
                                <Input
                                    id={field}
                                    type="url"
                                    required
                                    maxLength={2048}
                                    value={form.data[field]}
                                    onChange={(event) => form.setData(field, event.target.value)}
                                />
                            </FormField>
                        ))}
                        <FormField
                            id="app-debug-enabled"
                            label={t('App debug mode')}
                            error={errorMessage(form.errors.debugEnabled)}
                        >
                            <label className="flex items-start gap-2 text-sm">
                                <Checkbox
                                    id="app-debug-enabled"
                                    checked={form.data.debugEnabled}
                                    onCheckedChange={(checked) =>
                                        form.setData('debugEnabled', checked === true)
                                    }
                                />
                                {t(
                                    'Show page address, loading status and sanitized diagnostics in the app. Turning off clears diagnostics within 15 seconds while online. Requires a compatible APK.',
                                )}
                            </label>
                        </FormField>
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
                                    'I confirm both destinations provide the published version for their platform.',
                                )}
                            </label>
                        </FormField>
                        {form.errors.revision && (
                            <OperationFeedback role="alert" className="text-sm text-destructive">
                                {errorMessage(form.errors.revision)}
                            </OperationFeedback>
                        )}
                        <Button type="submit" disabled={form.processing || !form.data.confirmed}>
                            {t(form.processing ? 'Publishing app release…' : 'Publish app release')}
                        </Button>
                    </fieldset>
                </ConfigurationForm>
            </CardContent>
        </Card>
    );
}
