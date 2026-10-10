/** Display copy only: persisted assets, form values and requests retain ISO/provider codes. */
export function adminAssetLabel(value: string): string {
    return value.replace(/\bUSDT\b/g, 'U');
}
