import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { MoneyDisplay } from './MoneyDisplay';
import { t } from '@/i18n/admin';
import type { PlatformUser } from './UserManagement';

type Account = { id: string; accountId: string; displayName: string | null };
type Records = {
    records: {
        recordId: string;
        category: string;
        accountId: string | null;
        asset: string | null;
        amount: string | null;
        related: boolean;
        operation?: string;
    }[];
    page: number;
    lastPage: number;
};
type Preview = {
    id: string;
    status: string;
    summary: {
        total: number;
        deleteCount?: number;
        retainedCount?: number;
        categoryCounts: { category: string; count: number; related: number }[];
        affectedAccounts: { id: string; accountId: string; deleted: boolean }[];
        blockers: { code: string; accountId?: string; asset?: string }[];
        balances: {
            id: string;
            accountId: string | null;
            asset: string;
            accountType: string;
            before: string;
            after: string;
            removed: boolean;
        }[];
    };
};
const modules = {
    account: 'Delete entire account',
    orders: 'Orders',
    commissions: 'Commissions',
    cards: 'Cards',
};
async function request<T>(url: string, data?: object): Promise<T> {
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
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrf,
        },
        body: data ? JSON.stringify(data) : undefined,
    });
    const result: unknown = await response.json().catch(() => null);
    const problem = result as {
        error?: { message?: string };
        errors?: Record<string, string[]>;
    } | null;
    if (!response.ok || response.redirected || result === null)
        throw new Error(
            problem?.error?.message ??
                Object.values(problem?.errors ?? {}).flat()[0] ??
                'Unable to load. Please try again.',
        );
    return result as T;
}

function run(action: () => Promise<void>): () => void {
    return () => void action();
}

