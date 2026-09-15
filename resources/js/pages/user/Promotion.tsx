import { systemMoney } from '@/lib/system-money';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import {
    ArrowUpRight,
    Check,
    Copy,
    Users,
    UserCheck,
    ShieldCheck,
    Coins,
    Ticket,
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
    joinedAt: string;
    depositAmount: string;
    myCommission: string;
};
type Promotion = {
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
function DirectMember({
    member,
    levels,
    canAssign,
}: {
    member: Member;
    levels: Level[];
    canAssign: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ action: 'level', member_id: member.id, level_id: member.levelId });
    const currentAllowed =
        member.levelId === null || levels.some((level) => level.id === member.levelId);
    return (
        <form
            className="promotion-member"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/promotion', {
                    preserveScroll: true,
                    onSuccess: () => setEditing(false),
                });
            }}
        >
            <div className="promotion-member-heading">
                <div className="promotion-member-identity">
                    <span>{t('Account ID')}</span>
                    <strong className="font-mono">{member.accountId}</strong>
                </div>
                <span className="promotion-member-level">
                    {t('Promotion level')}:{' '}
                    {levels.find((level) => level.id === member.levelId)?.name ??
                        t(member.levelId ? 'Current higher level' : 'Unranked')}
                </span>
            </div>
            <dl className="promotion-member-money">
                <div>
                    <dt>{t('Security deposit')}</dt>
                    <dd
                        className={
                            /^0(?:\.0+)?$/.test(member.depositAmount) ? 'text-muted-foreground' : ''
                        }
                    >
                        {/^0(?:\.0+)?$/.test(member.depositAmount) ? (
                            t('Deposit not funded')
                        ) : (
                            <>
                                {t('Deposit funded')} · {systemMoney(member.depositAmount)}
                            </>
                        )}
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
                {!editing && canAssign && (
                    <Button type="button" variant="ghost" onClick={() => setEditing(true)}>
                        {t('Edit level')}
                    </Button>
                )}
            </div>
            {editing && (
                <div className="promotion-member-controls">
                    <Select
                        disabled={!canAssign}
                        value={form.data.level_id ?? 'none'}
                        onValueChange={(value) =>
                            form.setData('level_id', value === 'none' ? null : value)
                        }
                    >
                        <SelectTrigger aria-label={t('Promotion level')}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">{t('Unranked')}</SelectItem>
                            {!currentAllowed && member.levelId && (
                                <SelectItem value={member.levelId} disabled>
                                    {t('Current higher level')}
                                </SelectItem>
                            )}
                            {levels.map((level) => (
                                <SelectItem key={level.id} value={level.id}>
                                    {level.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button
                        className="shrink-0 whitespace-nowrap"
                        variant="secondary"
                        type="submit"
                        disabled={form.processing || !canAssign}
                    >
                        {t('Save')}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={form.processing}
                        onClick={() => {
                            form.reset();
                            form.clearErrors();
                            setEditing(false);
                        }}
                    >
                        {t('Cancel')}
                    </Button>
                </div>
            )}
            {editing && Object.keys(form.errors).length > 0 && (
                <div className="promotion-member-errors" role="alert">
                    {Object.entries(form.errors).map(([field, message]) => (
                        <p key={field}>{errorMessage(message)}</p>
                    ))}
                </div>
            )}
        </form>
    );
}
export default function PromotionPage({
    promotion: p,
    section = 'overview',
}: {
    promotion: Promotion;
    section?: 'overview' | 'daily' | 'direct';
}) {
    useClientTranslation();
    const [copied, setCopied] = useState(false);
    const [copyFailed, setCopyFailed] = useState(false);
    const [requestId, setRequestId] = useState(() => crypto.randomUUID());
    const errors = usePage<SharedProps>().props.errors as Record<string, string>;
    const title = {
        overview: 'Promotion center',
        daily: 'Daily data',
        direct: 'Direct invitees',
    }[section];
    const resultsRef = useRef<HTMLDivElement>(null);
    const [search, setSearch] = useState(p.filters.accountId);
    const visit = (values: Record<string, string | number>) =>
        router.get(
            section === 'overview' ? '/promotion' : `/promotion/${section}`,
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
                    backHref={section === 'overview' ? '/account' : '/promotion'}
                />
                {errors?.form && section !== 'direct' && (
                    <p role="alert" className="text-red-700">
                        {errorMessage(errors.form)}
                    </p>
                )}
                {!p.supported && (
                    <p role="status">{t('Promotion currently supports USDT accounts only.')}</p>
                )}
                {section === 'overview' && (
                    <>
                        <section
                            className="promotion-commission"
                            aria-labelledby="commission-title"
                        >
                            <div className="promotion-commission-top">
                                <h2 id="commission-title">{t('Available commission')}</h2>
                                <span
                                    className="promotion-level"
                                    aria-label={`${t('Promotion level')}: ${p.levelName ?? t('Unranked')}`}
                                >
                                    <ShieldCheck aria-hidden="true" />
                                    <span>
                                        {t('Promotion level')}: {p.levelName ?? t('Unranked')}
                                    </span>
                                </span>
                            </div>
                            <p className="promotion-commission-amount">
                                {systemMoney(p.availableCommission)}
                            </p>
                            <div className="promotion-commission-bottom">
                                <div className="promotion-earned">
                                    <p>{t('My total commission')}</p>
                                    <p>{systemMoney(p.myCommission)}</p>
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
                            className="promotion-invitation"
                            aria-labelledby="invitation-title"
                        >
                            <div className="promotion-invitation-code">
                                <h2 id="invitation-title">
                                    <Ticket aria-hidden="true" />
                                    {t('My invitation')}
                                </h2>
                                <p>{p.invitationCode}</p>
                            </div>
                            <Button
                                variant="secondary"
                                onClick={() => {
                                    setCopyFailed(false);
                                    void navigator.clipboard
                                        .writeText(
                                            `${window.location.origin}/register?invite=${encodeURIComponent(p.invitationCode)}`,
                                        )
                                        .then(() => setCopied(true))
                                        .catch(() => {
                                            setCopied(false);
                                            setCopyFailed(true);
                                        });
                                }}
                            >
                                {copied ? (
                                    <Check aria-hidden="true" />
                                ) : (
                                    <Copy aria-hidden="true" />
                                )}
                                <span>{copied ? t('Copied') : t('Copy invitation link')}</span>
                            </Button>
                            <span className="sr-only" role="status">
                                {copied ? t('Copied') : copyFailed ? t('Could not copy.') : ''}
                            </span>
                            {copyFailed && (
                                <p className="promotion-copy-error" role="alert">
                                    {t('Could not copy.')}{' '}
                                    <span className="select-all">{p.invitationCode}</span>
                                </p>
                            )}
                        </section>
                        <section
                            className="promotion-section promotion-team-summary"
                            id="team-summary"
                        >
                            <div className="promotion-section-heading">
                                <h2>{t('Team overview')}</h2>
                            </div>
                            <PromotionStats stats={p.totals} />
                            <details className="promotion-explanation">
                                <summary>
                                    {t('How team totals are calculated')}
                                    <ChevronDown aria-hidden="true" />
                                </summary>
                                <p>
                                    {t(
                                        'Activations count each member once. Deposit funding includes genuine repeat payments; team commission includes rewards earned by you and your team.',
                                    )}
                                </p>
                            </details>
                        </section>
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
                                {t('You can assign only levels below your own to direct invitees.')}
                            </p>
                            <p>
                                {t(
                                    'Member commission shows only what you earned from this member’s deposit funding.',
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
                                    levels={p.assignableLevels}
                                    canAssign={p.canAssign}
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
