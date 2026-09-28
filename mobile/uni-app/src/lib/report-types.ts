import type {
    ReportPeriod,
    ReportFilters,
    IncomeTotals,
    MembershipStatus,
} from './promotion-report';
export type TeamSubject = { id: string | null; accountId: string; displayName: string | null };
export type MemberData = {
    id: string;
    accountId: string;
    displayName: string | null;
    maskedEmail: string | null;
    rank: number;
    membershipStatus: MembershipStatus;
    joinedAt: string;
    endsAt: string | null;
    totals: IncomeTotals;
};
export type Movement = {
    id: string;
    kind: string;
    sourceAccountId: string;
    relation: string;
    sourceRank: number | null;
    sourceAmount: string | null;
    amount: string;
    occurredAt: string;
    postedAt: string | null;
    firstFunding: boolean;
    purchaseKind: string | null;
};
export type Member = MemberData & {
    relation: 'direct' | 'indirect';
    depositAmount: string;
    teamSize: number;
};
export type Report = ReportPeriod & {
    subject?: TeamSubject;
    breadcrumbs?: TeamSubject[];
    subjectTotals?: IncomeTotals;
    memberCounts?: { direct: number; total: number };
    filters: ReportFilters;
    page: number;
    hasMore: boolean;
    totals?: IncomeTotals;
    counts?: { invited: number; funded: number; orders: number };
    total?: number;
    items: (Movement | Member)[];
};
export type CommissionHistory = ReportPeriod & {
    subject?: TeamSubject;
    breadcrumbs?: TeamSubject[];
    filters: ReportFilters;
    page: number;
    hasMore: boolean;
    totals: IncomeTotals;
    items: {
        id: string;
        kind: string;
        amount: string;
        occurredAt: string;
        sourceAccountId?: string;
        sourceRank?: number | null;
        beneficiaryRank?: number | null;
        relation?: string;
        sourceAmount?: string;
        rate?: string | null;
        standard?: number | null;
        covered?: number | null;
        businessAt?: string;
    }[];
};
