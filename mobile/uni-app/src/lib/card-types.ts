export type Product = {
    supportedFormFactors?: string[];
    bin?: string;
    id: string;
    name: string;
    cardType: string;
    cardCurrency: string;
    openingFee: string;
    minimumInitialLoad: string;
    minimumRequiredBalance: string;
    maxCardsPerUser: number;
    readyForSetup: boolean;
    guidance: string;
};
export type Cardholder = {
    formFactor?: string;
    recipient?: { id: string; status: string } | null;
    id: string | null;
    requestId: string | null;
    productId: string | null;
    canSync: boolean;
    state: 'setup' | 'submitting' | 'ready' | 'action_required' | 'not_available' | 'unknown';
    safeReason: string | null;
    submittedAt: string | null;
    syncedAt: string | null;
};
export type IssueOrder = {
    id: string;
    productName: string;
    openingFee: string;
    initialLoadAmount: string;
    state: 'creating' | 'unknown' | 'created' | 'failed';
    requestedAt: string;
};
export type UserCard = {
    activationStatus?: string | null;
    formFactor?: string;
    produceStatus?: string | null;
    trackingNumber?: string | null;
    state?: string;
    pendingOperationCount?: number;
    refundLocked?: boolean;
    id: string;
    productName: string;
    maskedPan: string;
    last4: string;
    expiry: string | null;
    currency: string;
    balance: string | null;
    management?: string[];
    minimumReload?: string;
    syncedAt?: string | null;
};
export type CardsPage = {
    refundPending?: boolean;
    products: Product[];
    providerAvailable: boolean;
    kycApproved: boolean;
    availableBalance: string | null;
    walletAsset: string | null;
    cardholder: Cardholder;
    issueOrders: IssueOrder[];
    cards: UserCard[];
    demo?: boolean;
};

export function minorUnits(value: string): bigint | null {
    const match = /^(\d+)(?:\.(\d{0,8}))?$/.exec(value);
    if (!match) return null;
    return BigInt(match[1]) * 100000000n + BigInt((match[2] ?? '').padEnd(8, '0'));
}
export function moneyFromMinor(value: bigint) {
    return value / 100000000n + '.' + (value % 100000000n).toString().padStart(8, '0');
}
