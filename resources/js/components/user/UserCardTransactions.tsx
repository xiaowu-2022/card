import { CreditCard, LoaderCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { t, dateTime, dateLabel, useClientTranslation } from '@/i18n';
import { useCardTransactions } from '@/hooks/useCardTransactions';
import { transactionMoney, transactionStates, transactionTitles, transactionSections } from '@/lib/card-transactions';

export function UserCardTransactions({
    cardIds,
    singleCard = false,
}: {
    cardIds: string[];
    singleCard?: boolean;
}) {
    useClientTranslation();
    const history = useCardTransactions(cardIds);
    return (
        <section
            className="user-card-transactions min-w-0"
            aria-label={singleCard ? t('Card transactions') : undefined}
            aria-labelledby={singleCard ? undefined : 'all-card-transactions'}
        >
            {!singleCard && (
                <h2 id="all-card-transactions" className="mb-4 text-lg font-semibold">
                    {t('All card transactions')}
                </h2>
            )}
            <>
                {history.failed > 0 && (
                    <div
                        className="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                        role="status"
                    >
                        <p>
                            {t('Card transactions could not be updated. Please try again later.')}
                        </p>
                        <Button
                            variant="secondary"
                            size="sm"
                            className="mt-3"
                            disabled={history.loading}
                            onClick={history.retry}
                        >
                            {t('Retry')}
                        </Button>
                    </div>
                )}
                {transactionSections(history.items, dateLabel).map(section => (
                    <div key={section.day} className="mb-5">
                        <div className="mb-2 flex items-center justify-between px-1 text-xs text-muted-foreground">
                            <span>{section.day}</span>
                            <span>{t('{{count}} transactions', { count: section.items.length })}</span>
                        </div>
                        <div className="divide-y rounded-2xl bg-surface px-4 sm:px-6">
                            {section.items.map(item => {
                                const ending = item.last4 ? t('Card ending in {{last4}}', { last4: item.last4 }) : t('Card');
                                const title = ending + ' · ' + t(transactionTitles[item.type] ?? 'Card transaction');
                                const subtitle = item.merchant && item.merchant !== 'TEST / LOCAL MOCK' ? item.merchant : dateTime(item.displayAt);
                                return (
                                    <details key={item.id} className="group py-4">
                                        <summary className="flex cursor-pointer list-none items-center gap-3 [&::-webkit-details-marker]:hidden">
                                            <span className="grid size-9 shrink-0 place-items-center rounded-full bg-muted"><CreditCard className="size-4" aria-hidden="true" /></span>
                                            <span className="min-w-0 flex-1">
                                                <span className="flex flex-wrap justify-between gap-2 text-sm font-medium"><span className="break-words">{title}</span><span className="tabular-nums">{transactionMoney(item.amount, item.currency)}</span></span>
                                                <span className="mt-1 flex justify-between gap-2 text-xs text-muted-foreground"><span>{subtitle}</span><span>{t(transactionStates[item.state] ?? 'Confirming')}</span></span>
                                            </span>
                                            <span aria-hidden="true" className="shrink-0 text-muted-foreground transition-transform group-open:rotate-90">›</span>
                                        </summary>
                                        <dl className="mt-4 space-y-3 border-t pt-4 text-xs">
                                            <div className="flex justify-between gap-4"><dt>{t('Transaction type')}</dt><dd>{t(transactionTitles[item.type] ?? 'Card transaction')}</dd></div>
                                            <div className="flex justify-between gap-4"><dt>{t(item.timeKind === 'completed' ? 'Completion time' : 'Recorded time')}</dt><dd><time dateTime={item.displayAt}>{dateTime(item.displayAt)}</time></dd></div>
                                            <div className="flex justify-between gap-4"><dt>{t('Transaction fee')}</dt><dd>{item.feeAmount != null && item.feeCurrency ? transactionMoney(item.feeAmount, item.feeCurrency) : '—'}</dd></div>
                                            <div className="flex justify-between gap-4"><dt>{t('Fee refund')}</dt><dd>{item.feeReturnAmount != null && item.feeReturnCurrency ? transactionMoney(item.feeReturnAmount, item.feeReturnCurrency) : '—'}</dd></div>
                                            {item.note && <div className="flex justify-between gap-4"><dt>{t('Transaction note')}</dt><dd>{item.note}</dd></div>}
                                        </dl>
                                    </details>
                                );
                            })}
                        </div>
                    </div>
                ))}
                {!history.loading && history.failed === 0 && history.items.length === 0 && (
                    <div className="rounded-2xl bg-surface px-5 py-8 text-center" role="status">
                        <CreditCard
                            className="mx-auto mb-3 size-6 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="text-sm font-medium">{t('No card transactions yet')}</p>
                        {!singleCard && (
                            <p className="mt-2 text-xs text-muted-foreground">
                                {t('Transactions from all your cards will appear here.')}
                            </p>
                        )}
                    </div>
                )}
                {history.loading && (
                    <p
                        className="flex items-center justify-center gap-2 py-6 text-sm text-muted-foreground"
                        role="status"
                    >
                        <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />
                        {t('Loading card transactions...')}
                    </p>
                )}
                {!history.loading && history.hasMore && (
                    <Button variant="secondary" className="mt-4 w-full" onClick={history.loadMore}>
                        {t('Load more transactions')}
                    </Button>
                )}
            </>
        </section>
    );
}
