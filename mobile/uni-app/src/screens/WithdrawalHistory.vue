<script setup lang="ts">
import PageShell from '../components/PageShell.vue';
import { t, dateTime } from '../lib/i18n';
import { go } from '../lib/navigation';
import { displayMoney } from '../generated/exact-amount';
defineProps<{
    page: {
        history: {
            data: {
                id: string;
                amount: string;
                feeAmount: string;
                receiveAmount: string;
                asset: string;
                maskedAddress: string;
                requestedAt: string;
                state: string;
            }[];
            currentPage: number;
            lastPage: number;
        };
    };
}>();
const labels: Record<string, string> = {
    pending: 'Pending review',
    processing: 'Withdrawal approved',
    confirming: 'Confirming transaction',
    completed: 'Withdrawal complete',
    rejected: 'Withdrawal rejected',
    cancelled: 'Withdrawal cancelled',
};
</script>
<template>
    <PageShell :title="t('Withdrawal history')" back="/wallet/withdraw" active="assets"
        ><text v-if="!page.history.data.length" class="empty muted">{{
            t('No withdrawals yet.')
        }}</text
        ><view
            v-for="order in page.history.data"
            :key="order.id"
            class="order"
            @click="go('/wallet/withdrawals/' + order.id)"
            ><view class="body"
                ><text class="amount">{{ displayMoney(order.amount) }} {{ order.asset }}</text
                ><text class="muted">{{ order.maskedAddress }}</text
                ><template v-if="!['rejected', 'cancelled'].includes(order.state)"
                    ><text class="small muted"
                        >{{ t('Withdrawal fee') }}: {{ displayMoney(order.feeAmount) }} USDT</text
                    ><text class="small muted"
                        >{{ t('Amount to receive') }}:
                        {{ displayMoney(order.receiveAmount) }} USDT</text
                    ></template
                ><text class="small muted">{{ dateTime(order.requestedAt) }}</text></view
            ><text class="status">{{ t(labels[order.state] ?? order.state) }}</text></view
        ><view v-if="page.history.lastPage > 1" class="pages"
            ><button
                class="secondary"
                :disabled="page.history.currentPage <= 1"
                @click="go('/wallet/withdrawals?page=' + (page.history.currentPage - 1), true)"
            >
                {{ t('Previous') }}</button
            ><text>{{ page.history.currentPage }} / {{ page.history.lastPage }}</text
            ><button
                class="secondary"
                :disabled="page.history.currentPage >= page.history.lastPage"
                @click="go('/wallet/withdrawals?page=' + (page.history.currentPage + 1), true)"
            >
                {{ t('Next') }}
            </button></view
        ></PageShell
    >
</template>
<style scoped>
.empty {
    display: block;
    text-align: center;
    padding: 24px 0;
    font-size: 14px;
}
.order {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 0;
    border-bottom: 1px solid #e2e7e4;
}
.body {
    min-width: 0;
}
.body > text {
    display: block;
    margin-top: 4px;
    font-size: 14px;
    overflow-wrap: anywhere;
}
.body > .amount {
    font-weight: 600;
    font-size: 16px;
    margin-top: 0;
}
.body > .small {
    font-size: 12px;
}
.status {
    font-size: 14px;
    flex-shrink: 0;
}
.pages {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    margin-top: 16px;
    font-size: 14px;
}
.pages button {
    font-size: 14px;
    margin: 0;
}
</style>
