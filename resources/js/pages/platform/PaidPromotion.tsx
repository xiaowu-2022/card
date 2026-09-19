import { InvitationPosterSettings } from '@/components/admin/InvitationPosterSettings';
import { Head, Link, useForm } from '@inertiajs/react';
import { CompanyConfigurationLayout } from '@/components/admin/CompanyConfiguration';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t, dateTime, useAdminTranslation, errorMessage } from '@/i18n/admin';
import { exactAmount } from '@/lib/exact-amount';
import type { PaidLevel, PaidClaim } from '@/components/user/PaidPromotionSummary';
const name = (rank: number) => t('Mastercard level {{rank}}', { rank });
function Tariffs({ levels, base }: { levels: PaidLevel[]; base: string }) {
    const initial = levels.map((l) => ({
        id: l.id,
        fee: exactAmount(l.fee),
        percent: String(l.percent),
        reward: String(l.reward),
        target: String(l.target),
        revision: l.revision,
        enabled: l.enabled,
    }));
    const form = useForm({ levels: initial });
    const fields = {
        fee: 'Annual fee (USDT)',
        percent: 'Annual reward (%)',
        reward: 'Activation reward (USDT)',
        target: 'Fee rebate target',
    } as const;
    const dirty = form.data.levels.filter(
        (row, i) => JSON.stringify(row) !== JSON.stringify(initial[i]),
    );
    return (
        <form
            className="overflow-hidden rounded-xl border bg-surface"
            onSubmit={(event) => {
                event.preventDefault();
                if (!dirty.length) return;
                form.transform(() => ({ levels: dirty }));
                form.post(`${base}/levels`, { preserveScroll: true });
            }}
        >
            <div className="flex items-center justify-between gap-3 border-b px-4 py-3">
                <h2 className="font-semibold">{t('Promotion tariffs')}</h2>
                <Button disabled={form.processing || !dirty.length}>{t('Save all changes')}</Button>
            </div>
            {Object.values(form.errors).map((error, i) => (
                <p key={i} role="alert" className="px-4 py-2 text-sm text-destructive">
                    {errorMessage(error)}
                </p>
            ))}
            <div className="overflow-x-auto">
                <table className="w-full min-w-[720px] text-left text-sm">
                    <thead className="bg-muted/50 text-xs text-muted-foreground">
                        <tr>
                            <th className="px-4 py-3">{t('Level')}</th>
                            {Object.entries(fields).map(([key, label]) => (
                                <th key={key} className="px-2 py-3">
                                    {t(label)}
                                </th>
                            ))}
                            <th className="px-4 py-3">{t('Enabled')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {form.data.levels.map((row, i) => (
                            <tr key={row.id} className="border-t">
                                <th className="whitespace-nowrap px-4 py-2 font-medium">
                                    {name(levels[i]!.rank)}
                                </th>
                                {(Object.keys(fields) as (keyof typeof fields)[]).map((key) => (
                                    <td key={key} className="px-2 py-2">
                                        <Input
                                            className="h-9 min-w-24"
                                            aria-label={`${name(levels[i]!.rank)} · ${t(fields[key])}`}
                                            inputMode="decimal"
                                            disabled={form.processing}
                                            value={row[key]}
                                            onChange={(event) =>
                                                form.setData(
                                                    'levels',
                                                    form.data.levels.map((item, index) =>
                                                        index === i
                                                            ? { ...item, [key]: event.target.value }
                                                            : item,
                                                    ),
                                                )
                                            }
                                        />
                                    </td>
                                ))}
                                <td className="px-4 py-2">
                                    <input
                                        type="checkbox"
                                        aria-label={`${name(levels[i]!.rank)} · ${t('Enabled')}`}
                                        disabled={form.processing}
                                        checked={row.enabled}
                                        onChange={(event) =>
                                            form.setData(
                                                'levels',
                                                form.data.levels.map((item, index) =>
                                                    index === i
                                                        ? { ...item, enabled: event.target.checked }
                                                        : item,
                                                ),
                                            )
                                        }
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </form>
    );
}
function ReturnRecord({ claim: c }: { claim: PaidClaim & { accountId: string } }) {
    return (
        <article className="rounded-xl border bg-surface p-4 text-sm">
            <div className="flex flex-wrap justify-between gap-3">
                <h3 className="font-semibold">
                    {c.accountId} · {name(c.rank)}
                </h3>
                <span>
                    {exactAmount(c.amount)} {'USDT'} ·{' '}
                    {t(c.status === 'APPROVED' ? 'Fee returned' : 'Processing')}
                </span>
            </div>
            <p className="mt-2">
                {t('Direct {{direct}} + indirect {{indirect}} / 2; target {{target}}', {
                    direct: c.direct,
                    indirect: c.indirect,
                    target: c.target,
                })}
            </p>
            <p className="mt-2 text-muted-foreground">{dateTime(c.createdAt)}</p>
            <p>
                {t('Automatic annual fee return')} ·{' '}
                {c.processedAt ? dateTime(c.processedAt) : t('Processing')}
            </p>
        </article>
    );
}
export default function PaidPromotion({
    paid: p,
    posterBackground,
}: {
    posterBackground: string | null;
    paid: {
        companyName: string;
        tenantId: string;
        levels: PaidLevel[];
        claims: (PaidClaim & { accountId: string })[];
        page: number;
        hasMore: boolean;
    };
}) {
    useAdminTranslation();
    const base = `/platform/tenants/${p.tenantId}/configuration/paid-promotion`;
    return (
        <CompanyConfigurationLayout>
            <Head title={t('Paid promotion settings')} />
            <div className="mx-auto max-w-5xl space-y-5">
                <h1 className="text-2xl font-semibold">{t('Paid promotion settings')}</h1>
                <p className="text-sm font-medium">{p.companyName}</p>
                <p className="text-sm text-muted-foreground">
                    {t(
                        'Rules apply to new payments only. Qualification requires payment; manual level assignment is unavailable.',
                    )}
                </p>
                <InvitationPosterSettings tenant={p.tenantId} background={posterBackground} />
                <Tariffs
                    key={p.levels.map((level) => `${level.id}:${level.revision}`).join(',')}
                    levels={p.levels}
                    base={base}
                />
                <h2 className="font-semibold">{t('Automatic annual fee return')}</h2>
                {p.claims.map((c) => (
                    <ReturnRecord key={c.id} claim={c} />
                ))}
                {!p.claims.length && <p>{t('No activity yet')}</p>}
                <div className="flex gap-4">
                    {p.page > 1 && <Link href={`${base}?page=${p.page - 1}`}>{t('Previous')}</Link>}
                    {p.hasMore && <Link href={`${base}?page=${p.page + 1}`}>{t('Next')}</Link>}
                </div>
            </div>
        </CompanyConfigurationLayout>
    );
}
