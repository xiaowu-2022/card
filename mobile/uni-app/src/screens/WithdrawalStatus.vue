<script setup lang="ts">
import { computed } from 'vue';
import PageShell from '../components/PageShell.vue';
import WithdrawalAmounts from '../components/WithdrawalAmounts.vue';
import FormErrors from '../components/FormErrors.vue';
import UiIcon from '../components/UiIcon.vue';
import { t } from '../lib/i18n';
import { useAction } from '../lib/client';
import { displayMoney } from '../generated/exact-amount';
const props = defineProps<{
    page: {
        order: {
            id: string;
            amount: string;
            feeAmount: string;
            receiveAmount: string;
            asset: string;
            network: string;
            maskedAddress: string;
            status: string;
            txHash: string | null;
            reviewReason: string | null;
        };
    };
}>();
const order = computed(() => props.page.order),
    action = useAction(),
    emit = defineEmits<{ reload: [] }>();
const content: Record<string, [string, string]> = {
    PENDING: ['Withdrawal submitted', 'Pending review'],
    APPROVED: ['Withdrawal approved', "We're processing your transfer."],
    VERIFYING: ['Transfer submitted', "We're confirming the transaction."],
    SUCCEEDED: ['Withdrawal complete', 'Your transfer is confirmed on the TRON network.'],
    REJECTED: ['Withdrawal rejected', 'Funds have been returned to your available balance.'],
    CANCELLED: ['Withdrawal cancelled', 'Funds have been returned to your available balance.'],
};
const copy = computed(
        () => content[order.value.status] ?? ['Withdrawal', 'Review your withdrawal status.'],
    ),
    reversed = computed(() => ['REJECTED', 'CANCELLED'].includes(order.value.status));
async function cancel() {
    await action.submit(
        '/wallet/withdrawals/' + order.value.id + '/cancel',
        {},
        { navigate: false, success: () => emit('reload') },
    );
}
</script>
<template>
    <PageShell :title="t('Withdrawal')" back="/wallet/withdrawals" active="assets"
        ><FormErrors :errors="action.errors.value" /><view class="receipt"
            ><UiIcon
                :name="
                    order.status === 'SUCCEEDED'
                        ? 'circle-check'
                        : reversed
                          ? 'circle-alert'
                          : 'clock3'
                "
                :size="40"
            /><text class="title">{{ t(copy[0]) }}</text
            ><text class="muted description">{{ t(copy[1]) }}</text
            ><text class="amount">{{ displayMoney(order.amount) }} {{ order.asset }}</text
            ><view class="details"
                ><view
                    ><text class="muted">{{ t('Network') }}</text
                    ><text>{{ order.network }}</text></view
                ><view
                    ><text class="muted">{{ t('Address') }}</text
                    ><text>{{ order.maskedAddress }}</text></view
                ><view v-if="order.txHash"
                    ><text class="muted">{{ t('Transaction') }}</text
                    ><text class="hash">{{ order.txHash }}</text></view
                ></view
            ><WithdrawalAmounts
                :fee="reversed ? '0' : order.feeAmount"
                :receive="reversed ? '0' : order.receiveAmount"
            /><button
                v-if="order.status === 'PENDING'"
                class="secondary cancel"
                :disabled="action.pending.value"
                @click="cancel"
            >
                {{ t('Cancel withdrawal') }}
            </button></view
        ></PageShell
    >
</template>
<style scoped>
.receipt {
    padding: 24px;
    text-align: center;
    background: white;
    border: 1px solid #e2e7e4;
    border-radius: 16px;
}
.title {
    display: block;
    font-size: 24px;
    line-height: 32px;
    font-weight: 600;
    margin-top: 16px;
}
.description {
    display: block;
    font-size: 14px;
    line-height: 22px;
    margin-top: 8px;
}
.amount {
    display: block;
    font-size: 30px;
    line-height: 36px;
    font-weight: 600;
    margin-top: 24px;
    overflow-wrap: anywhere;
}
.details {
    margin-top: 24px;
    border-top: 1px solid #e2e7e4;
    padding: 20px 0 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    font-size: 14px;
}
.details > view {
    display: flex;
    justify-content: space-between;
    gap: 16px;
}
.hash {
    max-width: 70%;
    overflow-wrap: anywhere;
}
.cancel {
    margin-top: 24px;
}
</style>
