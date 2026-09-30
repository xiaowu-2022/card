<script setup lang="ts">
import { computed, ref } from 'vue';
import PageShell from '../components/PageShell.vue';
import StatusBanner from '../components/StatusBanner.vue';
import UiIcon from '../components/UiIcon.vue';
import { t, dateTime } from '../lib/i18n';
import { go } from '../lib/navigation';
import { displayMoney, exactAmount } from '../generated/exact-amount';
type Money = { amount: string; asset: string };
type Activity = {
    id: string;
    eventType: string;
    asset: string;
    amount?: string;
    postedAt: string;
    transferId?: string | null;
    reference?: string | null;
    state?: string;
    steps?: { id: string; eventType: string; amount: string; postedAt: string }[];
};
const props = defineProps<{
    page: {
        eligibility: {
            userStatus: string;
            tenantStatus: string;
            kycStatus: string;
            walletStatus: string | null;
            canActivate: boolean;
            depositSatisfied: boolean;
            activation: { agent: boolean };
            activationSatisfied: boolean;
            wallet: { id: string; asset: string; status: string } | null;
            available: Money | null;
            depositCurrent: Money;
            depositRequired: Money;
            depositRemaining: Money;
        };
        activity: Activity[];
        topupAvailable?: boolean;
        depositFundingAvailable?: boolean;
        withdrawalAvailable?: boolean;
        transferAvailable?: boolean;
    };
}>();
const e = computed(() => props.page.eligibility),
    activated = computed(() => e.value.wallet !== null && e.value.available !== null),
    operational = computed(
        () => e.value.userStatus === 'ACTIVE' && e.value.tenantStatus === 'ACTIVE',
    ),
    depositAvailable = computed(
        () =>
            operational.value &&
            e.value.kycStatus === 'APPROVED' &&
            e.value.walletStatus === 'ACTIVE',
    ),
    hidden = ref(false),
    expanded = ref<Record<string, boolean>>({});
