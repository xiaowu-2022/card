import {
    promotionLevel,
    membershipAction,
    promotionMoney,
    promotionUnits,
} from '@/lib/paid-promotion';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ShieldAlert } from 'lucide-react';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Button } from '@/components/ui/button';
import { IdentityVerificationDialog } from '@/components/user/IdentityVerificationDialog';
import { FinancialConfirmation } from '@/components/user/FinancialConfirmation';
import { type PaidPromotionData } from '@/components/user/PaidPromotionSummary';
import { exactAmount } from '@/lib/exact-amount';
import { t, dateTime, errorMessage, useClientTranslation } from '@/i18n';

type Quote = {
    id: string;
    rank: number;
    amount: string;
    previousTariff: string;
    depositApplied: string;
    settlementTotal: string;
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
    const [clock, setClock] = useState(() => Date.now());
    const [verificationPromptOpen, setVerificationPromptOpen] = useState(!p.paymentAccess.verified);
    useEffect(() => {
        const timer = window.setInterval(() => setClock(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);
    const expired = !!q && q.status === 'QUOTED' && new Date(q.expiresAt).getTime() <= clock;
    const insufficient = !!q && promotionUnits(p.availableBalance) < promotionUnits(q.amount);
    const available = p.levels.filter((l) => l.selectable);
    const quotedLevel = p.levels.find((level) => level.rank === q?.rank);
    const quoteUnavailable = q?.status === 'QUOTED' && !quotedLevel?.selectable;
    const ready = p.paymentAccess.verified && p.paymentAccess.walletActive;
    const title = p.activation.qualified ? membershipAction(p) : 'Activate your account';
    return (
        <UserLayout>
            <Head title={t(title)} />
            <UserPageHeader title={t(title)} backHref="/promotion" />
            <IdentityVerificationDialog
                open={!p.paymentAccess.verified && verificationPromptOpen}
                onDismiss={() => setVerificationPromptOpen(false)}
            />
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
                        {t(
                            'Ordinary members pay a deposit with no annual fee. Active agents are exempt from the deposit.',
                        )}
                    </p>
                </section>
                {!p.paymentAccess.verified ? (
                    <section className="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950">
                        <ShieldAlert
                            className="mt-0.5 size-5 shrink-0 text-amber-700"
                            aria-hidden="true"
                        />
                        <div className="min-w-0 space-y-2">
                            <h2 className="text-sm font-semibold">
                                {t('Complete identity verification')}
                            </h2>
                            <p className="text-xs leading-5 text-amber-900">
                                {t('Verify your identity before using financial services.')}
                            </p>
                            <Button asChild size="sm" className="bg-amber-700 text-white">
                                <Link href="/kyc">{t('Verify now')}</Link>
                            </Button>
                        </div>
                    </section>
                ) : (
                    !ready && (
                        <div className="rounded-xl border p-4 text-sm">
                            {p.paymentAccess.canCreateWallet ? (
                                <Button onClick={() => router.post('/wallet/activate')}>
                                    {t('Activate wallet')}
                                </Button>
                            ) : (
                                <p>{t('Wallet access is restricted')}</p>
                            )}
                        </div>
                    )
                )}
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
                                    : 'Choose a member deposit or an annual agent level.',
                            )}
                        </p>
                        {p.cycle && (
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'Current cycle activation count: {{count}}. Choose a level with a strictly higher target; the highest enabled level is exempt.',
                                    { count: p.upgradeEligibility.weightedCount },
                                )}
                            </p>
                        )}
                        {p.activation.refundPending && (
                            <p className="text-sm text-muted-foreground">
                                {t('Cancel the pending security deposit refund before continuing.')}
                            </p>
                        )}
                        {p.pending && (
                            <p role="status" className="rounded-xl bg-muted p-4 text-sm">
                                {t('Annual fee return is processing. Try upgrading shortly.')}
                            </p>
                        )}
                        {!available.length && (
                            <p role="status" className="py-4 text-sm text-muted-foreground">
                                {t(
                                    p.rank > 0 && p.rank >= p.upgradeEligibility.highestEnabledRank
                                        ? 'You have the highest promotion level.'
                                        : 'No promotion levels are currently available.',
                                )}
                            </p>
                        )}
                        <form
                            className="space-y-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (form.data.level_id === 'ordinary') {
                                    router.visit('/security-deposit');
                                    return;
                                }
                                form.post('/promotion/quotes', {
                                    onSuccess: () =>
                                        form.setData('request_id', crypto.randomUUID()),
                                });
                            }}
                        >
                            <fieldset
                                disabled={
                                    p.pending ||
                                    p.activation.refundPending ||
                                    !ready ||
                                    form.processing
                                }
                                className="space-y-3"
                            >
                                <legend className="sr-only">{t('Choose promotion level')}</legend>
                                {!p.activation.agent && p.activation.ordinaryAvailable && (
                                    <label
                                        className={`flex cursor-pointer gap-3 rounded-xl border p-4 ${form.data.level_id === 'ordinary' ? 'border-emerald-800 bg-emerald-50/50' : 'bg-surface'}`}
                                    >
                                        <input
                                            type="radio"
                                            name="level"
                                            value="ordinary"
                                            checked={form.data.level_id === 'ordinary'}
                                            onChange={() =>
                                                form.setData({
                                                    level_id: 'ordinary',
                                                    request_id: crypto.randomUUID(),
                                                })
                                            }
                                            className="mt-1 size-4 shrink-0 accent-emerald-800"
                                        />
                                        <span className="min-w-0 space-y-2">
                                            <span className="block text-sm font-semibold">
                                                {promotionLevel(0)}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {t('No annual fee')}
                                            </span>
                                            <span className="block text-lg font-semibold">
                                                {promotionMoney(p.activation.depositRequired)}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {t('Security deposit')}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {t(
                                                    'Your deposit can be converted into an agent annual fee when upgrading.',
                                                )}
                                            </span>
                                        </span>
                                    </label>
                                )}
                                {p.levels.map((l) => (
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
                                                disabled={!l.selectable}
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
                                                {!l.selectable && l.unavailableReason && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {t(l.unavailableReason)}
                                                    </span>
                                                )}
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
                                                    'Automatic return requires {{target}} weighted activated accounts. Each direct account counts as 1; each indirect account as 0.5.',
                                                    { target: l.target },
                                                )}
                                            </p>
                                            <p>
                                                {t(
                                                    'Each account counts once on its first member deposit or agent purchase. Annual fees are returned automatically when the target is reached.',
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
                            {(available.length > 0 ||
                                (!p.activation.agent && p.activation.ordinaryAvailable)) && (
                                <Button
                                    className="min-h-12 w-full rounded-full"
                                    type="submit"
                                    disabled={
                                        form.processing ||
                                        p.pending ||
                                        p.activation.refundPending ||
                                        !ready ||
                                        !form.data.level_id ||
                                        (form.data.level_id !== 'ordinary' &&
                                            !available.some(
                                                (level) => level.id === form.data.level_id,
                                            ))
                                    }
                                >
                                    {t(
                                        form.data.level_id === 'ordinary'
                                            ? 'Pay security deposit'
                                            : 'Next: review fees',
                                    )}
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
                                ['Annual fee settlement total', promotionMoney(q.settlementTotal)],
                                [
                                    'Deposit converted to annual fee',
                                    promotionMoney(q.depositApplied),
                                ],
                                ['Wallet payment', promotionMoney(q.amount)],
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
                                {promotionUnits(q.depositApplied) > 0n && (
                                    <p className="text-xs leading-5 text-muted-foreground">
                                        {t(
                                            'The converted deposit becomes annual fee and is no longer refundable as a deposit. Commission uses only the wallet payment; annual fee returns include the converted deposit.',
                                        )}
                                    </p>
                                )}
                                {expired && (
                                    <p role="alert" className="text-sm text-destructive">
                                        {t('The payment quote has expired. Review fees again.')}
                                    </p>
                                )}
                                {quoteUnavailable && (
                                    <p role="alert" className="text-sm text-destructive">
                                        {t(
                                            quotedLevel?.unavailableReason ??
                                                'Promotion terms changed. Request a new quote.',
                                        )}
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
                                        'Convert {{deposit}} USDT of deposit and pay {{amount}} USDT from your wallet for {{level}}. The {{total}} USDT total becomes annual fee; the converted deposit cannot be refunded separately.',
                                        {
                                            deposit: exactAmount(q.depositApplied),
                                            amount: exactAmount(q.amount),
                                            total: exactAmount(q.settlementTotal),
                                            level: promotionLevel(q.rank),
                                        },
                                    )}
                                    url={`/promotion/quotes/${q.id}/confirm`}
                                    payload={{}}
                                    disabled={
                                        quoteUnavailable ||
                                        expired ||
                                        insufficient ||
                                        p.pending ||
                                        p.activation.refundPending ||
                                        !ready
                                    }
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
                <Link href="/promotion" className="block py-3 text-center underline">
                    {t('Back to promotion')}
                </Link>
            </div>
        </UserLayout>
    );
}
