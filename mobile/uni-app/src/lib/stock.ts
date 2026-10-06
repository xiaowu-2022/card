export type PartnerIdentity = { id: string; name: string; accountId: string };
export type PartnerChildren = StockPage<PartnerIdentity & { email: string | null; stock: string; teamCount: number }> & {
    subject: PartnerIdentity;
    listPath: string;
};
export type StockPage<T> = { items: T[]; page: number; total: number; hasMore: boolean };
export type JournalRow = {
    id: string;
    partner_id: string;
    kind: string;
    amount: string;
    business_date: string;
    note: string;
    reverses_id: string | null;
    reversed: boolean;
    actor_id: string;
    created_at: string;
    account_id: string;
};
type Risk = {
    id: string;
    account_id: string;
    rank: number;
    target: number;
    weighted: string;
    remaining: string;
    pending: string;
    ends_at: string;
};
export type StockFlowDetails = StockPage<{
    id: string;
    source: string;
    account_id: string;
    email: string;
    direct_account_id: string | null;
    direct_email: string | null;
    asset_code: string;
    amount: string;
    amountUsdt: string | null;
    posted_at: string | null;
}> & { direction: 'inflow' | 'outflow' };
export type StockReport = {
    subject?: PartnerIdentity;
    reportPath?: string;
    partnersPath?: string;
    flowDetails?: StockFlowDetails | null;
    version: 'partner' | 'standard';
    stockBasis?: 'BUSINESS_CONTRIBUTIONS';
    cashFlow: null | {
        rateObservedAt: string | null;
        assets: {
            asset: string;
            inflow: string;
            outflow: string;
            rate: string | null;
            inflowUsdt: string | null;
            outflowUsdt: string | null;
        }[];
    };
    accountId: string;
    partnerId: string;
    updatedAt: string;
    timezone: string;
    sharePercent: string;
    stock: string | null;
    share: string | null;
    negative: boolean;
    missingRates: number;
    accountBalance: null | {
        advances: string;
        activationCommission: string;
        annualCommission: string;
        unclassifiedCommission: string;
        reimbursements: string;
        theoretical: string;
        actual: string;
        difference: string;
    };
    totals: Record<string, string | null>;
    trends: Record<string, Record<string, string>>;
    risks: {
        activeCount: number;
        remaining: string;
        expiredCount: number;
        expiredAmount: string;
        active: StockPage<Risk>;
        expired: StockPage<Risk>;
    };
    journal: StockPage<JournalRow>;
    unvalued: StockPage<{
        id: string;
        asset_code: string;
        fee_amount: string;
        valuation_id: string | null;
    }>;
};