const inactiveCopy: Record<string, string> = {
    NOT_SUBMITTED: 'Verify your identity before activating a wallet.',
    PENDING: 'Your identity verification is under review.',
    REJECTED: 'Identity verification is unavailable. Contact support for assistance.',
    RESUBMISSION_REQUIRED: 'Updated identity documents are required before you can continue.',
};
const inactiveDescription = computed(() =>
    e.value.kycStatus === 'APPROVED' && e.value.canActivate
        ? t('Set up your wallet and review the security deposit requirement.')
        : t(inactiveCopy[e.value.kycStatus] ?? 'Wallet activation is currently unavailable.'),
);
const inactiveAction = computed(() =>
    e.value.kycStatus === 'APPROVED' && e.value.canActivate
        ? undefined
        : ['NOT_SUBMITTED', 'RESUBMISSION_REQUIRED'].includes(e.value.kycStatus)
          ? { label: t('Identity verification'), href: '/kyc' }
          : undefined,
);
const unavailable = computed(() =>
    ['NOT_SUBMITTED', 'PENDING', 'RESUBMISSION_REQUIRED'].includes(e.value.kycStatus)
        ? '/kyc'
        : !e.value.wallet && e.value.kycStatus === 'APPROVED'
          ? '/security-deposit'
          : '/support',
);
const actions = computed(() => [
    {
        label: 'Top up',
        icon: 'arrow-down',
        href: '/wallet/top-up',
        available: props.page.topupAvailable,
    },
    {
        label: 'Withdraw',
        icon: 'arrow-up',
        href: '/wallet/withdraw',
        available: props.page.withdrawalAvailable,
    },
    {
        label: 'Security deposit',
        icon: 'shield-check',
        href: '/security-deposit',
        available: depositAvailable.value,
    },
    {
        label: 'Transfer',
        icon: 'arrow-left-right',
        href: '/wallet/transfer',
        available: props.page.transferAvailable,
    },
]);
const labels: Record<string, string> = {
    WALLET_TOPUP_CREDIT: 'Wallet top up',
    WALLET_ADJUSTMENT: 'Admin adjustment',
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
function label(row: { eventType: string; amount?: string }) {
    return t(
        row.eventType === 'WALLET_TRANSFER'
            ? row.amount?.startsWith('-')
                ? 'Transfer sent'
                : 'Transfer received'
            : (labels[row.eventType] ?? 'Wallet activity'),
    );
}
function show(row: Activity) {
    if (row.eventType === 'WALLET_TRANSFER' && row.transferId)
        go('/wallet/transfers/' + row.transferId);
    else expanded.value[row.id] = !expanded.value[row.id];
}
</script>
<template>
    <PageShell :title="t('Asset activity')" back="/dashboard" active="assets"
        ><view class="wallet"
            ><StatusBanner
                v-if="!activated"
                :title="
                    t(
                        e.kycStatus === 'APPROVED' && e.canActivate
                            ? 'Your identity is verified'
                            : 'Wallet is not activated',
                    )
                "
                :description="inactiveDescription"
                :action="inactiveAction"
            /><template v-else
                ><view class="overview"
                    ><view class="balance-label"
                        ><text>{{ t('Available balance') }}</text
                        ><button
                            :aria-label="t(hidden ? 'Show balance' : 'Hide balance')"
                            @click="hidden = !hidden"
                        >
                            <UiIcon :name="hidden ? 'eye-off' : 'eye'" :size="20" /></button></view
                    ><text class="balance"
                        >{{ hidden ? '••••••' : displayMoney(e.available!.amount) }}
                        <text>{{ e.available!.asset }}</text></text
                    ><view class="wallet-actions"
                        ><button
                            v-for="item in actions"
                            :key="item.label"
                            :class="{ unavailable: !item.available }"
                            @click="go(item.available ? item.href : unavailable)"
                        >
                            <UiIcon :name="item.icon" :size="24" /><text>{{ t(item.label) }}</text>
                        </button></view
                    ></view
                ><StatusBanner
                    v-if="!operational"
                    tone="warning"
                    :title="t('Wallet access is restricted')"
                    :description="
                        t('You can review your balance, but financial actions are unavailable.')
                    "
                /><view class="panel"
                    ><view class="deposit-heading"
                        ><UiIcon name="shield-check" :size="24" /><view
                            ><text class="heading">{{ t('Security deposit') }}</text
                            ><text class="small muted">{{
                                t(
                                    e.activation.agent
                                        ? 'Active agents do not need a security deposit.'
                                        : e.depositSatisfied
                                          ? 'Requirement met'
                                          : 'Complete your requirement to access cards',
                                )
                            }}</text></view
                        ><button
                            v-if="depositAvailable"
                            class="icon"
                            @click="go('/security-deposit')"
                        >
                            <UiIcon name="chevron-right" :size="20" /></button></view
                    ><view class="deposit-grid"
                        ><view
                            v-for="row in [
                                { label: 'Already deposited', money: e.depositCurrent },
                                { label: 'Required', money: e.depositRequired },
                            ]"
                            :key="row.label"
                            ><text class="small muted">{{ t(row.label) }}</text
                            ><text class="money"
                                >{{ displayMoney(row.money.amount) }} {{ row.money.asset }}</text
                            ></view
                        ></view
                    ><text v-if="!e.activationSatisfied" class="remaining muted"
                        >{{ t('Remaining requirement') }}
                        {{ displayMoney(e.depositRemaining.amount) }}
                        {{ e.depositRemaining.asset }}</text
                    ><button
                        v-if="page.depositFundingAvailable"
                        class="primary"
                        @click="go('/security-deposit')"
                    >
                        {{ t('Pay security deposit') }}
                    </button></view
                ><view class="panel"
                    ><text class="heading">{{ t('Recent activity') }}</text
                    ><text v-if="!page.activity.length" class="empty muted">{{
                        t('No activity yet')
                    }}</text
                    ><view v-for="row in page.activity" :key="row.id" class="activity"
                        ><view class="activity-summary" @click="show(row)"
                            ><view class="activity-icon"
                                ><UiIcon
                                    :name="
                                        row.amount?.startsWith('-')
                                            ? 'arrow-up-right'
                                            : 'arrow-down-left'
                                    "
                                    :size="16" /></view
                            ><view class="activity-body"
                                ><view class="between"
                                    ><text>{{ label(row) }}</text
                                    ><text v-if="row.amount" class="strong"
                                        >{{ exactAmount(row.amount) }} {{ row.asset }}</text
                                    ></view
                                ><text class="small muted">{{ dateTime(row.postedAt) }}</text></view
                            ></view
                        ><view v-if="expanded[row.id]" class="activity-details"
                            ><text v-if="row.reference" selectable
                                >{{ t('Reference') }}: {{ row.reference }}</text
                            ><text v-if="row.state">{{ t(row.state) }}</text
                            ><view v-for="step in row.steps ?? []" :key="step.id"
                                ><text
                                    >{{ label(step) }} · {{ exactAmount(step.amount) }}
                                    {{ row.asset }}</text
                                ><text class="small muted">{{
                                    dateTime(step.postedAt)
                                }}</text></view
                            ></view
                        ></view
                    ></view
                ></template
            ></view
        ></PageShell
    >
