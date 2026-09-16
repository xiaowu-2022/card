import { promotionLevel, promotionTableAmount, commissionSum } from '@/lib/paid-promotion';
import {
    PaidPromotionSummary,
    type PaidPromotionData,
} from '@/components/user/PaidPromotionSummary';
import { promotionMoney as systemMoney } from '@/lib/paid-promotion';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import {
    ArrowUpRight,
    Users,
    UserCheck,
    ShieldCheck,
    Coins,
    ListFilter,
    ChevronDown,
    UserRoundPlus,
    ReceiptText,
} from 'lucide-react';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { PromotionDateFilter } from '@/components/user/PromotionDateFilter';
import { FinancialConfirmation } from '@/components/user/FinancialConfirmation';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectTrigger,
    SelectValue,
    SelectContent,
    SelectItem,
} from '@/components/ui/select';
import { t, dateTime, useClientTranslation, errorMessage } from '@/i18n';
import type { SharedProps } from '@/types/global';
import '../../../css/promotion.css';

type Stats = { invited: number; activated: number; deposits: string; commission: string };
type Level = { id: string; name: string };
type Member = {
    id: string;
    accountId: string;
    levelId: string | null;
    rank: number;
    joinedAt: string;
    depositAmount: string;
    myCommission: string;
};
type Promotion = {
    paid: PaidPromotionData;
    invitationCode: string;
    levelName: string | null;
    availableCommission: string;
    myCommission: string;
    supported: boolean;
    canTransfer: boolean;
    commissionRefundRestricted?: boolean;
    date: string;
    timezone: string;
    totals: Stats;
    daily: Stats;
    direct: Member[];
    assignableLevels: Level[];
    canAssign: boolean;
    directTotal: number;
    filters: { accountId: string; funding: string };
    directPage: number;
    hasMoreDirect: boolean;
    page: number;
    hasMore: boolean;
    details: {
        id: string;
        kind: string;
        accountId: string;
        sourceAccountId: string | null;
        inviterAccountId: string | null;
        invitedByMe: boolean;
        depositAmount: string | null;
        amount: string | null;
        occurredAt: string;
    }[];
};
function activityDescription(row: Promotion['details'][number]): string {
    if (!row.sourceAccountId || !row.inviterAccountId) return row.accountId;
    const values = { account: row.sourceAccountId, inviter: row.inviterAccountId };
    if (row.kind === 'Annual fee commission') return t('Annual fee paid by {{account}}', values);
    if (row.kind === 'Invitation') {
        return t(
            row.invitedByMe
                ? 'You invited {{account}}'
                : 'Team member {{inviter}} invited {{account}}',
            values,
        );
    }
    if (row.kind === 'Activation') {
        return t(
            row.invitedByMe
                ? 'Your invitee {{account}} funded their first deposit'
                : '{{account}}, invited by team member {{inviter}}, funded their first deposit',
            values,
        );
    }
    return t(
        row.invitedByMe
            ? 'Your invitee {{account}} funded a deposit'
            : '{{account}}, invited by team member {{inviter}}, funded a deposit',
        values,
    );
}

