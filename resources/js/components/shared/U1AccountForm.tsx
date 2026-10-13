import { useCallback, useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { t, errorMessage, dateTime } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AdminPagination } from '@/components/admin/AdminPagination';
import { OperationFeedback } from '@/components/admin/OperationFeedback';

export type U1Configuration = {
    customerNo: string;
    baseUrl: string;
    callbackUrl: string | null;
    provider: 'U1';
    enabled: boolean;
    zeroFeesConfirmed: boolean;
    checkStatus: string;
    checkedAt: string | null;
    groups: { groupNo: string; bin: string; currency: string; model: string }[];
    types: string[];
};
type TestResult = {
    id: string;
    type: string;
    status: 'REQUESTING' | 'ACCEPTED' | 'REJECTED' | 'UNKNOWN';
    notificationStatus: 'WAITING' | 'RECEIVED' | 'NOT_RECEIVED';
    notificationId: string | null;
    response: Record<string, unknown> | null;
};
type Notification = {
    id: string;
    notification_type: string | null;
    verified: boolean;
    verification_reason: string;
    processing_status: string;
    delivery_count: number;
    received_at: string;
    last_received_at: string;
};
type NotificationPage = {
    items: Notification[];
    page: number;
    pages: number;
    total: number;
    requests: TestResult[];
};
type NotificationDetail = {
    id: string;
    type: string | null;
    verified: boolean;
    reason: string;
    processingStatus: string;
    deliveries: number;
    receivedAt: string;
    lastReceivedAt: string;
    fields: Record<string, unknown>;
    payload: Record<string, unknown> | null;
    signedFields: string[];
};

async function request<T>(url: string, data?: object, signal?: AbortSignal): Promise<T> {
    const csrf = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.slice(11) ?? '',
    );
    const response = await fetch(url, {
        method: data ? 'POST' : 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        signal,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrf,
        },
        body: data ? JSON.stringify(data) : undefined,
    });
    if (!response.ok || response.redirected) {
        throw new Error(
            t('Unable to load U1 test data. Refresh and check the saved configuration.'),
        );
    }
    return (await response.json()) as T;
}

const typeLabels: Record<string, string> = {
    'openvcc.success': 'Card issue succeeded',
    'openvcc.failed': 'Card issue failed',
    'updatevcc.success': 'Card updated',
    otp: 'OTP notification',
    'rechargevcc.success': 'Card recharge succeeded',
    'rechargevcc.failed': 'Card recharge failed',
    'withdrawvcc.success': 'Card withdrawal succeeded',
    'withdrawvcc.fail': 'Card withdrawal failed',
    transaction: 'Card transaction',
};
const statusLabels: Record<string, string> = {
    REQUESTING: 'Test request in progress',
    ACCEPTED: 'Test request accepted',
    REJECTED: 'Test request rejected',
    UNKNOWN: 'Test request result unknown',
    WAITING: 'Waiting for notification',
    RECEIVED: 'Notification received',
    NOT_RECEIVED: 'No notification received yet',
    VERIFIED: 'Signature verified',
    INVALID_JSON: 'Invalid notification JSON',
    UNSUPPORTED_TYPE: 'Unsupported notification type',
    MISSING_SIGNED_FIELD: 'Missing signed notification field',
    INVALID_TIMESTAMP: 'Invalid notification timestamp',
    INVALID_SIGNATURE: 'Invalid notification signature',
};
const amountFields = [
    ['Card identifier', ['cardno', 'cardNo']],
    ['Provider request ID', ['apiId']],
    ['Transaction time', ['tradetime', 'tradeTime']],
    ['Transaction status', ['status', 'tradeStatus']],
    ['Transaction type', ['tradetype', 'tradeType']],
    ['Transaction amount', ['amount', 'tradeAmt', 'tradeAmount']],
    ['Transaction currency', ['currency', 'tradeCurrency']],
    ['Billing amount', ['billingamount', 'billAmount']],
    ['Billing currency', ['billingcurrency', 'billCurrency']],
    ['Card balance', ['free', 'freeAmount']],
    ['Card balance currency', ['freecurrency']],
    ['Fee amount', ['feeAmount']],
    ['Fee currency', ['feeCurrency']],
    ['Merchant', ['merchantinfo', 'merchantName']],
] as const;

