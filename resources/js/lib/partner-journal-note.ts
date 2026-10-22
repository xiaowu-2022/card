// Localize the exact system-generated receipt format without rewriting stored notes.
export function partnerJournalNote(
    note: string,
    translate: (key: string, values: Record<string, string>) => string,
): string {
    const receipt =
        /^(?:Manual deposit|Deposit receipt): (?:wallet_topup_orders|asset_deposit_orders)\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i.exec(
            note,
        );
    const id = receipt?.[1];
    return id
        ? translate(
              note.startsWith('Deposit receipt:')
                  ? 'Deposit receipt · Order ID: {{id}}'
                  : 'Manual deposit · Order ID: {{id}}',
              { id },
          )
        : note;
}

export function partnerJournalKind(kind: string): string {
    return (
        (
            {
                ADVANCE: 'Advance',
                ANNUAL_SUBSIDY: 'Annual fee subsidy',
                REIMBURSEMENT: 'Reimbursement',
                ADJUSTMENT_INCREASE: 'Increase theoretical balance',
                ADJUSTMENT_DECREASE: 'Decrease theoretical balance',
            } as Record<string, string>
        )[kind] ?? kind
    );
}

export function partnerJournalSign(kind: string, reversal: string | null): string {
    if (!kind.startsWith('ADJUSTMENT_')) return '';
    const negative = (kind === 'ADJUSTMENT_DECREASE') !== Boolean(reversal);
    return negative ? '−' : kind.startsWith('ADJUSTMENT_') ? '+' : '';
}

// Ordinary cooperation amounts carry their own sign; reversals invert it exactly.
export function partnerJournalAmount(
    kind: string,
    amount: string,
    reversal: string | null,
): string {
    if (kind.startsWith('ADJUSTMENT_') || !reversal) return amount;
    return amount.startsWith('-') ? amount.slice(1) : `-${amount}`;
}
