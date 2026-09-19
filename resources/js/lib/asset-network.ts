export function assetNetworkLabel(network: string | null | undefined, asset: string): string | undefined {
    if (network === 'TRON') return 'TRC20';
    if (network === 'ETHEREUM') return asset === 'ETH' ? 'Ethereum' : 'ERC20';
    if (network === 'BITCOIN') return 'Bitcoin';
    return network ?? undefined;
}
