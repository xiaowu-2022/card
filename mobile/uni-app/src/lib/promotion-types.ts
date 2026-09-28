export type PaidLevel = {
    selectable?: boolean;
    unavailableReason?: string | null;
    id: string;
    rank: number;
    fee: string;
    percent: number;
    reward: string;
    target: number;
    revision: number;
    enabled: boolean;
};
type Cell = { count: number; amount: string; minimum: string; maximum: string };
export type PaidClaim = {
    id: string;
    rank: number;
    amount: string;
    status: string;
    target: number;
    direct: number;
    indirect: number;
    createdAt: string;
    processedAt: string | null;
    source: 'AUTO';
};
export type PaidPromotionData = {
    manualLevel?: boolean;
    upgradeEligibility: {
        weightedUnits: number;
        weightedCount: string;
        highestEnabledRank: number;
        pending: boolean;
    };
    activation: {
        qualified: boolean;
        agent: boolean;
        ordinaryAvailable: boolean;
        depositRequired: string;
        refundPending: boolean;
    };
    paymentAccess: { verified: boolean; walletActive: boolean; canCreateWallet: boolean };
    membershipStatus: 'NONE' | 'ACTIVE' | 'EXPIRED';
    previousCycle: { rank: number; endsAt: string } | null;
    availableBalance: string;
    levels: PaidLevel[];
    rank: number;
    percent: number;
    reward: string;
    cycle: {
        id: string;
        startsAt: string;
        endsAt: string;
        tariff: string;
        rebatePolicy: 'AUTO_FIRST_FUNDING';
    } | null;
    progress: {
        policy: 'AUTO_FIRST_FUNDING';
        pending: boolean;
        direct: number;
        indirect: number;
        target: number;
        paid: string;
        returned: string;
        remaining: string;
    } | null;
    claimsPage: number;
    hasMoreClaims: boolean;
    pending: boolean;
    claims: PaidClaim[];
    tables: Record<'ANNUAL' | 'ACTIVATION', { rank: number; direct: Cell; indirect: Cell }[]>;
    totals: Record<'ANNUAL' | 'ACTIVATION', string>;
    legacy: string;
    teamByLevel: { rank: number; direct: number; indirect: number }[];
    directPeople: number;
    indirectPeople: number;
};
