import {
    promotionLevel,
    rebateStatus,
    membershipAction,
    promotionMoney,
    promotionUnits,
} from '@/lib/paid-promotion';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
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
    const [clock, setClock] = useState(() => Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setClock(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);
    const expired = !!q && q.status === 'QUOTED' && new Date(q.expiresAt).getTime() <= clock;
    const insufficient = !!q && promotionUnits(p.availableBalance) < promotionUnits(q.amount);
    const available = p.levels.filter(
        (l) =>
            l.enabled &&
            l.rank > p.rank &&
            (!p.cycle || promotionUnits(l.fee) > promotionUnits(p.cycle.tariff)),
    );
    const title = membershipAction(p);
    const ready =
        !!p.progress &&
        !!p.cycle &&
        new Date(p.cycle.endsAt).getTime() > clock &&
        2 * p.progress.direct + p.progress.indirect >= 2 * p.progress.target &&
        !/^0(?:\.0+)?$/.test(p.progress.remaining) &&
        !p.pending;
    return (
        <UserLayout>
            <Head title={t(title)} />
            <UserPageHeader title={t(title)} backHref="/promotion" />
            <div className="mx-auto max-w-2xl space-y-5 pb-5">
                <section className="space-y-2 rounded-2xl bg-[#f2f6ef] p-5">
                    <p className="text-xs text-muted-foreground">{t('My promotion level')}</p>
                    <h2 className="text-xl font-semibold">{promotionLevel(p.rank)}</h2>
                    <p className="text-sm">
                        {p.cycle
                            ? t('Valid until {{time}}', { time: dateTime(p.cycle.endsAt) })
                            : t('Ordinary members earn 20 USDT for direct activation only.')}
                    </p>
                    {p.membershipStatus === 'EXPIRED' && p.previousCycle && (
                        <p className="text-sm">
                            {t(
                                'Your previous level expired on {{time}}. Ordinary member rewards now apply.',
                                { time: dateTime(p.previousCycle.endsAt) },
                            )}
                        </p>
                    )}
                    <p className="text-xs text-muted-foreground">
                        {t('Annual fee reward rate: {{rate}}%', { rate: p.percent })}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {t('Direct activation reward: {{amount}} USDT per event', {
                            amount: p.reward,
                        })}
                    </p>
                    <p className="text-xs leading-5 text-muted-foreground">
                        {t('Annual fees and security deposits are separate. No automatic renewal.')}
                    </p>
                </section>
                <ol aria-label={t('Membership steps')} className="grid grid-cols-3 gap-2 text-xs">
                    {[
                        'Choose promotion level',
                        'Review promotion payment',
                        'Confirm promotion payment',
                    ].map((step, index) => (
                        <li
                            key={step}
                            className="min-w-0 rounded-lg bg-muted p-3"
                            aria-current={
                                (q?.status === 'COMPLETED' ? 2 : q ? 1 : 0) === index
                                    ? 'step'
                                    : undefined
                            }
                        >
                            <span className="mb-1 block font-semibold">{index + 1}</span>
                            {t(step)}
                        </li>
                    ))}
                </ol>
                {!q && (
                    <section className="space-y-4">
                        <h2 className="font-semibold">{t('Choose promotion level')}</h2>
                        <p className="text-xs leading-5 text-muted-foreground">
                            {t(
                                p.cycle
                                    ? 'Upgrades charge the difference from your purchased tariff and retain the current expiry date.'
                                    : 'Choose a level and pay to activate one year of membership. No automatic renewal.',
                            )}
                        </p>
                        {p.pending && (
                            <p role="status" className="rounded-xl bg-muted p-4 text-sm">
                                {t('Withdraw the pending fee rebate request before upgrading.')}
                            </p>
                        )}
                        {!available.length && (
                            <p role="status" className="py-4 text-sm text-muted-foreground">
                                {t(
                                    p.rank >= 8
                                        ? 'You have the highest promotion level.'
                                        : 'No promotion levels are currently available.',
                                )}
                            </p>
                        )}
                        <form
                            className="space-y-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post('/promotion/quotes', {
                                    onSuccess: () =>
                                        form.setData('request_id', crypto.randomUUID()),
                                });
                            }}
                        >
                            <fieldset disabled={p.pending || form.processing} className="space-y-3">
                                <legend className="sr-only">{t('Choose promotion level')}</legend>
                                {available.map((l) => (
                                    <div
                                        key={l.id}
                                        className={`rounded-xl border p-4 ${form.data.level_id === l.id ? 'border-emerald-800 bg-emerald-50/50' : 'bg-surface'}`}
                                    >
                                        <label className="flex cursor-pointer items-start gap-3">
                                            <input
                                                type="radio"
                                                className="mt-1 size-4 shrink-0 accent-emerald-800"
                                                name="level"
                                                value={l.id}
                                                checked={form.data.level_id === l.id}
                                                required
                                                onChange={() =>
                                                    form.setData({
                                                        level_id: l.id,
                                                        request_id: crypto.randomUUID(),
                                                    })
                                                }
                                            />
                                            <span className="min-w-0 flex-1 space-y-2">
                                                <span className="block text-sm font-semibold">
                                                    {promotionLevel(l.rank)}
                                                </span>
                                                <span className="block break-words text-lg font-semibold">
                                                    {promotionMoney(l.fee)}{' '}
                                                    <span className="text-xs font-normal">
                                                        {t('per year')}
                                                    </span>
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {t('Annual fee reward rate: {{rate}}%', {
                                                        rate: l.percent,
                                                    })}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {t(
                                                        'Direct activation reward: {{amount}} USDT per event',
                                                        { amount: l.reward },
                                                    )}
                                                </span>
                                            </span>
                                        </label>
                                        <details className="mt-3 border-t pt-2 text-xs leading-5 text-muted-foreground">
                                            <summary className="cursor-pointer py-1">
                                                {t('Annual fee rebate conditions')}
                                            </summary>
                                            <p>
                                                {t(
                                                    'Rebate requires {{target}} weighted funding events. Direct counts as one; indirect counts as half.',
                                                    { target: l.target },
                                                )}
                                            </p>
                                            <p>
                                                {t(
                                                    'Each successful deposit funding counts. Apply before expiry; the platform reviews your request.',
                                                )}
                                            </p>
                                        </details>
                                    </div>
                                ))}
                            </fieldset>
                            {Object.values(form.errors).map((error, i) => (
                                <p role="alert" className="text-sm text-red-700" key={i}>
                                    {errorMessage(error)}
                                </p>
                            ))}
                            {!!available.length && (
                                <Button
                                    className="min-h-12 w-full rounded-full"
                                    type="submit"
                                    disabled={form.processing || p.pending || !form.data.level_id}
                                >
                                    {t('Next: review fees')}
                                </Button>
                            )}
                        </form>
                    </section>
                )}
                {q && (
                    <section
                        className="space-y-4 rounded-2xl border bg-surface p-5"
                        data-payment-review
                    >
                        <h2 className="font-semibold">
                            {t(
                                q.status === 'COMPLETED'
                                    ? 'Promotion payment completed.'
                                    : 'Review promotion payment',
                            )}
                        </h2>
                        <dl className="space-y-4 text-sm">
                            {[
                                ['Target level', promotionLevel(q.rank)],
                                ['Annual fee', promotionMoney(q.tariff)],
                                ...(q.previousTariff !== '0' &&
                                !/^0(?:\.0+)?$/.test(q.previousTariff)
                                    ? [['Purchased tariff', promotionMoney(q.previousTariff)]]
                                    : []),
                                [
                                    q.cycleId && !/^0(?:\.0+)?$/.test(q.previousTariff)
                                        ? 'Upgrade payment'
                                        : 'Amount due',
                                    promotionMoney(q.amount),
                                ],
                                ['Available USDT balance', promotionMoney(p.availableBalance)],
                            ].map(([label = '', value = '0']) => (
                                <div key={label} className="flex flex-wrap justify-between gap-2">
                                    <dt className="text-muted-foreground">{t(label)}</dt>
                                    <dd className="break-all font-medium">{value}</dd>
                                </div>
                            ))}
                        </dl>
                        <p className="text-xs leading-5 text-muted-foreground">
                            {t(
                                !/^0(?:\.0+)?$/.test(q.previousTariff)
                                    ? 'Upgrades charge the difference from your purchased tariff and retain the current expiry date.'
                                    : 'Choose a level and pay to activate one year of membership. No automatic renewal.',
                            )}
                        </p>
                        {p.cycle && (
                            <p className="text-xs">
                                {t('Valid until {{time}}', { time: dateTime(p.cycle.endsAt) })}
                            </p>
                        )}
                        {q.status === 'QUOTED' && (
                            <>
                                <p className="text-xs">
                                    {t('Quote valid until {{time}}', {
                                        time: dateTime(q.expiresAt),
                                    })}
                                </p>
                                {expired && (
                                    <p role="alert" className="text-sm text-destructive">
                                        {t('The payment quote has expired. Review fees again.')}
                                    </p>
                                )}
                                {insufficient && (
                                    <p role="alert" className="text-sm text-destructive">
                                        {t('Your available balance is not enough.')}
                                    </p>
                                )}
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
                                    disabled={expired || insufficient || p.pending}
                                />
                                <Link
                                    href="/promotion/membership"
                                    className="block py-2 text-sm underline"
                                >
                                    {t('Choose again and review fees')}
                                </Link>
                            </>
                        )}
                        {q.status === 'COMPLETED' && (
                            <Link href="/promotion" className="block py-2 text-sm underline">
                                {t('Back to promotion')}
                            </Link>
                        )}
                    </section>
                )}
                {p.progress && (
                    <section id="annual-rebate" className="scroll-mt-4 rounded-2xl bg-surface p-5">
                        <h2 className="font-semibold">{t('This year’s annual fee rebate')}</h2>
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
                        <p className="mb-3 break-words text-sm font-medium">
                            {t('Eligible rebate amount')}:{' '}
                            {promotionMoney(ready ? p.progress.remaining : '0')}
                        </p>
                        {p.pending && <p className="mb-3 text-sm">{t('Under review')}</p>}
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
                <section id="rebate-history" className="scroll-mt-4">
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
