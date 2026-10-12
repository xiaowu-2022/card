import { AdminPagination } from '@/components/admin/AdminPagination';
import { useEffect, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { MoneyDisplay } from './MoneyDisplay';
import { supportRequest } from '@/components/support/supportRequest';
import { t, dateTime } from '@/i18n/admin';

type Target = {
    companyId: string;
    id: string;
    accountId: string;
    displayName: string | null;
    view: 'funds' | 'cards';
};
type Funds = {
    items: {
        kind: string;
        asset: string;
        amount: string | null;
        held: string;
        usdt: string | null;
    }[];
    estimate: string | null;
    updatedAt: string | null;
    readAt: string;
};
type Totals = { funded: string; returned: string; balance: string | null; consumed: string | null };
type Cards = {
    items: (Totals & { id: string; name: string; last4: string; updated_at: string | null })[];
    totals: Totals;
    page: number;
    total: number;
    hasMore: boolean;
    readAt: string;
};
export function SummaryAmount({
    amount,
    asset = 'USDT',
}: {
    amount: string | null | undefined;
    asset?: string;
}) {
    return amount == null ? (
        <span className="text-muted-foreground">{t('Unavailable estimate')}</span>
    ) : (
        <MoneyDisplay amount={amount} asset={asset} />
    );
}
export function AssetAmounts({ items }: { items?: { asset: string; amount: string }[] }) {
    return (
        <div className="space-y-1">
            {(items?.length ? items : [{ asset: 'USDT', amount: '0' }]).map((item) => (
                <div key={item.asset}>
                    <MoneyDisplay amount={item.amount} asset={item.asset} />
                </div>
            ))}
        </div>
    );
}
export function UserAssetSummaryDialog({
    target,
    onClose,
}: {
    target: Target | null;
    onClose: () => void;
}) {
    const [view, setView] = useState<'funds' | 'cards'>(target?.view ?? 'funds');
    const [archived, setArchived] = useState(false);
    const [page, setPage] = useState(1);
    const [funds, setFunds] = useState<Funds | null>(null);
    const [cards, setCards] = useState<Cards | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        if (target) {
            setView(target.view);
            setArchived(false);
            setPage(1);
            setFunds(null);
            setCards(null);
        }
    }, [target]);
    useEffect(() => {
        if (!target) return;
        let active = true;
        setLoading(true);
        setError(false);
        const query =
            view === 'cards'
                ? `cards-summary?archived=${archived ? 1 : 0}&page=${page}`
                : 'funds-summary';
        supportRequest<Funds | Cards>(
            `/platform/tenants/${target.companyId}/users/${target.id}/${query}`,
        )
            .then((data) => {
                if (active) {
                    if (view === 'funds') setFunds(data as Funds);
                    else setCards(data as Cards);
                }
            })
            .catch(() => {
                if (active) setError(true);
            })
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => {
            active = false;
        };
    }, [target, view, archived, page, retry]);
    const cardsView = () => {
        setPage(1);
        setView('cards');
    };
    return (
        <Dialog
            open={Boolean(target)}
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent className="max-w-3xl" closeLabel={t('Close')}>
                <DialogHeader>
                    <DialogTitle>
                        {t(view === 'funds' ? 'Funds summary' : 'Card balance details')}
                    </DialogTitle>
                    <DialogDescription>
                        {target?.accountId} · {target?.displayName || '—'}
                    </DialogDescription>
                </DialogHeader>
                {view === 'cards' && (
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                        {target?.view === 'funds' ? (
                            <Button variant="secondary" onClick={() => setView('funds')}>
                                {t('Back to funds summary')}
                            </Button>
                        ) : (
                            <span />
                        )}
                        <select
                            aria-label={t('Card scope')}
                            className="h-9 rounded-md border px-3"
                            value={archived ? 'archived' : 'active'}
                            onChange={(e) => {
                                setArchived(e.target.value === 'archived');
                                setPage(1);
                            }}
                        >
                            <option value="active">{t('Unarchived cards')}</option>
                            <option value="archived">{t('Archived cards')}</option>
                        </select>
                    </div>
                )}
                {loading ? (
                    <p role="status">{t('Loading...')}</p>
                ) : error ? (
                    <div role="alert">
                        {t('Unable to load data.')}{' '}
                        <Button variant="secondary" onClick={() => setRetry((r) => r + 1)}>
                            {t('Retry')}
                        </Button>
                    </div>
                ) : view === 'funds' && funds ? (
                    <>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="p-2">{t('Item')}</th>
                                        <th className="p-2">{t('Native amount')}</th>
                                        <th className="p-2">{t('USDT valuation')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {funds.items.map((item) => (
                                        <tr className="border-b" key={`${item.kind}-${item.asset}`}>
                                            <td className="p-2">
                                                {item.kind === 'cards' ? (
                                                    <button
                                                        className="text-primary underline"
                                                        onClick={cardsView}
                                                    >
                                                        {t('Card balance')}
                                                    </button>
                                                ) : (
                                                    t(
                                                        item.kind === 'deposit'
                                                            ? 'Security deposit'
                                                            : item.kind === 'wealth'
                                                              ? 'Wealth principal'
                                                              : item.asset,
                                                    )
                                                )}
                                                {item.kind === 'wealth' && ` · ${item.asset}`}
                                            </td>
                                            <td className="p-2">
                                                <SummaryAmount
                                                    amount={item.amount}
                                                    asset={item.asset}
                                                />
                                                {item.kind === 'wallet' && (
                                                    <div className="text-xs text-muted-foreground">
                                                        {t('Held amount')}:{' '}
                                                        <MoneyDisplay
                                                            amount={item.held}
                                                            asset={item.asset}
                                                        />
                                                    </div>
                                                )}
                                            </td>
                                            <td className="p-2">
                                                <SummaryAmount amount={item.usdt} />
                                            </td>
                                        </tr>
                                    ))}
                                    <tr className="font-semibold">
                                        <td className="p-2">{t('Total')}</td>
                                        <td />
                                        <td className="p-2">
                                            <SummaryAmount amount={funds.estimate} />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p className="mt-3 text-xs text-muted-foreground">
                            {t(
                                'Wallet valuation includes held funds once. Card balances include overflow; USD is valued at 1 USDT.',
                            )}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {t('Valuation time')}: {dateTime(funds.updatedAt ?? funds.readAt)}
                        </p>
                    </>
                ) : view === 'cards' && cards ? (
                    <>
                        <div className="overflow-x-auto">
                            <table className="w-full whitespace-nowrap text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        {[
                                            'Card / Last four',
                                            'Total funding',
                                            'Net consumption including fees',
                                            'Current balance',
                                        ].map((label) => (
                                            <th className="p-2" key={label}>
                                                {t(label)}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {cards.items.map((card) => (
                                        <tr key={card.id} className="border-b">
                                            <td className="p-2">
                                                {card.name} · {card.last4}
                                                <div className="text-xs text-muted-foreground">
                                                    {card.updated_at
                                                        ? dateTime(card.updated_at)
                                                        : t('Balance cache unavailable')}
                                                </div>
                                            </td>
                                            <td className="p-2">
                                                <SummaryAmount amount={card.funded} />
                                            </td>
                                            <td className="p-2">
                                                <SummaryAmount amount={card.consumed} />
                                            </td>
                                            <td className="p-2">
                                                <SummaryAmount amount={card.balance} />
                                            </td>
                                        </tr>
                                    ))}
                                    {!cards.items.length && (
                                        <tr>
                                            <td className="p-4" colSpan={4}>
                                                {t('No cards')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t font-semibold">
                                        <td className="p-2">
                                            {t('All cards in selected scope')} ({cards.total})
                                        </td>
                                        <td className="p-2">
                                            <SummaryAmount amount={cards.totals.funded} />
                                        </td>
                                        <td className="p-2">
                                            <SummaryAmount amount={cards.totals.consumed} />
                                        </td>
                                        <td className="p-2">
                                            <SummaryAmount amount={cards.totals.balance} />
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <p className="mt-3 text-xs text-muted-foreground">
                            {t(
                                'Estimated net usage including fees = total funding − successful returns to wallet − current balance. Negative values are retained.',
                            )}
                        </p>
                        <AdminPagination
                            page={page}
                            pages={Math.max(1, Math.ceil(cards.total / 20))}
                            onPage={setPage}
                        />
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
