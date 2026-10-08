// Localize the exact system-generated receipt format without rewriting stored notes.
export function partnerJournalNote(
    note: string,
    translate: (key: string, values: Record<string, string>) => string,
): string {
    const receipt =
        /^Manual deposit: (?:wallet_topup_orders|asset_deposit_orders)\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i.exec(
            note,
        );
    const id = receipt?.[1];
    return id ? translate('Manual deposit · Order ID: {{id}}', { id }) : note;
}

export function partnerJournalKind(kind: string): string {
    return (
        (
            {
                ADVANCE: 'Advance',
                REIMBURSEMENT: 'Reimbursement',
                ADJUSTMENT_INCREASE: 'Increase theoretical balance',
                ADJUSTMENT_DECREASE: 'Decrease theoretical balance',
            } as Record<string, string>
        )[kind] ?? kind
    );
}

export function partnerJournalSign(kind: string, reversal: string | null): string {
    const negative = (kind === 'ADJUSTMENT_DECREASE') !== Boolean(reversal);
    return negative ? '−' : kind.startsWith('ADJUSTMENT_') ? '+' : '';
}