function PromotionStats({ stats, compact = false }: { stats: Stats; compact?: boolean }) {
    const items = [
        { label: 'Team invitations', value: stats.invited, icon: Users },
        { label: 'Team activations', value: stats.activated, icon: UserCheck },
        {
            label: 'Team deposit funding',
            value: systemMoney(stats.deposits),
            icon: ShieldCheck,
            money: true,
        },
        {
            label: compact ? 'My commission' : 'Team commission',
            value: systemMoney(stats.commission),
            icon: Coins,
            money: true,
        },
    ];
    return (
        <dl className={`promotion-stats ${compact ? 'promotion-stats-daily' : ''}`}>
            {items.map(({ label, value, icon: Icon }) => (
                <div key={label}>
                    <dt>
                        {!compact && <Icon aria-hidden="true" />}
                        <span>{t(label)}</span>
                    </dt>
                    <dd>{value}</dd>
                </div>
            ))}
        </dl>
    );
}
function DirectMember({ member }: { member: Member }) {
    return (
        <article className="promotion-member">
            <div className="promotion-member-heading">
                <div className="promotion-member-identity">
                    <span>{t('Account ID')}</span>
                    <strong className="font-mono">{member.accountId}</strong>
                </div>
                <span className="promotion-member-level">{promotionLevel(member.rank)}</span>
            </div>
            <dl className="promotion-member-money">
                <div>
                    <dt>{t('Security deposit')}</dt>
                    <dd>
                        {/^0(?:\.0+)?$/.test(member.depositAmount)
                            ? t('Deposit not funded')
                            : systemMoney(member.depositAmount)}
                    </dd>
                </div>
                <div>
                    <dt>{t('My commission')}</dt>
                    <dd>{systemMoney(member.myCommission)}</dd>
                </div>
            </dl>
            <div className="promotion-member-footer">
                <span>
                    {t('Joined at')}: {dateTime(member.joinedAt)}
                </span>
            </div>
        </article>
    );
}
export default function PromotionPage({
    promotion: p,
    section = 'invitations',
}: {
    promotion: Promotion;
    section?: 'invitations' | 'daily' | 'direct';
}) {
    useClientTranslation();
    const [requestId, setRequestId] = useState(() => crypto.randomUUID());
    const errors = usePage<SharedProps>().props.errors as Record<string, string>;
    const title = {
        invitations: 'My invitations',
        daily: 'Daily data',
        direct: 'Direct invitees',
    }[section];
    const resultsRef = useRef<HTMLDivElement>(null);
    const [search, setSearch] = useState(p.filters.accountId);
    const visit = (values: Record<string, string | number>) =>
        router.get(
            `/promotion/${section}`,
            {
                date: p.date,
                page: p.page,
                direct_page: p.directPage,
                account_id: p.filters.accountId,
                funding: p.filters.funding,
                ...values,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () =>
                    requestAnimationFrame(() =>
                        resultsRef.current?.scrollIntoView({ block: 'start' }),
                    ),
            },
        );
    return (
        <UserLayout>
            <Head title={t(title)} />
            <div className="promotion-page">
                <UserPageHeader
                    title={t(title)}
                    backHref={section === 'invitations' ? '/promotion' : '/promotion/invitations'}
                />
                {errors?.form && section !== 'direct' && (
                    <p role="alert" className="text-red-700">
                        {errorMessage(errors.form)}
                    </p>
                )}
                {!p.supported && (
                    <p role="status">{t('Promotion currently supports USDT accounts only.')}</p>
                )}
                {section === 'invitations' && (
                    <>
                        <section
                            className="promotion-commission border border-[#c7ac6b]"
                            aria-labelledby="commission-title"
                        >
                            <div>
                                <h2 id="commission-title" className="text-sm text-[#e2ecd8]">
                                    {t('My total commission')}
                                </h2>
                                <p className="mt-2 break-all text-3xl font-semibold tabular-nums text-[#fff0bb]">
                                    {promotionTableAmount(p.myCommission)}{' '}
                                    <span className="text-sm font-normal">USDT</span>
                                </p>
                            </div>
                            <div className="promotion-commission-bottom">
                                <div className="promotion-earned">
                                    <p>{t('Commission balance')}</p>
                                    <p className="break-all">
                                        {systemMoney(p.availableCommission)}
                                    </p>
                                </div>
                                <div className="promotion-commission-action">
                                    <FinancialConfirmation
                                        title={t('Transfer commission to balance')}
                                        warning={t(
                                            'Transfer all available commission to your wallet. Withdrawals must use the wallet withdrawal flow.',
                                        )}
                                        url="/promotion"
                                        payload={{ action: 'transfer', request_id: requestId }}
                                        disabled={
                                            !p.supported ||
                                            !p.canTransfer ||
                                            /^0(?:\.0+)?$/.test(p.availableCommission)
                                        }
                                        onCompleted={() => setRequestId(crypto.randomUUID())}
                                    />
                                </div>
                            </div>
                            {!p.canTransfer && (
                                <p className="promotion-commission-note">
                                    {t(
                                        p.commissionRefundRestricted
                                            ? 'During and after a deposit refund, commission can still be earned but cannot be transferred to your wallet or withdrawn. Existing wallet balance can still be withdrawn.'
                                            : 'An active verified wallet is required to receive commission.',
                                    )}
                                </p>
                            )}
                        </section>
                        <section
                            className="rounded-2xl border bg-surface p-5"
                            aria-labelledby="team-title"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <h2 id="team-title">{t('Team members')}</h2>
                                <span className="text-2xl font-semibold">
                                    {p.paid.directPeople + p.paid.indirectPeople}
                                </span>
                            </div>
                            <div className="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-muted p-4">
                                <div>
                                    <p className="text-2xl font-semibold">{p.paid.directPeople}</p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {t('Direct team members')}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-2xl font-semibold">
                                        {p.paid.indirectPeople}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {t('Indirect team members')}
                                    </p>
                                </div>
                            </div>
                            <p className="mt-3 text-xs leading-5 text-muted-foreground">
                                {t(
                                    'Indirect members include all descendants beyond your direct invitees.',
                                )}
                            </p>
                        </section>
                        {(['ANNUAL', 'ACTIVATION'] as const).map((kind) => (
                            <section key={kind} className="rounded-2xl border bg-surface p-5">
                                <h2>
                                    {t(
                                        kind === 'ANNUAL'
                                            ? 'Annual fee commission'
                                            : 'Activation commission',
                                    )}
                                </h2>
                                <p className="mt-2 break-all text-2xl font-semibold">
                                    {systemMoney(p.paid.totals[kind])}
                                </p>
                                <dl className="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-muted p-4">
                                    {(['direct', 'indirect'] as const).map((relation) => (
                                        <div key={relation} className="min-w-0">
                                            <dt className="text-xs text-muted-foreground">
                                                {t(
                                                    relation === 'direct'
                                                        ? 'Direct commission income'
                                                        : 'Indirect commission income',
                                                )}
                                            </dt>
                                            <dd className="mt-2 break-all text-sm font-medium">
                                                {systemMoney(
                                                    commissionSum(
                                                        ...p.paid.tables[kind].map(
                                                            (row) => row[relation].amount,
                                                        ),
                                                    ),
                                                )}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </section>
                        ))}
                        <section className="border-y py-4">
                            <h2>{t('Legacy commission')}</h2>
                            <p className="mt-2 break-all text-lg font-semibold">
                                {systemMoney(p.paid.legacy)}
                            </p>
                            <Link
                                href="/promotion/commissions"
                                className="mt-2 inline-flex min-h-11 items-center text-sm underline"
                            >
                                {t('View records')}
                            </Link>
                        </section>
                        <PaidPromotionSummary paid={p.paid} />
                        <nav className="promotion-destinations" aria-label={t('Promotion details')}>
                            {[
                                { href: '/promotion/daily', label: 'Daily data', icon: ListFilter },
                                {
                                    href: '/promotion/direct',
                                    label: 'Direct invitees',
                                    icon: UserRoundPlus,
                                },
                                {
                                    href: '/promotion/commissions',
                                    label: 'Commission details',
                                    icon: ReceiptText,
                                },
                            ].map(({ href, label, icon: Icon }) => (
                                <Link key={href} href={href}>
                                    <Icon aria-hidden="true" />
                                    <span>{t(label)}</span>
                                </Link>
                            ))}
                        </nav>
                    </>
                )}
                {section === 'daily' && (
                    <section className="promotion-section promotion-daily">
                        <PromotionDateFilter
                            id="promotion-date"
                            date={p.date}
                            onChange={(date) => date && visit({ date, page: 1 })}
                        />
                        <details className="promotion-explanation">
                            <summary>
                                {t('Statistics notes')}
                                <ChevronDown aria-hidden="true" />
                            </summary>
                            <p>{t('Dates and times follow the company timezone.')}</p>
                        </details>
                        <PromotionStats stats={p.daily} compact />
                        <div ref={resultsRef} className="promotion-results">
                            {p.details.length === 0 && (
                                <div className="promotion-empty">
                                    <ListFilter aria-hidden="true" />
                                    <p>{t('No promotion changes on this date.')}</p>
                                </div>
                            )}
                            <ul className="promotion-activity">
                                {p.details.map((row) => (
                                    <li key={row.id}>
                                        <span className="promotion-activity-icon">
                                            <ArrowUpRight aria-hidden="true" />
                                        </span>
                                        <div className="promotion-activity-description">
                                            <p className="font-medium">{t(row.kind)}</p>
                                            <p>{activityDescription(row)}</p>
                                            {row.kind === 'Commission earned' &&
                                                typeof row.depositAmount === 'string' && (
                                                    <p>
                                                        {t('Source deposit: {{amount}}', {
                                                            amount: systemMoney(row.depositAmount),
                                                        })}
                                                    </p>
                                                )}
                                            <p>{dateTime(row.occurredAt)}</p>
                                        </div>
                                        {row.amount !== null && (
                                            <span className="promotion-activity-amount">
                                                {systemMoney(row.amount)}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                        {(p.page > 1 || p.hasMore) && (
                            <div className="promotion-pagination">
                                <Button
                                    variant="ghost"
                                    disabled={p.page === 1}
                                    onClick={() => visit({ page: p.page - 1 })}
                                >
                                    {t('Previous')}
                                </Button>
                                <Button
                                    variant="ghost"
                                    disabled={!p.hasMore}
                                    onClick={() => visit({ page: p.page + 1 })}
                                >
                                    {t('Next')}
                                </Button>
                            </div>
                        )}
                    </section>
                )}
                {section === 'direct' && (
                    <section className="promotion-section">
                        <form
                            className="promotion-member-filters"
                            onSubmit={(event) => {
                                event.preventDefault();
                                visit({ account_id: search, direct_page: 1 });
                            }}
                        >
                            <Input
                                aria-label={t('Search account ID')}
                                placeholder={t('Search account ID')}
                                value={search}
                                maxLength={24}
                                inputMode="numeric"
                                onChange={(event) =>
                                    setSearch(event.target.value.replace(/[^0-9]/g, ''))
                                }
                                className="min-w-0 flex-1"
                            />
                            <Button type="submit">{t('Search')}</Button>
                            <Select
                                value={p.filters.funding}
                                onValueChange={(funding) => visit({ funding, direct_page: 1 })}
                            >
                                <SelectTrigger aria-label={t('Deposit status')}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {['all', 'funded', 'unfunded'].map((value) => (
                                        <SelectItem key={value} value={value}>
                                            {t(
                                                value === 'all'
                                                    ? 'All'
                                                    : value === 'funded'
                                                      ? 'Deposit funded'
                                                      : 'Deposit not funded',
                                            )}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </form>
                        <p className="text-sm text-muted-foreground">
                            {t('Members: {{count}} · Page {{page}}', {
                                count: p.directTotal,
                                page: p.directPage,
                            })}
                        </p>

                        <details className="promotion-explanation">
                            <summary>
                                {t('Level rules')}
                                <ChevronDown aria-hidden="true" />
                            </summary>
                            <p>
                                {t(
                                    'Rules apply to new payments only. Qualification requires payment; manual level assignment is unavailable.',
                                )}
                            </p>
                            <p>
                                {t(
                                    'Member commission includes your annual fee and activation rewards from this member.',
                                )}
                            </p>
                            <p>{t('Dates and times follow the company timezone.')}</p>
                        </details>
                        <div ref={resultsRef} className="promotion-results">
                            {p.direct.length === 0 && (
                                <div className="promotion-empty">
                                    <Users aria-hidden="true" />
                                    <p>
                                        {t(
                                            p.filters.accountId || p.filters.funding !== 'all'
                                                ? 'No matching members.'
                                                : 'No direct invitees yet.',
                                        )}
                                    </p>
                                </div>
                            )}
                            {p.direct.map((member) => (
                                <DirectMember
                                    key={`${member.id}:${member.levelId}`}
                                    member={member}
                                />
                            ))}
                        </div>
                        {(p.directPage > 1 || p.hasMoreDirect) && (
                            <div className="promotion-pagination">
                                <Button
                                    variant="ghost"
                                    disabled={p.directPage === 1}
                                    onClick={() => visit({ direct_page: p.directPage - 1 })}
                                >
                                    {t('Previous')}
                                </Button>
                                <Button
                                    variant="ghost"
                                    disabled={!p.hasMoreDirect}
                                    onClick={() => visit({ direct_page: p.directPage + 1 })}
                                >
                                    {t('Next')}
                                </Button>
                            </div>
                        )}
                    </section>
                )}
            </div>
        </UserLayout>
    );
}
