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
                asset: string;
                state: string;
                requestedAt: string;
            }[];
            currentPage: number;
            lastPage: number;
        };
    };
}>();
const labels: Record<string, string> = {
    pending: 'Refund checks pending',
    completed: 'Security deposit refunded',
    cancelled: 'Refund request cancelled',
};
</script>
<template>
    <PageShell :title="t('Security deposit history')" back="/security-deposit" active="assets"
        ><view v-if="!page.history.data.length" class="empty">{{
            t('No security deposit history yet.')
        }}</view
        ><view v-for="row in page.history.data" :key="row.id" class="refund-row"
            ><view
                ><text class="refund-label">{{ t(labels[row.state]) }}</text
                ><text class="refund-time">{{ dateTime(row.requestedAt) }}</text></view
            ><text class="refund-amount">{{ displayMoney(row.amount) }} {{ row.asset }}</text></view
        ><view v-if="page.history.lastPage > 1" class="pagination"
            ><button
                class="secondary"
                :disabled="page.history.currentPage <= 1"
                @click="
                    go('/security-deposit/history?page=' + (page.history.currentPage - 1), true)
                "
            >
                {{ t('Previous') }}</button
            ><text>{{ page.history.currentPage }} / {{ page.history.lastPage }}</text
            ><button
                class="secondary"
                :disabled="page.history.currentPage >= page.history.lastPage"
                @click="
                    go('/security-deposit/history?page=' + (page.history.currentPage + 1), true)
                "
            >
                {{ t('Next') }}
            </button></view
        ></PageShell
    >
</template>
<style scoped>
.refund-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 0;
    border-bottom: 1px solid #e2e7e4;
    font-size: 14px;
}
.refund-label {
    display: block;
    font-weight: 500;
}
.refund-time {
    display: block;
    margin-top: 8px;
    font-size: 12px;
    color: #68736e;
}
.refund-amount {
    font-weight: 600;
    flex-shrink: 0;
}
.pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    margin-top: 24px;
    font-size: 14px;
}
</style>
