// Admin presentation only. Keep all significant digits without floating-point rounding.
// Do not apply to identifiers, persisted values, or accounting calculations.
export function displayMoney(value: string): string {
    const match = /^([+-]?)(\d+)(?:\.(\d+))?$/.exec(value);
    if (!match) return value;
    const fraction = (match[3] ?? '').replace(/0+$/, '');
    const whole = match[2]!;
    const sign = /^0+$/.test(whole) && !fraction ? '' : match[1];
    return `${sign}${whole}${fraction ? `.${fraction}` : ''}`;
}