</template>
<style scoped>
.wallet {
    display: flex;
    flex-direction: column;
    gap: 24px;
}
.overview {
    border-radius: 20px;
    background: linear-gradient(135deg, #d7eddc, #eff4e7);
    padding: 24px 20px;
}
.balance-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: #68736e;
}
.balance-label button {
    background: none;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0;
    padding: 0;
}
.balance {
    display: block;
    font-size: 32px;
    line-height: 40px;
    font-weight: 600;
    margin-top: 8px;
    overflow-wrap: anywhere;
}
.balance > text {
    font-size: 16px;
    font-weight: 400;
}
.wallet-actions {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 8px;
    margin-top: 24px;
}
.wallet-actions button {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    background: none;
    font-size: 12px;
    line-height: 18px;
    padding: 8px 0;
    margin: 0;
}
.unavailable {
    opacity: 0.55;
}
.panel {
    background: white;
    border-radius: 16px;
    padding: 20px;
}
.heading {
    font-size: 16px;
    font-weight: 600;
    line-height: 24px;
    display: block;
}
.small {
    display: block;
    font-size: 12px;
    line-height: 20px;
    margin-top: 4px;
}
.deposit-heading {
    display: flex;
    align-items: center;
    gap: 12px;
}
.deposit-heading > view {
    flex: 1;
    min-width: 0;
}
.icon {
    background: none;
    padding: 0;
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.deposit-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #e1e7dd;
}
.money {
    display: block;
    font-size: 14px;
    font-weight: 600;
    margin-top: 4px;
    overflow-wrap: anywhere;
}
.remaining {
    display: block;
    font-size: 12px;
    line-height: 20px;
    margin-top: 12px;
}
.panel > .primary {
    margin-top: 20px;
}
.empty {
    display: block;
    text-align: center;
    font-size: 14px;
    padding: 24px 0;
}
.activity {
    border-bottom: 1px solid #e1e7dd;
}
.activity:last-child {
    border: 0;
}
.activity-summary {
    display: flex;
    gap: 12px;
    padding: 16px 0;
}
.activity-icon {
    width: 32px;
    height: 32px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #f0f3f1;
}
.activity-body {
    min-width: 0;
    flex: 1;
}
.between {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 4px 12px;
    font-size: 14px;
    line-height: 22px;
}
.strong {
    font-weight: 600;
    overflow-wrap: anywhere;
}
.activity-details {
    padding: 0 0 16px 44px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 12px;
    line-height: 20px;
}
</style>
