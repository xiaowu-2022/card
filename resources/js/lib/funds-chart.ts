export type FundsDay = { date: string; inflow?: string; outflow?: string; net?: string };
export type FundsKey = 'inflow' | 'outflow' | 'net';
export const fundsLabels = {
    inflow: 'Inflow (top-ups)',
    outflow: 'Outflow (withdrawals)',
    net: 'Retained funds',
};

// Reserve space for both endpoints instead of appending the final date next to
// an already-rendered tick. Coordinates and spacing are display values only.
export function chartDateLabelIndices(dayCount: number, plotWidth: number): number[] {
    if (dayCount <= 0) return [];
    if (dayCount === 1) return [0];
    const minimumIndexGap = Math.max(1, Math.ceil(64 / (plotWidth / dayCount)));
    const labelCount = Math.min(8, Math.floor((dayCount - 1) / minimumIndexGap) + 1);
    if (labelCount < 2) return [dayCount - 1];
    return Array.from({ length: labelCount }, (_, index) =>
        Math.round((index * (dayCount - 1)) / (labelCount - 1)),
    );
}

// Money stays in exact integer units. Only a bounded, dimensionless SVG position
// is converted to Number; chart coordinates are never reused as monetary values.
export function chartUnits(amount: string): bigint {
    const match = /^(-?)(\d+)(?:\.(\d{1,8}))?$/.exec(amount);
    if (!match) throw new Error('Invalid chart decimal');
    const units = BigInt(match[2]!) * 100000000n + BigInt((match[3] ?? '').padEnd(8, '0'));
    return match[1] ? -units : units;
}

export function chartDecimal(units: bigint): string {
    const sign = units < 0n ? '-' : '';
    const digits = (units < 0n ? -units : units).toString().padStart(9, '0');
    return `${sign}${digits.slice(0, -8)}.${digits.slice(-8)}`;
}

export function fundsChartScale(amounts: string[]) {
    const values = amounts.map(chartUnits);
    const minimum = values.reduce((a, b) => (a < b ? a : b), 0n);
    let maximum = values.reduce((a, b) => (a > b ? a : b), 0n);
    if (minimum === maximum) maximum = minimum + 100000000n;
    const range = maximum - minimum;
    return {
        ticks: [0n, 1n, 2n, 3n, 4n].map((i) => chartDecimal(maximum - (range * i) / 4n)),
        position: (amount: string) =>
            Number(((maximum - chartUnits(amount)) * 10000n) / range) / 10000,
    };
}
