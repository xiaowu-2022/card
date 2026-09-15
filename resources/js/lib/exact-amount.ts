// Presentation only: remove insignificant zeros without converting money to a JS number.
export function exactAmount(amount: string): string {
    const [integer = '0', fraction = ''] = amount.split('.');
    const trimmed = fraction.replace(/0+$/, '');
    return trimmed ? `${integer}.${trimmed}` : integer;
}

// Form validation only. Compare exact strings; the server always rechecks its current minimum.
export function meetsTopupMinimum(amount: string, minimum: string): boolean {
    if (!/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/.test(amount)) return false;
    if (!/^\d+(?:\.\d{1,8})?$/.test(minimum)) return false;
    const units = (value: string) => {
        const [integer, fraction = ''] = value.split('.');
        return BigInt(integer!) * 100000000n + BigInt(fraction.padEnd(8, '0'));
    };
    return units(amount) > 0n && units(amount) >= units(minimum);
}

// Exact validation for the two-decimal withdrawal form; never clamp insufficient funds to zero.
export function withdrawalRemainder(amount: string, available: string): string | null {
    if (!/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/.test(amount)) return null;
    if (!/^\d{1,12}(?:\.\d{1,8})?$/.test(available)) return null;
    const units = (value: string) => {
        const [integer, fraction = ''] = value.split('.');
        return BigInt(integer!) * 100000000n + BigInt(fraction.padEnd(8, '0'));
    };
    const requested = units(amount);
    const remaining = units(available) - requested;
    if (requested <= 0n || remaining < 0n) return null;
    const digits = remaining.toString().padStart(9, '0');
    return `${digits.slice(0, -8)}.${digits.slice(-8)}`;
}

// Presentation quote only. Server settings and the immutable order remain authoritative.
export function withdrawalReceiveAmount(amount: string, fee: string): string | null {
    if (!/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/.test(amount)) return null;
    if (!/^\d{1,12}(?:\.\d{1,8})?$/.test(fee)) return null;
    const units = (value: string) => {
        const [integer, fraction = ''] = value.split('.');
        return BigInt(integer!) * 100000000n + BigInt(fraction.padEnd(8, '0'));
    };
    const net = units(amount) - units(fee);
    if (net <= 0n) return null;
    const digits = net.toString().padStart(9, '0');
    return `${digits.slice(0, -8)}.${digits.slice(-8)}`;
}

// Display estimate only: the provider-confirmed card balance remains authoritative.
export function cardReloadBalance(amount: string, balance: string | null): string | null {
    if (!/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/.test(amount)) return null;
    if (balance === null || !/^-?\d{1,12}(?:\.\d{1,8})?$/.test(balance)) return null;
    const units = (value: string) => {
        const negative = value.startsWith('-');
        const [integer, fraction = ''] = value.replace(/^-/, '').split('.');
        const result = BigInt(integer!) * 100000000n + BigInt(fraction.padEnd(8, '0'));
        return negative ? -result : result;
    };
    const added = units(amount);
    if (added <= 0n) return null;
    const total = units(balance) + added;
    const digits = (total < 0n ? -total : total).toString().padStart(9, '0');
    return `${total < 0n ? '-' : ''}${digits.slice(0, -8)}.${digits.slice(-8)}`;
}

// Two-decimal presentation, rounded half-up using integer arithmetic only.
// Never use this value for posting, provider requests, or unchanged form submissions.
export function displayMoney(amount: string): string {
    const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(amount);
    if (!match) return amount;
    const fraction = match[3] ?? '';
    const cents =
        BigInt(match[2]!) * 100n +
        BigInt(fraction.padEnd(2, '0').slice(0, 2)) +
        ((fraction[2] ?? '0') >= '5' ? 1n : 0n);
    const sign = match[1] === '-' && cents !== 0n ? '-' : '';
    return `${sign}${cents / 100n}.${(cents % 100n).toString().padStart(2, '0')}`;
}
