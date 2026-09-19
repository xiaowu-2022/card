import { InvitationPoster } from '@/components/user/InvitationPoster';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Users, BookOpen, Copy, Share2, Check } from 'lucide-react';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Button } from '@/components/ui/button';
import { AnnualRebateProgress } from '@/components/user/AnnualRebateProgress';
import type { PaidPromotionData } from '@/components/user/PaidPromotionSummary';
import { promotionLevel, promotionUnits } from '@/lib/paid-promotion';
import { t, dateTime, errorMessage, useClientTranslation } from '@/i18n';
import type { SharedProps } from '@/types/global';
import '../../../css/promotion.css';

type Benefits = Pick<
    PaidPromotionData,
    | 'levels'
    | 'rank'
    | 'percent'
    | 'reward'
    | 'membershipStatus'
    | 'previousCycle'
    | 'cycle'
    | 'pending'
    | 'progress'
> & { hasClaims: boolean };
type Home = {
    posterBackground: string | null;
    paid: Benefits;
    invitationCode: string;
    canPurchase: boolean;
};

// Whole-unit presentation only. Non-integer configured fees are marked approximate;
// the payment review continues to show the full server quote precision.
function annualFeeLabel(amount: string): string {
    const units = promotionUnits(amount);
    const rounded = ((units + 50000000n) / 100000000n).toString();
    return `${units % 100000000n === 0n ? '' : '≈ '}${rounded.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} USDT`;
}

