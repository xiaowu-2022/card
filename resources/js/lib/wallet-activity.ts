import type { UserActivityItem } from '@/components/user/UserActivityList';
import type { MoneyAmount } from '@/types/global';

export type WalletActivity = {
    reference?: string | null;
    state?: string;
    steps?: { id: string; eventType: string; amount: string; postedAt: string }[];
    id: string;
    eventType: string;
    asset: string;
    amount?: MoneyAmount;
    postedAt: string;
    transferId?: string | null;
};

export function walletActivityItems(entries: WalletActivity[]): UserActivityItem[] {
    const labels: Record<string, string> = {
        WALLET_TOPUP_CREDIT: 'Wallet top up',
        SECURITY_DEPOSIT_FUND: 'Security deposit',
        WITHDRAWAL_HOLD: 'Withdrawal requested',
        WITHDRAWAL_RELEASE: 'Withdrawal returned',
        WITHDRAWAL_SETTLE: 'Withdrawal completed',
        CARD_ISSUE_FEE_HOLD: 'Card opening fee reserved',
        CARD_INITIAL_LOAD_HOLD: 'Initial card funding reserved',
        CARD_ISSUE_FEE_RELEASE: 'Card opening fee returned',
        CARD_INITIAL_LOAD_RELEASE: 'Initial card funding returned',
        CARD_ISSUE_FEE_SETTLE: 'Opening fee',
        CARD_INITIAL_LOAD_SETTLE: 'Card funding',
        CARD_LOAD_HOLD: 'Card reload reserved',
        CARD_LOAD_SETTLE: 'Card reload completed',
        CARD_LOAD_RELEASE: 'Card reload returned',
        CARD_RETURN_SETTLE: 'Card balance returned',
        CARD_CANCEL_RETURN_SETTLE: 'Card balance returned',
        PROMOTION_ANNUAL_FEE: 'Promotion annual fee paid',
        PROMOTION_FEE_REBATE: 'Annual fee returned',
        COMMISSION_TRANSFER: 'Balance transfer received',
        COMMISSION_EARN: 'Activation commission',
        PROMOTION_ANNUAL_COMMISSION: 'Annual fee commission',
        COMMISSION_BALANCE_CONSOLIDATED: 'Commission credited to USDT',
        SECURITY_DEPOSIT_REFUND: 'Security deposit refunded',
    };
    return entries.map((entry) => ({
        id: entry.id,
        reference: entry.reference,
        state: entry.state,
        steps: entry.steps?.map((step) => ({
            ...step,
            title: labels[step.eventType] ?? 'Wallet activity',
        })),
        asset: entry.asset,
        amount: entry.amount,
        postedAt: entry.postedAt,
        href:
            entry.eventType === 'WALLET_TRANSFER' && entry.transferId
                ? `/wallet/transfers/${entry.transferId}`
                : undefined,
        title:
            entry.eventType === 'WALLET_TRANSFER'
                ? entry.amount?.startsWith('-')
                    ? 'Transfer sent'
                    : 'Transfer received'
                : (labels[entry.eventType] ?? 'Wallet activity'),
        direction: entry.amount?.startsWith('-') ? 'DEBIT' : entry.amount ? 'CREDIT' : 'NEUTRAL',
    }));
}
