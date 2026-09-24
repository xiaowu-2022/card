import { systemMoney } from '@/lib/system-money';
import { useEffect, useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { t, useClientTranslation, errorMessage, dateTime } from '@/i18n';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import { FormField } from '@/components/ui/form-field';
import { MoneyDisplay } from '@/components/user/UserMoney';
import type { SharedProps } from '@/types/global';

type Draft = { request_id: string; recipient_account_id: string; amount: string };
type Receipt = {
    id: string;
    requestId: string;
    amount: string;
    asset: string;
    sent: boolean;
    senderAccountId: string;
    recipientAccountId: string;
    createdAt: string;
};
function readDraft(key: string): Draft | null {
    try {
        const value: unknown = JSON.parse(sessionStorage.getItem(key) ?? 'null');
        if (!value || typeof value !== 'object') return null;
        const draft = value as Partial<Draft>;
        return typeof draft.request_id === 'string' &&
            /^[0-9a-f-]{36}$/.test(draft.request_id) &&
            typeof draft.recipient_account_id === 'string' &&
            /^\d{12}$/.test(draft.recipient_account_id) &&
            typeof draft.amount === 'string' &&
            /^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/.test(draft.amount)
            ? (draft as Draft)
            : null;
    } catch {
        return null;
    }
}

export default function Transfer({
    accountId,
    available,
    transferAvailable,
    receipt,
}: {
    accountId: string;
    available: { amount: string; asset: string } | null;
    transferAvailable: boolean;
    receipt: Receipt | null;
}) {
    useClientTranslation();
    const { tenant } = usePage<SharedProps>().props;
    const storageKey = `wallet-transfer:${tenant?.id}:${accountId}`;
    const [draft] = useState(() => readDraft(storageKey));
    const [reviewing, setReviewing] = useState(draft !== null);
    const [attempted, setAttempted] = useState(draft !== null);
    const form = useForm({
        request_id: draft?.request_id ?? crypto.randomUUID(),
        recipient_account_id: draft?.recipient_account_id ?? '',
        amount: draft?.amount ?? '',
        current_password: '',
        confirmed: false,
        form: '',
    });
    useEffect(() => {
        try {
            if (receipt && receipt.requestId === readDraft(storageKey)?.request_id)
                sessionStorage.removeItem(storageKey);
        } catch {
            // The immutable receipt remains authoritative if browser storage is blocked.
        }
    }, [receipt, storageKey]);
    return (
        <UserLayout handledSuccessMessage={receipt ? 'Transfer completed.' : undefined}>
            <Head title={t('Transfer')} />
            <div className="space-y-6">
                <UserPageHeader title={t('Transfer')} backHref="/dashboard" />
                {receipt ? (
                    <section className="transfer-receipt" aria-labelledby="transfer-result-title">
                        <div className="transfer-receipt-summary" role="status">
                            <span className="transfer-receipt-icon">
                                <CheckCircle2 aria-hidden="true" />
                            </span>
                            <h2 id="transfer-result-title">{t('Transfer completed.')}</h2>
                            <p className="transfer-receipt-amount">
                                <MoneyDisplay amount={receipt.amount} asset={receipt.asset} />
                            </p>
                        </div>
                        <dl className="transfer-receipt-details">
                            <div>
                                <dt>{t('Sender account ID')}</dt>
                                <dd className="tabular-nums">{receipt.senderAccountId}</dd>
                            </div>
                            <div>
                                <dt>{t('Recipient account ID')}</dt>
                                <dd className="tabular-nums">{receipt.recipientAccountId}</dd>
                            </div>
                            <div>
                                <dt>{t('Time')}</dt>
                                <dd>{dateTime(receipt.createdAt)}</dd>
                            </div>
                            <div className="transfer-receipt-reference">
                                <dt>{t('Transfer reference')}</dt>
                                <dd>{receipt.id}</dd>
                            </div>
                        </dl>
                        <div className="transfer-receipt-actions">
                            <Button asChild>
                                <Link href="/wallet/transfer">{t('New transfer')}</Link>
                            </Button>
                            <Button asChild variant="secondary">
                                <Link href="/dashboard">{t('Back to home')}</Link>
                            </Button>
                        </div>
                    </section>
                ) : !transferAvailable || !available ? (
                    <p role="status">
                        {t('Both accounts need active verified wallets in the same currency.')}
                    </p>
                ) : (
                    <form
                        className="space-y-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (form.processing) return;
                            if (!reviewing) {
                                if (
                                    !/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/.test(form.data.amount) ||
                                    !/[1-9]/.test(form.data.amount)
                                ) {
                                    form.setError('amount', 'Enter a positive transfer amount.');
                                    return;
                                }
                                if (form.data.recipient_account_id === accountId) {
                                    form.setError(
                                        'recipient_account_id',
                                        'The recipient is unavailable. Check the account ID and company.',
                                    );
                                    return;
                                }
                                form.clearErrors();
                                setReviewing(true);
                                return;
                            }
                            if (!form.data.confirmed || !form.data.current_password) return;
                            try {
                                sessionStorage.setItem(
                                    storageKey,
                                    JSON.stringify({
                                        request_id: form.data.request_id,
                                        recipient_account_id: form.data.recipient_account_id,
                                        amount: form.data.amount,
                                    }),
                                );
                            } catch {
                                form.setError(
                                    'form',
                                    'Transfer retry details could not be saved. Please enable browser storage.',
                                );
                                return;
                            }
                            setAttempted(true);
                            form.post('/wallet/transfers', {
                                preserveScroll: true,
                                onError: () => setAttempted(false),
                                onFinish: () => form.reset('current_password', 'confirmed'),
                            });
                        }}
                    >
                        <div>
                            <p className="text-sm text-muted-foreground">
                                {t('Available balance')}
                            </p>
                            <p className="text-3xl font-semibold">
                                <MoneyDisplay {...available} />
                            </p>
                        </div>
                        {!reviewing ? (
                            <>
                                <FormField
                                    id="recipient-account-id"
                                    label={t('Recipient account ID')}
                                    error={errorMessage(form.errors.recipient_account_id)}
                                >
                                    <Input
                                        id="recipient-account-id"
                                        inputMode="numeric"
                                        pattern="[0-9]{12}"
                                        maxLength={12}
                                        required
                                        value={form.data.recipient_account_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'recipient_account_id',
                                                e.target.value.replace(/\D/g, ''),
                                            )
                                        }
                                    />
                                </FormField>
                                <FormField
                                    id="transfer-amount"
                                    label={t('Transfer amount')}
                                    error={errorMessage(form.errors.amount)}
                                    description="$"
                                >
                                    <Input
                                        id="transfer-amount"
                                        inputMode="decimal"
                                        pattern="(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?"
                                        required
                                        value={form.data.amount}
                                        onChange={(e) => form.setData('amount', e.target.value)}
                                    />
                                </FormField>
                                <Button type="submit">{t('Review transfer')}</Button>
                            </>
                        ) : (
                            <>
                                <div className="rounded-xl border p-4 space-y-3">
                                    <h2 className="font-semibold">{t('Review transfer')}</h2>
                                    <p>
                                        {t('Recipient account ID')}:{' '}
                                        <span className="font-mono">
                                            {form.data.recipient_account_id}
                                        </span>
                                    </p>
                                    <p>
                                        {t('Transfer amount')}: {systemMoney(form.data.amount)}
                                    </p>
                                    <p className="text-sm">
                                        {t(
                                            'The same amount will be credited to the recipient. Check the account ID carefully; completed transfers cannot be cancelled here.',
                                        )}
                                    </p>
                                </div>
                                <FormField
                                    id="transfer-password"
                                    label={t('Current password')}
                                    error={errorMessage(form.errors.current_password)}
                                >
                                    <Input
                                        id="transfer-password"
                                        type="password"
                                        autoComplete="current-password"
                                        value={form.data.current_password}
                                        onChange={(e) =>
                                            form.setData('current_password', e.target.value)
                                        }
                                        required
                                    />
                                </FormField>
                                <label className="flex min-h-11 gap-3 items-start text-sm">
                                    <Checkbox
                                        className="shrink-0 min-h-5!"
                                        checked={form.data.confirmed}
                                        onCheckedChange={(value) =>
                                            form.setData('confirmed', value === true)
                                        }
                                    />
                                    <span>
                                        {t(
                                            'I have checked the recipient and amount and confirm this transfer.',
                                        )}
                                    </span>
                                </label>
                                <div className="flex flex-wrap gap-3">
                                    <Button
                                        type="submit"
                                        disabled={
                                            form.processing ||
                                            !form.data.confirmed ||
                                            !form.data.current_password
                                        }
                                    >
                                        {t('Confirm transfer')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        disabled={form.processing || attempted}
                                        onClick={() => {
                                            setReviewing(false);
                                            form.reset('current_password', 'confirmed');
                                        }}
                                    >
                                        {t('Edit')}
                                    </Button>
                                </div>
                                {attempted && (
                                    <p className="text-sm text-muted-foreground">
                                        {t(
                                            'If the result is unclear, retry this same transfer. Do not start a new request.',
                                        )}
                                    </p>
                                )}
                            </>
                        )}
                        {Object.entries(form.errors)
                            .filter(
                                ([key]) =>
                                    key !== 'current_password' &&
                                    (reviewing ||
                                        !['amount', 'recipient_account_id'].includes(key)),
                            )
                            .map(([key, message]) => (
                                <p role="alert" className="text-sm text-danger" key={key}>
                                    {errorMessage(message)}
                                </p>
                            ))}
                    </form>
                )}
            </div>
        </UserLayout>
    );
}
