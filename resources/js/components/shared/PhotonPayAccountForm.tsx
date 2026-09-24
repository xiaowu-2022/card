import { useEffect, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { t, errorMessage } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type PhotonPayConfiguration = {
    identityLocked: boolean;
    environment: string;
    appId: string;
    accountId: string;
    memberId: string;
    matrixAccount: string;
    enabled: boolean;
    complete: boolean;
    checkStatus: string;
    checkedAt: string | null;
    migrationError: string | null;
    callbackUrl: string;
};
export function PhotonPayAccountForm({
    record,
    close,
}: {
    record: {
        id: string;
        name: string;
        version: number;
        photonpay: PhotonPayConfiguration | null;
    } | null;
    close: () => void;
}) {
    const c = record?.photonpay;
    const [checking, setChecking] = useState(false);
    const [checkErrors, setCheckErrors] = useState<string[]>([]);
    const form = useForm({
        name: record?.name ?? '',
        version: record?.version ?? 1,
        request_id: crypto.randomUUID(),
        environment: c?.environment ?? 'sandbox',
        enabled: c?.enabled ?? true,
        app_id: c?.appId ?? '',
        app_secret: '',
        private_key: '',
        webhook_public_key: '',
        account_id: c?.accountId ?? '',
        member_id: c?.memberId ?? '',
        matrix_account: c?.matrixAccount ?? '',
    });
    useEffect(() => {
        if (record) form.setData('version', record.version);
    }, [record?.version]);
    return (
        <form
            className="max-h-[75vh] space-y-3 overflow-y-auto"
            autoComplete="off"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((data) => data);
                const options = {
                    onSuccess: close,
                    onFinish: () =>
                        form.setData((data) => ({
                            ...data,
                            app_secret: '',
                            private_key: '',
                            webhook_public_key: '',
                        })),
                };
                if (record) form.put(`/platform/card-providers/${record.id}`, options);
                else form.post('/platform/card-providers', options);
            }}
        >
            <label className="block text-sm">
                {t('Card provider name')}
                <Input
                    required
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
            </label>
            <label className="block text-sm">
                {t('Environment')}
                <select
                    disabled={c?.identityLocked}
                    className="w-full rounded border p-2"
                    value={form.data.environment}
                    onChange={(e) => form.setData('environment', e.target.value)}
                >
                    <option value="sandbox">{t('Sandbox')}</option>
                    <option value="production">{t('Production')}</option>
                </select>
            </label>
            {(['app_id', 'account_id', 'member_id', 'matrix_account'] as const).map((key) => (
                <label key={key} className="block text-sm">
                    {
                        {
                            app_id: 'App ID',
                            account_id: t('USD account'),
                            member_id: t('Member ID'),
                            matrix_account: t('Matrix account (optional)'),
                        }[key]
                    }
                    <Input
                        disabled={c?.identityLocked && key !== 'app_id'}
                        required={key !== 'matrix_account'}
                        value={form.data[key]}
                        onChange={(e) => form.setData(key, e.target.value)}
                    />
                </label>
            ))}
            <p className="text-xs text-muted-foreground">
                {t('Leave credentials blank to retain the saved values.')}
            </p>
            <label className="block text-sm">
                App Secret
                <Input
                    type="password"
                    autoComplete="new-password"
                    required={!c}
                    value={form.data.app_secret}
                    onChange={(e) => form.setData('app_secret', e.target.value)}
                />
            </label>
            {(['private_key', 'webhook_public_key'] as const).map((key) => (
                <label key={key} className="block text-sm">
                    {t(
                        key === 'private_key'
                            ? 'Merchant signing private key'
                            : 'PhotonPay webhook public key',
                    )}
                    <textarea
                        className="w-full rounded border p-2 font-mono text-xs"
                        rows={3}
                        required={!c}
                        value={form.data[key]}
                        onChange={(e) => form.setData(key, e.target.value)}
                    />
                </label>
            ))}
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={form.data.enabled}
                    onChange={(e) => form.setData('enabled', e.target.checked)}
                />
                {t('Accept new card business')}
            </label>
            {c && (
                <div className="space-y-1 break-all text-xs">
                    <p>
                        {t(c.complete ? 'Configuration complete' : 'Configuration incomplete')} ·{' '}
                        {t(
                            c.checkStatus === 'VERIFIED'
                                ? 'Connection verified'
                                : c.checkStatus === 'FAILED'
                                  ? 'Connection failed'
                                  : 'Not checked',
                        )}
                    </p>
                    <p>
                        {t('Callback URL')}: {c.callbackUrl}
                    </p>
                    {c.migrationError && (
                        <p role="alert">{t('Legacy account configuration requires review.')}</p>
                    )}
                </div>
            )}
            <p className="text-xs text-muted-foreground">
                {t(
                    'Connection checks verify account ownership and BIN access, not issuing or request signing.',
                )}
            </p>
            {record && form.isDirty && (
                <p className="text-xs text-muted-foreground">
                    {t('Connection checks save your changes first.')}
                </p>
            )}
            {checkErrors.map((message, index) => (
                <p key={index} role="alert" className="text-sm text-destructive">
                    {errorMessage(message)}
                </p>
            ))}
            {Object.entries(form.errors).map(([key, message]) => (
                <p key={key} role="alert" className="text-sm text-destructive">
                    {errorMessage(message)}
                </p>
            ))}
            <div className="flex justify-end gap-2">
                {record && (
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={form.processing || checking}
                        onClick={() => {
                            setChecking(true);
                            setCheckErrors([]);
                            if (form.isDirty) {
                                form.transform((data) => ({ ...data, check_connection: true }));
                                form.put(`/platform/card-providers/${record.id}`, {
                                    preserveScroll: true,
                                    onFinish: () => {
                                        setChecking(false);
                                        form.setData((data) => ({
                                            ...data,
                                            app_secret: '',
                                            private_key: '',
                                            webhook_public_key: '',
                                        }));
                                        form.transform((data) => data);
                                    },
                                });
                            } else {
                                router.post(
                                    `/platform/card-providers/${record.id}/check`,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onError: (errors) => setCheckErrors(Object.values(errors)),
                                        onFinish: () => setChecking(false),
                                    },
                                );
                            }
                        }}
                    >
                        {t(
                            form.isDirty
                                ? 'Save and check connection'
                                : 'Check connection and refresh BINs',
                        )}
                    </Button>
                )}
                <Button type="submit" disabled={form.processing || checking}>
                    {t('Save')}
                </Button>
            </div>
        </form>
    );
}