function NotificationTests({
    record,
    disabled,
}: {
    record: { id: string; version: number; u1: U1Configuration };
    disabled: boolean;
}) {
    const root = `/platform/card-providers/${record.id}/u1`;
    const [type, setType] = useState('transaction');
    const [sending, setSending] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [page, setPage] = useState<NotificationPage>({
        items: [],
        page: 1,
        pages: 1,
        total: 0,
        requests: [],
    });
    const [detail, setDetail] = useState<NotificationDetail | null>(null);
    const [copied, setCopied] = useState(false);
    const load = useCallback(
        async (target: number, signal?: AbortSignal) => {
            setLoading(true);
            try {
                const result = await request<NotificationPage>(
                    `${root}/notifications?page=${target}`,
                    undefined,
                    signal,
                );
                if (!signal?.aborted) {
                    setPage(result);
                    setError('');
                }
            } catch (failure) {
                if (!signal?.aborted) setError((failure as Error).message);
            } finally {
                if (!signal?.aborted) setLoading(false);
            }
        },
        [root],
    );
    useEffect(() => {
        const controller = new AbortController();
        void load(1, controller.signal);
        return () => controller.abort();
    }, [load]);
    useEffect(() => {
        if (
            sending ||
            error ||
            !page.requests.some((item) => item.notificationStatus === 'WAITING')
        )
            return;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            void load(page.page, controller.signal);
        }, 2000);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [page, sending, error, load]);
    const send = async (types: string[]) => {
        setSending(true);
        setError('');
        setDetail(null);
        try {
            for (const notificationType of types) {
                const result = await request<TestResult>(`${root}/tests`, {
                    request_id: crypto.randomUUID(),
                    version: record.version,
                    notification_type: notificationType,
                });
                setPage((current) => ({
                    ...current,
                    requests: [result, ...current.requests].slice(0, 9),
                }));
            }
            await load(1);
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setSending(false);
        }
    };
    const copyCallback = async () => {
        try {
            await navigator.clipboard.writeText(record.u1.callbackUrl!);
            setCopied(true);
        } catch {
            setError(t('Unable to copy. Select and copy the callback URL.'));
        }
    };
    const showDetail = async (id: string) => {
        setLoading(true);
        setError('');
        try {
            setDetail(await request<NotificationDetail>(`${root}/notifications/${id}`));
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setLoading(false);
        }
    };
    return (
        <section className="space-y-4 border-t pt-4">
            <h3 className="font-medium">{t('U1 notification testing')}</h3>
            <label className="block space-y-1 text-sm">
                <span>{t('Callback URL')}</span>
                <Input readOnly value={record.u1.callbackUrl ?? ''} />
            </label>
            <Button
                type="button"
                variant="secondary"
                disabled={!record.u1.callbackUrl}
                onClick={() => {
                    void copyCallback();
                }}
            >
                {t(copied ? 'Copied' : 'Copy callback URL')}
            </Button>
            <p className="text-xs text-muted-foreground">
                {t('Save this callback URL in the U1 developer console before testing.')}
            </p>
            <div className="flex flex-wrap gap-2">
                <select
                    aria-label={t('Notification type')}
                    className="min-w-0 rounded border p-2 text-sm"
                    value={type}
                    disabled={sending}
                    onChange={(e) => setType(e.target.value)}
                >
                    {record.u1.types.map((value) => (
                        <option key={value} value={value}>
                            {t(typeLabels[value] ?? value)}
                        </option>
                    ))}
                </select>
                <Button
                    type="button"
                    disabled={disabled || sending}
                    onClick={() => {
                        void send([type]);
                    }}
                >
                    {t(sending ? 'Sending test notifications…' : 'Send test notification')}
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    disabled={disabled || sending}
                    onClick={() => {
                        void send(record.u1.types);
                    }}
                >
                    {t('Test all notifications')}
                </Button>
            </div>
            {disabled && (
                <p className="text-xs text-muted-foreground">
                    {t('Save configuration changes before testing.')}
                </p>
            )}
            <p className="text-xs text-muted-foreground">
                {t(
                    'Random callbacks are observed by notification type and receive time; request association is not confirmed.',
                )}
            </p>
            {error && <OperationFeedback role="alert">{error}</OperationFeedback>}
            <div className="space-y-2" aria-live="polite">
                {page.requests.map((result) => (
                    <div key={result.id} className="rounded border p-2 text-xs">
                        <p>
                            {t(typeLabels[result.type] ?? result.type)} ·{' '}
                            {t(statusLabels[result.status] ?? result.status)} ·{' '}
                            {t(
                                statusLabels[result.notificationStatus] ??
                                    result.notificationStatus,
                            )}
                        </p>
                        {result.response && (
                            <details>
                                <summary>{t('Test response details')}</summary>
                                <pre className="max-h-40 overflow-auto whitespace-pre-wrap break-all">
                                    {JSON.stringify(result.response, null, 2)}
                                </pre>
                            </details>
                        )}
                    </div>
                ))}
            </div>
            <div className="flex items-center justify-between gap-2">
                <h4 className="text-sm font-medium">{t('Notification records')}</h4>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    disabled={loading || sending}
                    onClick={() => {
                        void load(page.page);
                    }}
                >
                    {t('Refresh')}
                </Button>
            </div>
            {page.items.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    {t('No U1 notifications received.')}
                </p>
            )}
            {page.items.map((item) => (
                <button
                    key={item.id}
                    type="button"
                    disabled={loading}
                    className="block w-full rounded border p-3 text-left text-xs"
                    onClick={() => {
                        void showDetail(item.id);
                    }}
                >
                    <span className="block">
                        {item.notification_type ?? t('Unknown')} · {t('Processing status')}:{' '}
                        {t(item.processing_status)} ·{' '}
                        {t(
                            statusLabels[item.verification_reason] ??
                                'Invalid notification signature',
                        )}
                    </span>
                    <span className="block text-muted-foreground">
                        {dateTime(item.received_at)} · {t('Delivery count')}: {item.delivery_count}
                    </span>
                </button>
            ))}
            <AdminPagination
                page={page.page}
                pages={page.pages}
                total={page.total}
                disabled={loading || sending}
                onPage={(target) => {
                    setDetail(null);
                    void load(target);
                }}
            />
            {detail && (
                <div className="space-y-3 rounded border p-3 text-xs">
                    <div className="flex justify-between">
                        <h4 className="font-medium">{t('Notification details')}</h4>
                        <Button
                            type="button"
                            size="sm"
                            variant="secondary"
                            onClick={() => setDetail(null)}
                        >
                            {t('Close')}
                        </Button>
                    </div>
                    <p>
                        {t(statusLabels[detail.reason] ?? 'Invalid notification signature')} ·{' '}
                        {t('Delivery count')}: {detail.deliveries}
                    </p>
                    <p>
                        {t('Processing status')}: {t(detail.processingStatus)}
                    </p>
                    {detail.verified &&
                        ['RETRY', 'UNMAPPED', 'PENDING'].includes(detail.processingStatus) && (
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                disabled={loading || sending}
                                onClick={() => {
                                    setLoading(true);
                                    void request(`${root}/notifications/${detail.id}/retry`, {})
                                        .then(() => load(page.page))
                                        .catch((failure) => setError(String(failure)))
                                        .finally(() => setLoading(false));
                                }}
                            >
                                {t('Reprocess notification')}
                            </Button>
                        )}
                    <p>
                        {t('Last received')}: {dateTime(detail.lastReceivedAt)}
                    </p>
                    <p>
                        {t(
                            'Notification amounts are not independently verified. Business balances are refreshed through scoped provider queries.',
                        )}
                    </p>
                    <dl className="grid grid-cols-2 gap-2">
                        {amountFields.map(([label, keys]) => {
                            const value = keys
                                .map((key) => detail.fields[key])
                                .find((value) => value !== null && value !== undefined);
                            return (
                                <div key={label} className="min-w-0">
                                    <dt className="text-muted-foreground">{t(label)}</dt>
                                    <dd className="break-all">
                                        {value == null
                                            ? t('Not provided')
                                            : typeof value === 'string'
                                              ? value
                                              : JSON.stringify(value)}
                                    </dd>
                                </div>
                            );
                        })}
                    </dl>
                    <p>
                        {t('Signed fields')}: {detail.signedFields.join(', ') || '—'}
                    </p>
                    <h4 className="font-medium">
                        {t('Complete payload (sensitive fields masked)')}
                    </h4>
                    <pre className="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded bg-muted p-2">
                        {detail.payload
                            ? JSON.stringify(detail.payload, null, 2)
                            : t('Invalid notification JSON')}
                    </pre>
                </div>
            )}
        </section>
    );
}

