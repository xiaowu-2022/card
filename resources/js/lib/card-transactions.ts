export const transactionTitles: Record<string, string> = {
    purchase: 'Card purchase',
    verification: 'Card verification',
    reversal: 'Transaction reversal',
    refund: 'Card refund',
    transfer_in: 'Card funding',
    transfer_out: 'Card balance return',
    fee: 'Card fee',
    cash_withdrawal: 'Cash withdrawal',
    balance_inquiry: 'Balance inquiry',
    adjustment: 'Transaction adjustment',
    other: 'Card transaction',
};
export const transactionStates: Record<string, string> = {
    completed: 'Completed',
    authorized: 'Authorized',
    declined: 'Declined',
    reversed: 'Reversed',
    pending: 'Pending',
    confirming: 'Confirming',
};
export type CardTransaction = {
    id: string;
    cardId: string;
    last4: string;
    amount: string;
    currency: string;
    type: string;
    state: string;
    displayAt: string;
    timeKind: 'completed' | 'recorded';
    merchant: string | null;
    operator?: string | null;
    note?: string | null;
};
export type CardTransactionPage = { items: CardTransaction[]; page: number; hasMore: boolean };

export function transactionPage(value: unknown, cardId: string, page: number): CardTransactionPage {
    if (!value || typeof value !== 'object') throw new Error('Invalid transaction page');
    const data = value as Partial<CardTransactionPage>;
    if (
        data.page !== page ||
        typeof data.hasMore !== 'boolean' ||
        !Array.isArray(data.items) ||
        data.items.length > 20
    )
        throw new Error('Invalid transaction page');
    for (const item of data.items) {
        if (
            !item ||
            item.cardId !== cardId ||
            typeof item.id !== 'string' ||
            !/^[a-f0-9]{64}$/.test(item.id) ||
            typeof item.last4 !== 'string' ||
            !/^\d{4}$/.test(item.last4) ||
            typeof item.amount !== 'string' ||
            !/^-?\d{1,12}\.\d{8}$/.test(item.amount) ||
            typeof item.currency !== 'string' ||
            !/^[A-Z]{3}$/.test(item.currency) ||
            typeof item.displayAt !== 'string' ||
            !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/.test(item.displayAt) ||
            !Number.isFinite(Date.parse(item.displayAt)) ||
            !['completed', 'recorded'].includes(item.timeKind) ||
            !Object.hasOwn(transactionTitles, item.type) ||
            !Object.hasOwn(transactionStates, item.state) ||
            (item.merchant !== null && typeof item.merchant !== 'string')
        )
            throw new Error('Invalid transaction item');
    }
    return data as CardTransactionPage;
}

export function mergeCardTransactions(
    previous: CardTransaction[],
    incoming: CardTransaction[],
): CardTransaction[] {
    const byId = new Map(previous.map((item) => [item.id, item]));
    for (const item of incoming) byId.set(item.id, item);
    return [...byId.values()].sort(
        (a, b) => Date.parse(b.displayAt) - Date.parse(a.displayAt) || a.id.localeCompare(b.id),
    );
}

export { displayMoney as transactionAmount } from './exact-amount';
