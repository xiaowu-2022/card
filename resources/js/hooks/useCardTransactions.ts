import { useEffect, useRef, useState } from 'react';
import {
    mergeCardTransactions,
    transactionPage,
    type CardTransaction,
} from '@/lib/card-transactions';

type View = { items: CardTransaction[]; loading: boolean; failed: number; hasMore: boolean };

export function useCardTransactions(cardIds: string[], available: boolean) {
    const cardKey = [...cardIds].sort().join(',');
    const [view, setView] = useState<View>({
        items: [],
        loading: cardIds.length > 0,
        failed: 0,
        hasMore: false,
    });
    const actions = useRef<{ more: () => void; retry: () => void }>({
        more: () => {},
        retry: () => {},
    });

    useEffect(() => {
        const cards = cardKey
            ? cardKey.split(',').map((id) => ({
                  id,
                  local: { page: 1, more: true, failed: false },
                  sync: { page: 1, more: available, failed: false },
              }))
            : [];
        const controller = new AbortController();
        let items: CardTransaction[] = [];
        let busy = false;
        const publish = () => {
            if (!controller.signal.aborted)
                setView({
                    items,
                    loading: busy,
                    failed: cards.filter((card) => card.local.failed || card.sync.failed).length,
                    hasMore: cards.some((card) =>
                        [card.local, card.sync].some((source) => source.more && !source.failed),
                    ),
                });
        };
        const run = async (failedOnly: boolean) => {
            if (busy || controller.signal.aborted) return;
            busy = true;
            const queue = cards.filter((card) =>
                [card.local, card.sync].some((source) =>
                    failedOnly ? source.failed : source.more && !source.failed,
                ),
            );
            publish();
            await Promise.all(
                Array.from({ length: Math.min(3, queue.length) }, async () => {
                    while (queue.length && !controller.signal.aborted) {
                        const card = queue.shift()!;
                        for (const kind of ['local', 'sync'] as const) {
                            const source = card[kind];
                            if (failedOnly ? !source.failed : !source.more || source.failed)
                                continue;
                            try {
                                const token = document.cookie
                                    .split('; ')
                                    .find((value) => value.startsWith('XSRF-TOKEN='))
                                    ?.slice(11);
                                const response = await fetch(
                                    kind === 'sync'
                                        ? `/cards/${card.id}/transactions/sync`
                                        : `/cards/${card.id}/transactions?page=${source.page}`,
                                    {
                                        method: kind === 'sync' ? 'POST' : 'GET',
                                        headers: {
                                            Accept: 'application/json',
                                            ...(kind === 'sync'
                                                ? {
                                                      'Content-Type': 'application/json',
                                                      'X-XSRF-TOKEN': decodeURIComponent(
                                                          token ?? '',
                                                      ),
                                                  }
                                                : {}),
                                        },
                                        ...(kind === 'sync'
                                            ? { body: JSON.stringify({ page: source.page }) }
                                            : {}),
                                        credentials: 'same-origin',
                                        cache: 'no-store',
                                        signal: AbortSignal.any([
                                            controller.signal,
                                            AbortSignal.timeout(30000),
                                        ]),
                                    },
                                );
                                if (!response.ok) throw new Error('Transactions unavailable');
                                const data = transactionPage(
                                    (await response.json()) as unknown,
                                    card.id,
                                    source.page,
                                );
                                if (controller.signal.aborted) return;
                                items = mergeCardTransactions(items, data.items);
                                source.page++;
                                source.more = data.hasMore;
                                source.failed = false;
                            } catch {
                                if (controller.signal.aborted) return;
                                source.failed = true;
                            }
                            publish();
                        }
                    }
                }),
            );
            busy = false;
            publish();
        };
        actions.current = {
            more: () => {
                void run(false);
            },
            retry: () => {
                void run(true);
            },
        };
        void run(false);
        return () => {
            controller.abort();
        };
    }, [cardKey, available]);

    return {
        ...view,
        loadMore: () => actions.current.more(),
        retry: () => actions.current.retry(),
    };
}
