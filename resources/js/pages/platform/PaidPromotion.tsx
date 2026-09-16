import { Head, Link, useForm } from '@inertiajs/react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t, dateTime, useAdminTranslation, errorMessage } from '@/i18n/admin';
import { exactAmount } from '@/lib/exact-amount';
import type { PaidLevel, PaidClaim } from '@/components/user/PaidPromotionSummary';
const name = (rank: number) => t('Mastercard level {{rank}}', { rank });
function Tariff({ level: l, base }: { level: PaidLevel; base: string }) {
    const f = useForm({
        fee: l.fee,
        percent: l.percent,
        reward: l.reward,
        target: l.target,
        revision: l.revision,
        enabled: l.enabled,
    });
    return (
        <form
            className="space-y-3 rounded-xl border bg-surface p-4"
            onSubmit={(e) => {
                e.preventDefault();
                f.post(`${base}/levels/${l.id}`, {
                    preserveScroll: true,
                    onSuccess: () => {
                        f.setData('revision', l.revision + 1);
                    },
                });
            }}
        >
            <h3 className="font-semibold">{name(l.rank)}</h3>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {(['fee', 'percent', 'reward', 'target'] as const).map((key) => (
                    <label className="text-sm" key={key}>
                        {t(
                            {
                                fee: 'Annual fee (USDT)',
                                percent: 'Annual reward (%)',
                                reward: 'Activation reward (USDT)',
                                target: 'Fee rebate target',
                            }[key],
                        )}
                        <Input
                            className="mt-1"
                            inputMode="decimal"
                            value={f.data[key]}
                            onChange={(e) =>
                                f.setData(
                                    key,
                                    key === 'fee' || key === 'reward'
                                        ? e.target.value
                                        : Number(e.target.value),
                                )
                            }
                        />
                    </label>
                ))}
            </div>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={f.data.enabled}
                    onChange={(e) => f.setData('enabled', e.target.checked)}
                />
                {t('Enabled')}
            </label>

            {Object.values(f.errors).map((v, i) => (
                <p role="alert" key={i} className="text-sm text-red-700">
                    {errorMessage(v)}
                </p>
            ))}
            <Button disabled={f.processing}>{t('Save')}</Button>
        </form>
    );
}
function Review({
    claim: c,
    base,
    canReview,
}: {
    claim: PaidClaim & { accountId: string; reviewer: string | null };
    base: string;
    canReview: boolean;
}) {
    const f = useForm({ decision: 'approve', reason: '', confirmed: false });
    const status = (
        {
            PENDING: 'Under review',
            APPROVED: 'Fee returned',
            REJECTED: 'Request rejected',
            WITHDRAWN: 'Request withdrawn',
        } as Record<string, string>
    )[c.status];
    return (
        <article className="rounded-xl border bg-surface p-4 text-sm">
            <div className="flex flex-wrap justify-between gap-3">
                <h3 className="font-semibold">
                    {c.accountId} · {name(c.rank)}
                </h3>
                <span>
                    {exactAmount(c.amount)} {'USDT'} · {t(status ?? 'Under review')}
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
            {c.reviewedAt && (
                <p>
                    {c.reviewer ?? '—'} · {dateTime(c.reviewedAt)}
                </p>
            )}
            {c.reason && <p className="mt-2">{c.reason}</p>}
            {c.status === 'PENDING' && canReview && (
                <form
                    className="mt-4 space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        f.post(`${base}/rebates/${c.id}`, {
                            preserveScroll: true,
                            onSuccess: () => f.reset('confirmed'),
                        });
                    }}
                >
                    <label className="block">
                        {t('Review decision')}
                        <select
                            className="ml-3 rounded border p-2"
                            value={f.data.decision}
                            onChange={(e) => f.setData('decision', e.target.value)}
                        >
                            <option value="approve">{t('Approve rebate')}</option>
                            <option value="reject">{t('Reject request')}</option>
                        </select>
                    </label>
                    <label className="block">
                        {t('Review reason')}
                        <Input
                            maxLength={300}
                            value={f.data.reason}
                            onChange={(e) => f.setData('reason', e.target.value)}
                        />
                    </label>

                    <label className="flex items-start gap-2">
                        <input
                            type="checkbox"
                            checked={f.data.confirmed}
                            onChange={(e) => f.setData('confirmed', e.target.checked)}
                        />
                        {t(
                            'I verified the request. Approval returns the stated fee to the user USDT balance without reversing commissions.',
                        )}
                    </label>
                    {Object.values(f.errors).map((v, i) => (
                        <p role="alert" key={i} className="text-red-700">
                            {errorMessage(v)}
                        </p>
                    ))}
                    <Button disabled={f.processing || !f.data.confirmed}>
                        {t('Submit review')}
                    </Button>
                </form>
            )}
        </article>
    );
}
export default function PaidPromotion({
    paid: p,
}: {
    paid: {
        canReview: boolean;
        companyName: string;
        tenantId: string;
        levels: PaidLevel[];
        claims: (PaidClaim & { accountId: string; reviewer: string | null })[];
        page: number;
        hasMore: boolean;
    };
}) {
    useAdminTranslation();
    const base = `/platform/tenants/${p.tenantId}/configuration/paid-promotion`;
    return (
        <PlatformLayout>
            <Head title={t('Paid promotion settings')} />
            <div className="mx-auto max-w-5xl space-y-5">
                <h1 className="text-2xl font-semibold">{t('Paid promotion settings')}</h1>
                <p className="text-sm font-medium">{p.companyName}</p>
                <p className="text-sm text-muted-foreground">
                    {t(
                        'Rules apply to new payments only. Qualification requires payment; manual level assignment is unavailable.',
                    )}
                </p>
                <details className="rounded-xl border bg-surface p-4">
                    <summary className="cursor-pointer font-semibold">
                        {t('Promotion tariffs')}
                    </summary>
                    <div className="mt-4 space-y-4">
                        {p.levels.map((l) => (
                            <Tariff key={`${l.id}-${l.revision}`} level={l} base={base} />
                        ))}
                    </div>
                </details>
                <h2 className="font-semibold">{t('Annual fee rebate review')}</h2>
                {p.claims.map((c) => (
                    <Review key={c.id} claim={c} base={base} canReview={p.canReview} />
                ))}
                {!p.claims.length && <p>{t('No activity yet')}</p>}
                <div className="flex gap-4">
                    {p.page > 1 && <Link href={`${base}?page=${p.page - 1}`}>{t('Previous')}</Link>}
                    {p.hasMore && <Link href={`${base}?page=${p.page + 1}`}>{t('Next')}</Link>}
                </div>
            </div>
        </PlatformLayout>
    );
}
