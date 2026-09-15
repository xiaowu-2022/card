import { displayMoney } from './exact-amount';

/** Presentation only; principal assets and decimal values are unchanged. */
export function systemMoney(amount: string): string {
    const value = displayMoney(amount);
    const negative = value.startsWith('-');
    const [integer = '0', fraction = '00'] = (negative ? value.slice(1) : value).split('.');
    return `${negative ? '-' : ''}$${integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${fraction}`;
}
