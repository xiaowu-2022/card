import { exactAmount } from './exact-amount';

export function transactionMoney(amount: string, currency: string): string {
    const value = exactAmount(amount);
    const symbol = currency === 'USD' ? '$' : ['CNY', 'RMB'].includes(currency) ? '￥' : null;
    if (!symbol) return `${value} ${currency}`;
    return value.startsWith('-') ? `-${symbol}${value.slice(1)}` : `${symbol}${value}`;
}

export const transactionTitles: Record<string, string> = {
    purchase: 'Card purchase',
    authorization: 'Card authorization',
    fund_collection: 'Card fund collection',
    atm_deposit: 'Card ATM deposit',
    settlement_debit: 'Card settlement debit',
    settlement_credit: 'Card settlement credit',
    settlement_reversal: 'Card settlement reversal',
    chargeback_debit: 'Card chargeback debit',
    chargeback_credit: 'Card chargeback credit',
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
    last4: string | null;
    amount: string;
    currency: string;
    feeAmount?: string | null;
    feeCurrency?: string | null;
    feeReturnAmount?: string | null;
    feeReturnCurrency?: string | null;
    type: string;
    state: string;
    displayAt: string;
    timeKind: 'completed' | 'recorded';
    merchant: string | null;
    operator?: string | null;
    note?: string | null;
};
export type CardTransactionPage = { items: CardTransaction[]; page: number; hasMore: boolean };

export class TransactionFeeError extends Error {
    constructor(public field: 'feeAmount' | 'feeReturnAmount', public detail: string) {
        super('Invalid transaction fee');
    }
}

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
        for (const { amount, currency, field } of [
            { amount: item?.feeAmount, currency: item?.feeCurrency, field: 'feeAmount' as const },
            { amount: item?.feeReturnAmount, currency: item?.feeReturnCurrency, field: 'feeReturnAmount' as const },
        ]) {
            if (amount == null && currency == null) continue;
            const detail = amount == null ? 'amount_missing'
                : typeof amount !== 'string' || !/^-?\d{1,12}\.\d{8}$/.test(amount) ? 'invalid_amount'
                : currency == null ? 'currency_missing'
                : typeof currency !== 'string' || !/^[A-Z]{3}$/.test(currency) ? 'invalid_currency' : null;
            if (detail) throw new TransactionFeeError(field, detail);
        }
        if (
            !item ||
            item.cardId !== cardId ||
            typeof item.id !== 'string' ||
            !/^[a-f0-9]{64}$/.test(item.id) ||
            (item.last4 !== null && (typeof item.last4 !== 'string' || !/^\d{4}$/.test(item.last4))) ||
            typeof item.amount !== 'string' ||
            !/^-?\d{1,12}\.\d{8}$/.test(item.amount) ||
            typeof item.currency !== 'string' ||
            !/^[A-Z]{3}$/.test(item.currency) ||
            typeof item.displayAt !== 'string' ||
            !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/.test(item.displayAt) ||
            !Number.isFinite(Date.parse(item.displayAt)) ||
            !['completed', 'recorded'].includes(item.timeKind) ||
            !Object.prototype.hasOwnProperty.call(transactionTitles, item.type) ||
            !Object.prototype.hasOwnProperty.call(transactionStates, item.state) ||
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

/** Date sections are presentation only: distinct transaction IDs never coalesce. */
export function transactionSections(items: CardTransaction[], day: (value: string) => string) {
    const sections: { day: string; items: CardTransaction[] }[] = [];
    for (const item of mergeCardTransactions([], items)) {
        const label = day(item.displayAt);
        let section = sections.find(section => section.day === label);
        if (!section) { section = { day: label, items: [] }; sections.push(section); }
        section.items.push(item);
    }
    return sections;
}