export function U1AccountForm({
    record,
    close,
}: {
    record: { id: string; name: string; version: number; u1: U1Configuration | null } | null;
    close: () => void;
}) {
    const form = useForm({
        provider_type: 'U1',
        name: record?.name ?? '',
        customer_no: record?.u1?.customerNo ?? '',
        api_key: '',
        enabled: record?.u1?.enabled ?? false,
        zero_fees_confirmed: record?.u1?.zeroFeesConfirmed ?? false,
        base_url: record?.u1?.baseUrl ?? 'https://api.uucard.cyou/v1',
        version: record?.version ?? 1,
        request_id: crypto.randomUUID(),
    });
    useEffect(() => {
        if (record) form.setData('version', record.version);
    }, [record?.version]);
    const [checking, setChecking] = useState(false);
    const [checkError, setCheckError] = useState('');
    const dirty =
        form.data.api_key !== '' ||
        form.data.name !== (record?.name ?? '') ||
        form.data.customer_no !== (record?.u1?.customerNo ?? '') ||
        form.data.enabled !== (record?.u1?.enabled ?? false) ||
        form.data.zero_fees_confirmed !== (record?.u1?.zeroFeesConfirmed ?? false);
    return (
        <div className="max-h-[75vh] space-y-4 overflow-y-auto">
            <p className="text-sm font-medium">U1</p>
            <p className="text-xs text-muted-foreground">
                {t(
                    'U1 uses card groups and asynchronous order results. Check the account and card groups before accepting new business.',
                )}
            </p>
            <form
                className="space-y-3"
                autoComplete="off"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform(({ request_id, version, ...data }) =>
                        record ? { ...data, version } : { ...data, request_id },
                    );
                    const options = {
                        preserveScroll: true,
                        onSuccess: close,
                        onFinish: () => form.setData('api_key', ''),
                    };
                    if (record) form.put(`/platform/card-providers/${record.id}`, options);
                    else form.post('/platform/card-providers', options);
                }}
            >
                <label className="block text-sm">
                    {t('Card provider name')}
                    <Input
                        required
                        maxLength={120}
                        value={form.data.name}
                        disabled={form.processing}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                </label>
                <label className="block text-sm">
                    customerNo
                    <Input
                        required
                        maxLength={100}
                        readOnly={!!record?.u1}
                        value={form.data.customer_no}
                        disabled={form.processing}
                        onChange={(e) => form.setData('customer_no', e.target.value)}
                    />
                </label>
                <label className="block text-sm">
                    apiKey
                    <Input
                        type="password"
                        autoComplete="new-password"
                        required={!record?.u1}
                        maxLength={2000}
                        value={form.data.api_key}
                        disabled={form.processing}
                        onChange={(e) => form.setData('api_key', e.target.value)}
                    />
                </label>
                <p className="text-xs text-muted-foreground">
                    {t('Leave credentials blank to retain the saved values.')}
                </p>
                <label className="block text-sm">
                    {t('API URL')}
                    <Input readOnly value={form.data.base_url} />
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={form.data.enabled}
                        disabled={form.processing}
                        onChange={(event) => form.setData('enabled', event.target.checked)}
                    />
                    {t('Accept new card business')}
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={form.data.zero_fees_confirmed}
                        disabled={form.processing}
                        onChange={(event) =>
                            form.setData('zero_fees_confirmed', event.target.checked)
                        }
                    />
                    {t('U1 has confirmed no additional recharge or withdrawal fees')}
                </label>
                <p className="text-xs text-muted-foreground">
                    {t(
                        'Recharge and return remain unavailable until funding fees are confirmed. No upstream requestId is sent; uncertain requests are never automatically resent.',
                    )}
                </p>
                {Object.entries(form.errors).map(([key, message]) => (
                    <OperationFeedback key={key} role="alert">
                        {errorMessage(message)}
                    </OperationFeedback>
                ))}
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t(form.processing ? 'Saving…' : 'Save')}
                    </Button>
                </div>
            </form>
            {record?.u1 && (
                <section className="space-y-3">
                    <p className="text-xs">
                        {t(
                            record.u1.checkStatus === 'VERIFIED'
                                ? 'Connection verified'
                                : record.u1.checkStatus === 'FAILED'
                                  ? 'Connection failed'
                                  : 'Not checked',
                        )}
                    </p>
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={dirty || form.processing || checking}
                        onClick={() => {
                            setChecking(true);
                            setCheckError('');
                            void request(`/platform/card-providers/${record.id}/check`, {})
                                .then(() => {
                                    window.location.reload();
                                })
                                .catch((failure: Error) => setCheckError(failure.message))
                                .finally(() => setChecking(false));
                        }}
                    >
                        {t(checking ? 'Checking…' : 'Check U1 account and synchronize card groups')}
                    </Button>
                    {dirty && (
                        <p className="text-xs text-muted-foreground">
                            {t('Save configuration changes before checking.')}
                        </p>
                    )}
                    {checkError && <OperationFeedback role="alert">{checkError}</OperationFeedback>}
                    {record.u1.groups.map((group) => (
                        <p className="break-all text-xs" key={group.groupNo}>
                            {t('Card group')}: {group.groupNo} · BIN {group.bin} · {group.currency}{' '}
                            · {group.model}
                        </p>
                    ))}
                </section>
            )}
            {record?.u1 && (
                <NotificationTests
                    key={record.id}
                    record={{ ...record, u1: record.u1 }}
                    disabled={dirty || form.processing || checking}
                />
            )}
            {!record?.u1 && (
                <p className="text-xs text-muted-foreground">
                    {t(
                        'Save the U1 connection, then reopen it to copy the callback URL and test notifications.',
                    )}
                </p>
            )}
        </div>
    );
}
