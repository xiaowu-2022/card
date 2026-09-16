import { promotionLevel, rebateStatus } from '@/lib/paid-promotion';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Button } from '@/components/ui/button';
import { FinancialConfirmation } from '@/components/user/FinancialConfirmation';
import { type PaidPromotionData } from '@/components/user/PaidPromotionSummary';
import { exactAmount } from '@/lib/exact-amount';
import { t, dateTime, errorMessage, useClientTranslation } from '@/i18n';

type Quote = {
    id: string;
    rank: number;
    amount: string;
    previousTariff: string;
    tariff: string;
    expiresAt: string;
    status: string;
    cycleId: string | null;
};
export default function PaidPromotion({
    paid: p,
    quote: q,
}: {
    paid: PaidPromotionData;
    quote: Quote | null;
}) {
    useClientTranslation();
    const form = useForm({ level_id: '', request_id: crypto.randomUUID() });
    const [requestId, setRequestId] = useState(() => crypto.randomUUID());
    const available = p.levels.filter((l) => l.enabled && l.rank > p.rank);
    const selected = p.levels.find((l) => l.id === form.data.level_id);
    const ready =
        !!p.progress &&
        2 * p.progress.direct + p.progress.indirect >= 2 * p.progress.target &&
        !/^0(?:\.0+)?$/.test(p.progress.remaining) &&
        !p.pending;
    return (
        <UserLayout>
            <Head title={t('Promotion membership')} />
            <UserPageHeader title={t('Promotion membership')} backHref="/promotion" />
            <div className="space-y-5">
                <section className="rounded-2xl bg-surface p-5">
                    <h2 className="text-xl font-semibold">{promotionLevel(p.rank)}</h2>
                    <p className="mt-2 text-sm">
                        {p.cycle
                            ? t('Valid until {{time}}', { time: dateTime(p.cycle.endsAt) })
                            : t('Ordinary members earn 20 USDT for direct activation only.')}
                    </p>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {t('Annual fees and security deposits are separate. No automatic renewal.')}
                    </p>
                </section>
                {q && (
                    <section className="rounded-2xl border bg-surface p-5">
                        <h2 className="font-semibold">
                            {q.status === 'COMPLETED'
                                ? t('Promotion payment completed.')
                                : t('Review promotion payment')}
                        </h2>
                        <p className="mt-3">
                            {promotionLevel(q.rank)} · {exactAmount(q.amount)} USDT
                        </p>
                        {q.status === 'QUOTED' && (
                            <>
                                <p className="my-3 text-sm">
                                    {t('Quote valid until {{time}}', {
                                        time: dateTime(q.expiresAt),
                                    })}
                                </p>
                                <FinancialConfirmation
                                    title={t('Confirm promotion payment')}
                                    warning={t(
                                        q.cycleId
                                            ? 'Pay {{amount}} USDT to upgrade to {{level}}. The original expiry date stays unchanged.'
                                            : 'Pay {{amount}} USDT for {{level}}, valid for one year. No automatic renewal.',
                                        {
                                            amount: exactAmount(q.amount),
                                            level: promotionLevel(q.rank),
                                        },
                                    )}
                                    url={`/promotion/quotes/${q.id}/confirm`}
                                    payload={{}}
                                />
                            </>
                        )}
                    </section>
                )}
                <section className="rounded-2xl bg-surface p-5">
                    <h2 className="font-semibold">
                        {t(p.cycle ? 'Upgrade promotion level' : 'Choose promotion level')}
                    </h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {t(
                            'Upgrades charge the difference from your purchased tariff and retain the current expiry date.',
                        )}
                    </p>
                    <form
                        className="mt-4 space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/promotion/quotes', {
                                onSuccess: () => form.setData('request_id', crypto.randomUUID()),
                            });
                        }}
                    >
                        <label className="block text-sm" htmlFor="paid-level">
                            {t('Level')}
                        </label>
                        <select
                            id="paid-level"
                            className="min-h-12 w-full rounded-xl border bg-surface px-3"
                            value={form.data.level_id}
                            onChange={(e) =>
                                form.setData({
                                    level_id: e.target.value,
                                    request_id: crypto.randomUUID(),
                                })
                            }
                            required
                            disabled={p.pending || !available.length}
                        >
                            <option value="">{t('Choose promotion level')}</option>
                            {available.map((l) => (
                                <option key={l.id} value={l.id}>
                                    {promotionLevel(l.rank)} · {exactAmount(l.fee)} USDT
                                </option>
                            ))}
                        </select>
                        {Object.values(form.errors).map((error, i) => (
                            <p role="alert" className="text-sm text-red-700" key={i}>
                                {errorMessage(error)}
                            </p>
                        ))}
                        {p.pending && (
                            <p className="text-sm">
                                {t('Withdraw the pending fee rebate request before upgrading.')}
                            </p>
                        )}
                        {selected && (
                            <div className="space-y-2 text-sm">
                                <p>
                                    {t('Annual fee reward rate: {{rate}}%', {
                                        rate: selected.percent,
                                    })}
                                </p>
                                <p>
                                    {t('Direct activation reward: {{amount}} USDT per event', {
                                        amount: selected.reward,
                                    })}
                                </p>
                                <p>
                                    {t('Fee rebate target')}: {selected.target}
                                </p>
                            </div>
                        )}
                        <Button
                            type="submit"
                            disabled={form.processing || p.pending || !form.data.level_id}
                        >
                            {t('Get promotion quote')}
                        </Button>
                    </form>
                </section>
                {p.progress && (
                    <section className="rounded-2xl bg-surface p-5">
                        <h2 className="font-semibold">{t('Annual fee rebate')}</h2>
                        <p className="mt-3 text-sm">
                            {t('Direct {{direct}} + indirect {{indirect}} / 2; target {{target}}', {
                                direct: p.progress.direct,
                                indirect: p.progress.indirect,
                                target: p.progress.target,
                            })}
                        </p>
                        <p className="mt-2 text-sm">
                            {t(
                                'Paid {{paid}}; returned {{returned}}; remaining {{remaining}} USDT',
                                {
                                    paid: exactAmount(p.progress.paid),
                                    returned: exactAmount(p.progress.returned),
                                    remaining: exactAmount(p.progress.remaining),
                                },
                            )}
                        </p>
                        <p className="my-3 text-sm text-muted-foreground">
                            {t(
                                'Each successful deposit funding counts. Apply before expiry; the platform reviews your request.',
                            )}
                        </p>
                        <FinancialConfirmation
                            title={t('Apply for annual fee rebate')}
                            warning={t(
                                'Apply to return {{amount}} USDT of annual fees. Approval does not cancel your level.',
                                { amount: exactAmount(p.progress.remaining) },
                            )}
                            url="/promotion/rebates"
                            payload={{ request_id: requestId }}
                            disabled={!ready}
                            onCompleted={() => setRequestId(crypto.randomUUID())}
                        />
                    </section>
                )}
                <section>
                    <h2 className="mb-3 font-semibold">{t('Fee rebate history')}</h2>
                    {p.claims.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{t('No activity yet')}</p>
                    ) : (
                        p.claims.map((c) => (
                            <div className="space-y-2 border-b py-4 text-sm" key={c.id}>
                                <div className="flex justify-between gap-3">
                                    <span>
                                        {promotionLevel(c.rank)} · {rebateStatus(c.status)}
                                    </span>
                                    <span>{exactAmount(c.amount)} USDT</span>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {dateTime(c.createdAt)}
                                </p>
                                {c.reason && <p>{c.reason}</p>}
                                {c.status === 'PENDING' && (
                                    <FinancialConfirmation
                                        title={t('Withdraw rebate request')}
                                        warning={t(
                                            'Withdraw this pending request. No funds will move.',
                                        )}
                                        url={`/promotion/rebates/${c.id}/withdraw`}
                                        payload={{}}
                                    />
                                )}
                            </div>
                        ))
                    )}
                </section>
                <nav className="flex justify-between gap-4">
                    {p.claimsPage > 1 && (
                        <Link href={`/promotion/membership?claims_page=${p.claimsPage - 1}`}>
                            {t('Previous')}
                        </Link>
                    )}
                    {p.hasMoreClaims && (
                        <Link href={`/promotion/membership?claims_page=${p.claimsPage + 1}`}>
                            {t('Next')}
                        </Link>
                    )}
                </nav>
                <Link href="/promotion" className="block py-3 text-center underline">
                    {t('Back to promotion')}
                </Link>
            </div>
        </UserLayout>
    );
}
