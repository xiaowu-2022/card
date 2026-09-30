export type CardOperation = {
    id: string;
    kind: string;
    state: string;
    amount: string;
    debit: string | null;
    arrival: string | null;
    fee: string | null;
    expiresAt: string | null;
    createdAt: string;
};
export type ManagedCard = {
    expiry?: string | null;
    balance: string | null;
    pendingOperationCount?: number;
    state?: string;
    refundLocked?: boolean;
    id: string;
    management?: string[];
    minimumReload?: string;
    syncedAt?: string | null;
};
export const cardActionLabels: Record<string, string> = {
    reveal: 'View card information',
    transactions: 'Card transactions',
    holder: 'Edit cardholder',
    load: 'Reload card',
    return: 'Return card balance',
    cancel: 'Cancel card',
    freeze: 'Freeze card',
    unfreeze: 'Unfreeze card',
    history: 'Card operation history',
};
export const cardShortLabels: Record<string, string> = {
    reveal: 'View',
    holder: 'Edit',
    load: 'Reload',
    return: 'Return',
    transactions: 'Transactions',
    cancel: 'Close card',
    unfreeze: 'Unfreeze',
};
export const cardOperationStates: Record<string, string> = {
    quoted: 'Quote ready',
    completed: 'Completed',
    declined: 'Operation declined',
    expired: 'Quote expired',
    confirming: 'Awaiting confirmation',
};

export function record(value: unknown): Record<string, unknown> {
    if (!value || typeof value !== 'object' || Array.isArray(value))
        throw new Error('Awaiting confirmation');
    return value as Record<string, unknown>;
}

export function operation(value: unknown): CardOperation {
    const row = record(value);
    for (const key of ['id', 'kind', 'state', 'amount', 'createdAt']) {
        if (typeof row[key] !== 'string') throw new Error('Awaiting confirmation');
    }
    for (const key of ['debit', 'arrival', 'fee']) {
        if (row[key] !== null && (typeof row[key] !== 'string' || !/^\d+\.\d{8}$/.test(row[key])))
            throw new Error('Awaiting confirmation');
    }
    if (row.expiresAt !== null && typeof row.expiresAt !== 'string')
        throw new Error('Awaiting confirmation');
    return {
        id: row.id as string,
        kind: row.kind as string,
        state: row.state as string,
        amount: row.amount as string,
        createdAt: row.createdAt as string,
        debit: row.debit as string | null,
        arrival: row.arrival as string | null,
        fee: row.fee as string | null,
        expiresAt: row.expiresAt,
    };
}