export default function PromotionHub({
    home,
    section = 'overview',
}: {
    home: Home;
    section?: 'overview' | 'rules';
}) {
    useClientTranslation();
    const p = home.paid;
    const { errors } = usePage<SharedProps>().props;
    const track = useRef<HTMLDivElement>(null);
    const shareDock = useRef<HTMLDivElement>(null);
    const [shareDockHeight, setShareDockHeight] = useState(64);
    const [selected, setSelected] = useState(p.rank);
    const [busy, setBusy] = useState(false);
    const submitting = useRef(false);
    const intents = useRef(new Map<string, string>());
    const [shareState, setShareState] = useState<'idle' | 'copied' | 'shared' | 'failed'>('idle');
    const [sharing, setSharing] = useState(false);
    const link = `${window.location.origin}/register?invite=${encodeURIComponent(home.invitationCode)}`;
    const title = section === 'rules' ? 'Invitation rules' : 'Promotion center';
    const select = (rank: number, behavior: ScrollBehavior = 'smooth') => {
        const node = track.current;
        const card = node?.querySelector<HTMLElement>(`[data-rank="${rank}"]`);
        if (!node || !card) return;
        node.scrollTo({
            left: card.offsetLeft - (node.clientWidth - card.offsetWidth) / 2,
            behavior,
        });
        setSelected(rank);
    };
    useEffect(() => {
        select(p.rank, 'instant');
    }, [p.rank, section]);
    useEffect(() => {
        const node = shareDock.current;
        if (!node) return;
        const measure = () => setShareDockHeight(node.getBoundingClientRect().height);
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(node);
        return () => observer.disconnect();
    }, [section]);
    const quote = (id: string) => {
        if (submitting.current || p.pending || !home.canPurchase) return;
        const requestId = intents.current.get(id) ?? crypto.randomUUID();
        intents.current.set(id, requestId);
        submitting.current = true;
        setBusy(true);
        router.post(
            '/promotion/quotes',
            { level_id: id, request_id: requestId },
            {
                preserveScroll: true,
                onError: () => intents.current.delete(id),
                onSuccess: () => intents.current.delete(id),
                onFinish: () => {
                    submitting.current = false;
                    setBusy(false);
                },
            },
        );
    };
    const copy = async () => {
        setShareState('idle');
        try {
            await navigator.clipboard.writeText(link);
            setShareState('copied');
        } catch {
            setShareState('failed');
        }
    };
    const share = async () => {
        setSharing(true);
        setShareState('idle');
        try {
            if (navigator.share) {
                await navigator.share({ title: t('Invitation to join'), url: link });
                setShareState('shared');
            } else await copy();
        } catch (error) {
            if (!(error instanceof Error && error.name === 'AbortError')) await copy();
        } finally {
            setSharing(false);
        }
    };
    const rules = [
        [
            'Invitation relationship',
            'Friends who register through your link or invitation code join your team. Direct invitees and indirect descendants are counted separately.',
        ],
        [
            'Activation commission',
            'Each account activates once through a member deposit or agent purchase. Only first activation through a deposit earns activation commission; direct agent purchases earn annual fee commission only.',
        ],
        [
            'Reward differentials',
            'The direct inviter earns their activation standard; higher ancestors earn only the positive uncovered difference. Ordinary members earn 20 USDT for direct activations only. The payer’s own level does not reduce their direct inviter’s reward.',
        ],
        [
            'Annual fee commission',
            'Annual fee commission uses the wallet payment only. Direct agents earn their own rate regardless of the payer’s level. Indirect agents must be at or above the payer’s purchased level and earn only the uncovered rate difference. Ineligible ancestors do not consume a rate share.',
        ],
        [
            'Membership validity',
            'Membership lasts one year with manual renewal after expiry. An active membership can only be upgraded: pay the tariff difference and keep the original expiry. After expiry, ordinary member rewards apply; past commissions remain.',
        ],
        [
            'Annual fee rebate conditions',
            'Count each account once at its first deposit or agent activation within the paid year: direct as 1 and indirect as 0.5. Renewals, upgrades and repeat deposits do not count again. Eligible annual fee returns include converted deposits and are credited automatically to USDT. Upgrades keep progress and expiry.',
        ],
    ];
    return (
        <UserLayout>
            <Head title={t(title)} />
            <div
                className="promotion-page promotion-hub"
                style={section === 'overview' ? { paddingBottom: shareDockHeight + 16 } : undefined}
            >
                <UserPageHeader
                    title={t(title)}
                    backHref={section === 'rules' ? '/promotion' : '/account'}
                />
                {section === 'rules' ? (
                    <>
                        <a
                            href="/images/promotion/invitation-rules.jpg"
                            target="_blank"
                            rel="noreferrer"
                            className="block"
                        >
                            <img
                                src="/images/promotion/invitation-rules.jpg"
                                alt={t('Invitation rules')}
                                width={1024}
                                height={1536}
                                className="h-auto w-full rounded-lg"
                            />
                        </a>
                        <div className="space-y-6">
                            {rules.map(([heading = '', body = '']) => (
                                <section key={heading} className="border-b pb-5">
                                    <h2>{t(heading)}</h2>
                                    <p className="mt-3 text-sm leading-7 text-muted-foreground">
                                        {t(body)}
                                    </p>
                                </section>
                            ))}
                        </div>
                        <Link
                            href="/promotion/membership#rebate-history"
                            className="min-h-11 py-3 text-sm underline"
                        >
                            {t('Fee rebate history')}
                        </Link>
                    </>
                ) : (
                    <>
                        <div ref={shareDock} className="promotion-share-dock">
                            <div className="grid grid-cols-2 gap-3">
                                <InvitationPoster
                                    link={link}
                                    code={home.invitationCode}
                                    background={home.posterBackground}
                                />
                                <Button
                                    className="w-full rounded-full"
                                    onClick={() => void share()}
                                    disabled={sharing}
                                >
                                    <Share2 className="size-4" />
                                    {t('Share invitation link')}
                                </Button>
                            </div>
                            <p
                                role="status"
                                className="flex items-center gap-2 text-xs text-muted-foreground"
                            >
                                {shareState === 'copied' && (
                                    <>
                                        <Check className="size-4" />
                                        {t('Copied')}
                                    </>
                                )}
                                {shareState === 'shared' && t('Invitation link shared.')}
                                {shareState === 'failed' &&
                                    t('Could not copy. Copy the link below manually.')}
                            </p>
                            {shareState === 'failed' && (
                                <input
                                    readOnly
                                    value={link}
                                    aria-label={t('Invitation link')}
                                    onFocus={(e) => e.target.select()}
                                    className="w-full min-w-0 rounded-xl border p-3 text-xs"
                                />
                            )}
                        </div>
                        {Object.entries(errors ?? {}).map(([key, message]) => (
                            <p key={key} role="alert" className="text-sm text-destructive">
                                {errorMessage(message)}
                            </p>
                        ))}
                        <section aria-label={t('Promotion level benefits')}>
                            <div
                                ref={track}
                                className="promotion-level-track"
                                tabIndex={0}
                                aria-label={t('Swipe to compare levels')}
                                onKeyDown={(e) => {
                                    if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                                        e.preventDefault();
                                        select(
                                            Math.max(
                                                0,
                                                Math.min(
                                                    8,
                                                    selected + (e.key === 'ArrowRight' ? 1 : -1),
                                                ),
                                            ),
                                        );
                                    }
                                }}
                                onScroll={() => {
                                    const node = track.current;
                                    if (!node) return;
                                    const center = node.scrollLeft + node.clientWidth / 2;
                                    const cards = Array.from(
                                        node.querySelectorAll<HTMLElement>('[data-rank]'),
                                    );
                                    const closest = cards.reduce<HTMLElement | null>(
                                        (best, card) =>
                                            !best ||
                                            Math.abs(
                                                card.offsetLeft + card.offsetWidth / 2 - center,
                                            ) <
                                                Math.abs(
                                                    best.offsetLeft + best.offsetWidth / 2 - center,
                                                )
                                                ? card
                                                : best,
                                        null,
                                    );
                                    if (closest) setSelected(Number(closest.dataset.rank));
                                }}
                            >
                                {Array.from({ length: 9 }, (_, rank) => {
                                    const offer = p.levels.find((l) => l.rank === rank);
                                    const current = p.rank === rank;
                                    const fee =
                                        rank === 0
                                            ? '0'
                                            : current && p.cycle
                                              ? p.cycle.tariff
                                              : offer?.fee;
                                    const percent = current ? p.percent : offer?.percent;
                                    const reward =
                                        rank === 0 ? '20' : current ? p.reward : offer?.reward;
                                    const canUpgrade =
                                        !!offer &&
                                        rank > p.rank &&
                                        (!p.cycle ||
                                            promotionUnits(offer.fee) >
                                                promotionUnits(p.cycle.tariff));
                                    return (
                                        <article
                                            key={rank}
                                            data-rank={rank}
                                            className={`promotion-level-card ${current ? 'is-current' : ''}`}
                                            aria-label={promotionLevel(rank)}
                                        >
                                            <div className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-2">
                                                <div className="min-w-0">
                                                    <h2 className="break-words">
                                                        {promotionLevel(rank)}
                                                    </h2>
                                                    {current && (
                                                        <span className="mt-2 inline-block rounded-full border border-current/20 px-2 py-0.5 text-xs">
                                                            {t('Current level')}
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="promotion-level-price whitespace-nowrap text-right text-sm font-semibold leading-6 tabular-nums">
                                                    {rank === 0 ? (
                                                        t('No annual fee')
                                                    ) : fee !== undefined ? (
                                                        <>
                                                            {annualFeeLabel(fee)}{' '}
                                                            <span className="text-xs font-normal opacity-75">
                                                                {t('per year')}
                                                            </span>
                                                        </>
                                                    ) : (
                                                        t('Not configured')
                                                    )}
                                                </p>
                                            </div>
                                            <div className="mt-3 grid grid-cols-2 gap-3 border-t border-current/15 pt-3">
                                                <div>
                                                    <p className="text-xs leading-5 opacity-75">
                                                        {t('Direct activation standard')}
                                                    </p>
                                                    <p className="mt-1 break-words text-lg font-semibold">
                                                        {reward ?? '—'}{' '}
                                                        <span className="text-xs font-normal">
                                                            USDT
                                                        </span>
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="text-xs leading-5 opacity-75">
                                                        {t('Annual fee reward rate')}
                                                    </p>
                                                    <p className="mt-1 text-lg font-semibold">
                                                        {rank === 0
                                                            ? '—'
                                                            : percent === undefined
                                                              ? '—'
                                                              : `${percent}%`}
                                                    </p>
                                                </div>
                                            </div>
                                            {current && p.cycle && p.progress && (
                                                <div className="mt-3">
                                                    <AnnualRebateProgress progress={p.progress} />
                                                </div>
                                            )}
                                            <div className="mt-auto pt-3">
                                                {current ? (
                                                    <p className="text-xs leading-5">
                                                        {p.cycle
                                                            ? t('Valid until {{time}}', {
                                                                  time: dateTime(p.cycle.endsAt),
                                                              })
                                                            : t('Your current benefits')}
                                                    </p>
                                                ) : rank < p.rank ? (
                                                    <p className="text-xs">
                                                        {t('Downgrades are not available.')}
                                                    </p>
                                                ) : !offer?.enabled || !canUpgrade ? (
                                                    <p className="text-xs">
                                                        {t('Currently unavailable for purchase')}
                                                    </p>
                                                ) : (
                                                    <Button
                                                        className="w-full"
                                                        disabled={
                                                            busy || p.pending || !home.canPurchase
                                                        }
                                                        onClick={() => quote(offer.id)}
                                                    >
                                                        {t(
                                                            p.membershipStatus === 'EXPIRED'
                                                                ? 'Renew or reactivate'
                                                                : p.rank > 0
                                                                  ? 'Upgrade to this level'
                                                                  : 'Apply for this level',
                                                        )}
                                                    </Button>
                                                )}
                                            </div>
                                        </article>
                                    );
                                })}
                            </div>
                            <div className="mt-3 flex items-center justify-between gap-2">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    disabled={selected === 0}
                                    aria-label={t('Previous level')}
                                    onClick={() => select(selected - 1)}
                                >
                                    <ChevronLeft className="size-4" />
                                </Button>
                                <div
                                    className="flex min-w-0 flex-wrap justify-center gap-0"
                                    aria-label={t('Choose promotion level')}
                                >
                                    {Array.from({ length: 9 }, (_, rank) => (
                                        <button
                                            key={rank}
                                            className="grid min-h-11 w-6 place-items-center"
                                            aria-label={promotionLevel(rank)}
                                            aria-current={selected === rank ? 'true' : undefined}
                                            onClick={() => select(rank)}
                                        >
                                            <span
                                                className={`h-1.5 rounded-full ${selected === rank ? 'w-5 bg-primary' : 'w-1.5 bg-primary/20'}`}
                                            />
                                        </button>
                                    ))}
                                </div>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    disabled={selected === 8}
                                    aria-label={t('Next level')}
                                    onClick={() => select(selected + 1)}
                                >
                                    <ChevronRight className="size-4" />
                                </Button>
                            </div>
                            <p className="text-center text-xs text-muted-foreground">
                                {t('Swipe to compare levels')}
                            </p>
                            {!home.canPurchase && (
                                <p className="mt-3 text-xs leading-5 text-muted-foreground">
                                    {t(
                                        'An active verified USDT wallet is required to purchase a level.',
                                    )}
                                </p>
                            )}
                            {p.pending && (
                                <p role="status" className="mt-3 rounded-xl bg-muted p-3 text-sm">
                                    {t('Annual fee return is processing. Try upgrading shortly.')}{' '}
                                    <Link
                                        href="/promotion/membership#rebate-history"
                                        className="underline"
                                    >
                                        {t('Fee rebate history')}
                                    </Link>
                                </p>
                            )}
                        </section>
                        <nav className="grid grid-cols-2 gap-3" aria-label={t('Promotion details')}>
                            {[
                                {
                                    href: '/promotion/invitations',
                                    label: 'Invitation data',
                                    Icon: Users,
                                },
                                {
                                    href: '/promotion/rules',
                                    label: 'Invitation rules',
                                    Icon: BookOpen,
                                },
                            ].map(({ href, label, Icon }) => (
                                <Link
                                    key={href}
                                    href={href}
                                    className="flex min-w-0 items-center gap-3 rounded-2xl border bg-surface p-4 text-sm font-medium"
                                >
                                    <Icon className="size-5 shrink-0 text-primary" />
                                    <span className="flex-1 break-words">{t(label)}</span>
                                    <ChevronRight className="size-4 shrink-0" />
                                </Link>
                            ))}
                        </nav>
                        <section>
                            <h2>{t('Invitation steps')}</h2>
                            <ol className="promotion-invite-steps">
                                {[
                                    'Share your invitation link with a friend.',
                                    'Your friend registers through the link and joins your team.',
                                    'After a successful security deposit or promotion annual fee payment, rewards follow your valid level and the commission rules.',
                                ].map((step, i) => (
                                    <li key={step}>
                                        <span aria-hidden="true">{i + 1}</span>
                                        <p>{t(step)}</p>
                                    </li>
                                ))}
                            </ol>
                        </section>
                        <section className="space-y-3 pb-4">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        {t('Invitation code')}
                                    </p>
                                    <p className="mt-1 text-xl font-semibold tracking-widest">
                                        {home.invitationCode}
                                    </p>
                                </div>
                                <Button variant="ghost" onClick={() => void copy()}>
                                    <Copy className="size-4" />
                                    {t('Copy invitation link')}
                                </Button>
                            </div>
                        </section>
                    </>
                )}
            </div>
        </UserLayout>
    );
}
