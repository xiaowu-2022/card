export type MovementDetails = {
    reason: string;
    reference: string | null;
    counterparty: { role: 'recipient' | 'sender'; accountId: string | null; email: string | null } | null;
};
export type FundsMovement = { id: string; kind: string; amount: string; time: string; details?: MovementDetails };
export type AssetAccount = {
    asset: string;
    available: string;
    held: string;
    deposit: string;
    exchange: boolean;
    exchangeUnavailableReason?: string | null;
    transfer?: boolean;
    rails: {
        code: string;
        network: string;
        deposit: boolean;
        withdrawal: boolean;
        feePercent: string | null;
        minimum: string | null;
    }[];
    activity: FundsMovement[];
    orders?: { id: string; mode: string; amount: string; state: string; time: string }[];
};
export type AssetOverview = {
    activation: {
        qualified: boolean;
        agent: boolean;
        rank: number;
        endsAt: string | null;
        ordinaryAvailable: boolean;
        depositSatisfied: boolean;
        depositRequired: string;
        depositCurrent: string;
        depositRemaining: string;
        refundPending: boolean;
    };
    cumulativeCommission: string;
    assets: AssetAccount[];
    estimate: string | null;
    updatedAt: string | null;
};
export function subtract(a: string, b: string) {
    const scale = Math.max(a.split('.')[1]?.length ?? 0, b.split('.')[1]?.length ?? 0);
    const units = (n: string) => {
        const [w, f = ''] = n.split('.');
        return BigInt(w + f.padEnd(scale, '0'));
    };
    const n = units(a) - units(b);
    const negative = n < 0n;
    const digits = (negative ? -n : n).toString().padStart(scale + 1, '0');
    return (
        (negative ? '-' : '') +
        (scale ? digits.slice(0, -scale) + '.' + digits.slice(-scale) : digits)
    );
}