export function DataCleanupDialog({
    companies,
    initialUser,
    company: initialCompany,
    onClose,
}: {
    companies: { id: string; name: string }[];
    initialUser?: PlatformUser;
    company?: string;
    onClose: () => void;
}) {
    const companyOptions =
        initialUser && !companies.some((c) => c.id === initialUser.companyId)
            ? [...companies, { id: initialUser.companyId, name: initialUser.companyName }]
            : companies;
    const [company, setCompany] = useState(
        initialUser?.companyId ?? initialCompany ?? companies[0]?.id ?? '',
    );
    const [search, setSearch] = useState('');
    const [accounts, setAccounts] = useState<Account[]>([]);
    const [selected, setSelected] = useState<Account[]>(
        initialUser
            ? [
                  {
                      id: initialUser.id,
                      accountId: initialUser.accountId,
                      displayName: initialUser.displayName,
                  },
              ]
            : [],
    );
    const [choices, setChoices] = useState<string[]>([]);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [records, setRecords] = useState<Records | null>(null);
    const [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [requestId, setRequestId] = useState(() => crypto.randomUUID());
    const url = `/platform/tenants/${company}/data-cleanup`;
    useEffect(() => {
        let active = true;
        const timer = setTimeout(() => {
            if (company)
                void request<{ accounts: Account[] }>(
                    `${url}/accounts?search=${encodeURIComponent(search)}`,
                )
                    .then((result) => {
                        if (active) setAccounts(result.accounts);
                    })
                    .catch((failure: Error) => {
                        if (active) setError(failure.message);
                    });
        }, 200);
        return () => {
            active = false;
            clearTimeout(timer);
        };
    }, [company, search, url]);
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
        >
            <DialogContent
                closeDisabled={busy}
                closeLabel={t('Close')}
                className="flex max-h-[90dvh] w-full max-w-4xl flex-col"
            >
                <DialogHeader>
                    <DialogTitle>{t('Cleanup data')}</DialogTitle>
                </DialogHeader>
                <div className="min-h-0 space-y-4 overflow-y-auto pr-2">
                    <p className="rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                        {t(
                            'Permanently delete selected account data and recompute affected balances. Card cleanup only removes local records; it does not cancel provider cards or refund funds.',
                        )}
                    </p>
                    {error && (
                        <p role="alert" className="text-sm text-red-700">
                            {t(error)}
                        </p>
                    )}
                    {!preview ? (
                        <>
                            <label className="block space-y-1 text-sm">
                                <span>{t('Company')}</span>
                                <select
                                    className="h-9 w-full rounded-md border px-3"
                                    disabled={busy}
                                    value={company}
                                    onChange={(e) => {
                                        setCompany(e.target.value);
                                        setSelected([]);
                                        setAccounts([]);
                                    }}
                                >
                                    {companyOptions.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <Input
                                aria-label={t('Search accounts')}
                                placeholder={t('Account ID or email')}
                                value={search}
                                disabled={busy}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <div className="max-h-48 overflow-y-auto rounded-md border p-3">
                                {accounts.map((account) => (
                                    <label
                                        key={account.id}
                                        className="flex items-center gap-2 py-1.5 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            disabled={busy}
                                            checked={selected.some((u) => u.id === account.id)}
                                            onChange={(e) =>
                                                setSelected(
                                                    e.target.checked
                                                        ? [...selected, account]
                                                        : selected.filter(
                                                              (u) => u.id !== account.id,
                                                          ),
                                                )
                                            }
                                        />
                                        {account.accountId} · {account.displayName || '—'}
                                    </label>
                                ))}
                            </div>
                            <p className="text-sm">
                                {t('Selected accounts')}:{' '}
                                {selected.map((u) => u.accountId).join('、') || '—'}
                            </p>
                            <div className="flex flex-wrap gap-4">
                                {Object.entries(modules).map(([key, label]) => (
                                    <label key={key} className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            disabled={
                                                busy ||
                                                (key !== 'account' && choices.includes('account'))
                                            }
                                            checked={
                                                choices.includes(key) ||
                                                (key !== 'account' && choices.includes('account'))
                                            }
                                            onChange={(e) =>
                                                setChoices(
                                                    e.target.checked
                                                        ? [...choices, key]
                                                        : choices.filter((v) => v !== key),
                                                )
                                            }
                                        />
                                        {t(label)}
                                    </label>
                                ))}
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'Account deletion includes all related data and is blocked when subordinates exist. Commission cleanup includes both received and source-generated commissions.',
                                )}
                            </p>
                        </>
                    ) : (
                        <>
                            <p className="text-sm font-medium">
                                {t('Selected accounts')}:{' '}
                                {selected.map((u) => u.accountId).join('、')}
                            </p>
                            <p className="text-sm">
                                {t('Records to remove')}:{' '}
                                {preview.summary.deleteCount ?? preview.summary.total}
                            </p>
                            {!!preview.summary.retainedCount && (
                                <p className="text-sm">
                                    {t('Identifiers to retain')}: {preview.summary.retainedCount}
                                </p>
                            )}
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b">
                                        <th className="py-2 text-left">{t('Data category')}</th>
                                        <th>{t('Count')}</th>
                                        <th>{t('Related records')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {preview.summary.categoryCounts.map((row) => (
                                        <tr key={row.category} className="border-b">
                                            <td className="py-2">{t(row.category)}</td>
                                            <td className="text-center">{row.count}</td>
                                            <td className="text-center">{row.related}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <p className="text-sm">
                                {t('Affected accounts')}:{' '}
                                {preview.summary.affectedAccounts
                                    .map(
                                        (u) =>
                                            `${u.accountId}${u.deleted ? ` (${t('Delete entire account')})` : ''}`,
                                    )
                                    .join('、')}
                            </p>
                            <Button
                                variant="secondary"
                                disabled={busy}
                                onClick={run(async () => {
                                    try {
                                        setRecords(
                                            await request<Records>(`${url}/${preview.id}/records`),
                                        );
                                    } catch (failure) {
                                        setError((failure as Error).message);
                                    }
                                })}
                            >
                                {t('View cleanup records')}
                            </Button>
                            {records && (
                                <div className="space-y-2">
                                    <div className="max-h-60 overflow-auto rounded-md border">
                                        <table className="w-full text-xs">
                                            <thead>
                                                <tr>
                                                    <th>{t('Data category')}</th>
                                                    <th>{t('Record ID')}</th>
                                                    <th>{t('Account')}</th>
                                                    <th>{t('Amount')}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {records.records.map((r, i) => (
                                                    <tr key={i} className="border-t">
                                                        <td className="p-2">
                                                            {t(r.category)}
                                                            {r.related
                                                                ? ` (${t('Related records')})`
                                                                : ''}
                                                        </td>
                                                        <td className="p-2 break-all">
                                                            {r.recordId}
                                                            {r.operation === 'RETIRE' &&
                                                                ` (${t('Retain identifier')})`}
                                                        </td>
                                                        <td className="p-2">
                                                            {r.accountId || '—'}
                                                        </td>
                                                        <td className="p-2">
                                                            {r.amount && r.asset ? (
                                                                <MoneyDisplay
                                                                    amount={r.amount}
                                                                    asset={r.asset}
                                                                />
                                                            ) : (
                                                                '—'
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    <div className="flex items-center justify-end gap-2">
                                        {[records.page - 1, records.page + 1].map((page, i) => (
                                            <Button
                                                key={i}
                                                size="sm"
                                                variant="secondary"
                                                disabled={page < 1 || page > records.lastPage}
                                                onClick={run(async () => {
                                                    try {
                                                        setRecords(
                                                            await request<Records>(
                                                                `${url}/${preview.id}/records?page=${page}`,
                                                            ),
                                                        );
                                                    } catch (failure) {
                                                        setError((failure as Error).message);
                                                    }
                                                })}
                                            >
                                                {t(i === 0 ? 'Previous' : 'Next')}
                                            </Button>
                                        ))}
                                        <span className="text-xs">
                                            {records.page} / {records.lastPage}
                                        </span>
                                    </div>
                                </div>
                            )}
                            {!!preview.summary.balances.length && (
                                <div>
                                    <div className="space-y-2 sm:hidden">
                                        {preview.summary.balances.map((b) => (
                                            <div
                                                key={b.id}
                                                className="space-y-2 rounded-md border p-3 text-sm"
                                            >
                                                <div className="flex flex-wrap justify-between gap-1">
                                                    <span>{b.accountId || t('Company')}</span>
                                                    <span>{t(b.accountType)}</span>
                                                </div>
                                                <div className="flex justify-between gap-2">
                                                    <span>{t('Before')}</span>
                                                    <MoneyDisplay
                                                        amount={b.before}
                                                        asset={b.asset}
                                                    />
                                                </div>
                                                <div className="flex justify-between gap-2">
                                                    <span>{t('After')}</span>
                                                    {b.removed ? (
                                                        t('Removed')
                                                    ) : (
                                                        <MoneyDisplay
                                                            amount={b.after}
                                                            asset={b.asset}
                                                        />
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                    <div className="hidden overflow-x-auto sm:block">
                                        <table className="w-full min-w-[640px] text-sm">
                                            <thead>
                                                <tr className="border-b">
                                                    <th className="text-left">{t('Account')}</th>
                                                    <th>{t('Type')}</th>
                                                    <th>{t('Before')}</th>
                                                    <th>{t('After')}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {preview.summary.balances.map((b) => (
                                                    <tr key={b.id} className="border-b">
                                                        <td className="py-2">
                                                            {b.accountId || t('Company')}
                                                        </td>
                                                        <td>{t(b.accountType)}</td>
                                                        <td className="text-center">
                                                            <MoneyDisplay
                                                                amount={b.before}
                                                                asset={b.asset}
                                                            />
                                                        </td>
                                                        <td className="text-center">
                                                            {b.removed ? (
                                                                t('Removed')
                                                            ) : (
                                                                <MoneyDisplay
                                                                    amount={b.after}
                                                                    asset={b.asset}
                                                                />
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}
                            {preview.summary.blockers.map((b, i) => (
                                <p key={i} role="alert" className="text-sm text-red-700">
                                    {t(b.code)} {b.accountId} {b.asset}
                                </p>
                            ))}
                            {preview.status === 'COMPLETED' ? (
                                <p role="status" className="text-sm text-emerald-700">
                                    {t('Cleanup completed.')}
                                </p>
                            ) : (
                                <label className="flex items-start gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        className="mt-1"
                                        checked={confirmed}
                                        disabled={busy}
                                        onChange={(e) => setConfirmed(e.target.checked)}
                                    />
                                    {t(
                                        'I confirm permanent deletion of this entire preview, including related records and balance changes.',
                                    )}
                                </label>
                            )}
                        </>
                    )}
                </div>
                <div className="flex justify-end gap-2 border-t pt-3">
                    <Button variant="secondary" disabled={busy} onClick={onClose}>
                        {t('Close')}
                    </Button>
                    {preview && preview.status !== 'COMPLETED' && (
                        <Button
                            variant="secondary"
                            disabled={busy}
                            onClick={() => {
                                setPreview(null);
                                setRecords(null);
                                setConfirmed(false);
                                setError('');
                                setRequestId(crypto.randomUUID());
                            }}
                        >
                            {t('Return to edit')}
                        </Button>
                    )}
                    {!preview ? (
                        <Button
                            disabled={busy || !selected.length || !choices.length}
                            onClick={run(async () => {
                                setBusy(true);
                                setError('');
                                try {
                                    setPreview(
                                        await request<Preview>(`${url}/preview`, {
                                            user_ids: selected.map((u) => u.id),
                                            modules: choices,
                                        }),
                                    );
                                } catch (failure) {
                                    setError((failure as Error).message);
                                } finally {
                                    setBusy(false);
                                }
                            })}
                        >
                            {t(busy ? 'Loading…' : 'Preview cleanup')}
                        </Button>
                    ) : (
                        preview.status !== 'COMPLETED' && (
                            <Button
                                variant="destructive"
                                disabled={
                                    busy ||
                                    !confirmed ||
                                    !!preview.summary.blockers.length ||
                                    preview.summary.total === 0
                                }
                                onClick={run(async () => {
                                    setBusy(true);
                                    setError('');
                                    try {
                                        setPreview(
                                            await request<Preview>(`${url}/${preview.id}/execute`, {
                                                request_id: requestId,
                                                confirmed: true,
                                            }),
                                        );
                                        router.reload();
                                    } catch (failure) {
                                        setError((failure as Error).message);
                                        try {
                                            const result = await request<Preview>(
                                                `${url}/${preview.id}`,
                                            );
                                            if (result.status === 'COMPLETED') {
                                                setPreview(result);
                                                router.reload();
                                            }
                                        } catch {
                                            /* Preserve the original error. */
                                        }
                                    } finally {
                                        setBusy(false);
                                    }
                                })}
                            >
                                {t(busy ? 'Cleaning data…' : 'Permanently delete')}
                            </Button>
                        )
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
