import { systemMoney } from '@/lib/system-money';
import { CreditCard, LoaderCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { t, dateTime, useClientTranslation } from '@/i18n';
import { useCardTransactions } from '@/hooks/useCardTransactions';
import { transactionAmount, transactionStates, transactionTitles } from '@/lib/card-transactions';

export function UserCardTransactions({
    cardIds,
    available,
    singleCard = false,
}: {
    cardIds: string[];
    available: boolean;
    singleCard?: boolean;
}) {
    useClientTranslation();
    const history = useCardTransactions(cardIds, available);
    const unavailable =
        cardIds.length > 0 && !available && history.items.length === 0 && !history.loading;
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
            {unavailable ? (
                <p
                    className="rounded-2xl bg-surface px-5 py-8 text-center text-sm text-muted-foreground"
                    role="status"
                >
                    {t('Card transactions are temporarily unavailable.')}
                </p>
            ) : (
                <>
                    {history.failed > 0 && (
                        <div
                            className="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                            role="status"
                        >
                            <p>
                                {t(
                                    'Card transactions could not be updated. Please try again later.',
                                )}
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
                    {history.items.length > 0 && (
                        <div className="divide-y rounded-2xl bg-surface px-4 sm:px-6">
                            {history.items.map((item) => {
                                const localMock = item.merchant === 'TEST / LOCAL MOCK';
                                const cardTitle = `${t('Card ending in {{last4}}', { last4: item.last4 })} · ${t(transactionTitles[item.type] ?? 'Card transaction')}`;
                                return (
                                    <div key={item.id} className="flex min-w-0 gap-3 py-5">
                                        <span className="grid size-9 shrink-0 place-items-center rounded-full bg-muted">
                                            <CreditCard className="size-4" aria-hidden="true" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                                                <div className="min-w-0 flex-1 basis-28">
                                                    <p className="break-words text-sm font-medium">
                                                        {localMock
                                                            ? cardTitle
                                                            : item.merchant ||
                                                              t(
                                                                  transactionTitles[item.type] ??
                                                                      'Card transaction',
                                                              )}
                                                    </p>
                                                    {!localMock && (
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            {t('Card ending in {{last4}}', {
                                                                last4: item.last4,
                                                            })}
                                                            {item.merchant
                                                                ? ` · ${t(transactionTitles[item.type] ?? 'Card transaction')}`
                                                                : ''}
                                                        </p>
                                                    )}
                                                </div>
                                                <div className="max-w-full text-right">
                                                    <p className="break-all text-sm font-semibold tabular-nums">
                                                        {item.currency === 'USD'
                                                            ? systemMoney(item.amount)
                                                            : `${transactionAmount(item.amount)} ${item.currency}`}
                                                    </p>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {t(
                                                            transactionStates[item.state] ??
                                                                'Confirming',
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                            <time
                                                dateTime={item.displayAt}
                                                className="mt-2 block text-xs text-muted-foreground"
                                            >
                                                {t(
                                                    item.timeKind === 'completed'
                                                        ? 'Completed time'
                                                        : 'Recorded time',
                                                )}
                                                : {dateTime(item.displayAt)}
                                            </time>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
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
                        <Button
                            variant="secondary"
                            className="mt-4 w-full"
                            onClick={history.loadMore}
                        >
                            {t('Load more transactions')}
                        </Button>
                    )}
                </>
            )}
        </section>
    );
}
