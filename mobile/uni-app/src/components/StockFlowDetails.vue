<script setup lang="ts">
import { computed } from 'vue';
import ReportPagination from './ReportPagination.vue';
import { t, dateTime } from '../lib/i18n';
import type { StockReport } from '../lib/stock';
const stockEntryLabels: Record<string, string> = {
    deposits: 'Eligible security deposit balances',
    annual: 'Total annual fees paid',
    activation: 'Activation commissions paid',
    annualCommission: 'Annual fee commissions paid',
    rebates: 'Annual fees returned',
    reimbursements: 'Reimbursed expenses',
};
const props = defineProps<{ report: StockReport }>();
defineEmits<{ page: [page: number] }>();
const details = computed(() => props.report.flowDetails!);
</script>
<template>
    <view class="flow-details">
        <text class="muted">{{
            t(
                'Branch ownership follows current referral relationships. Direct members belong to their own branch.',
            )
        }}</text>
        <text>{{ t('Total records') }}: {{ details.total }}</text>
        <text v-if="!details.items.length">{{ t('No completed transactions in this team.') }}</text>
        <view v-for="row in details.items" :key="row.source + row.id" class="flow-item">
            <text class="strong">{{ t('Transaction member') }}: {{ row.account_id }}</text>
            <text>{{ row.email }}</text>
            <view class="branch">
                <text
                    >{{ t('Direct branch member') }}:
                    {{ row.direct_account_id ?? t('This partner') }}</text
                >
                <text class="muted">{{ row.direct_email }}</text>
                <text v-if="row.account_id === row.direct_account_id" class="muted">{{
                    t('Direct member themself')
                }}</text>
            </view>
            <text>{{ t(stockEntryLabels[row.source] ?? 'Amount') }}: {{ row.amount }} USDT</text>
            <text class="muted">{{
                row.posted_at
                    ? t('Posted at') + ': ' + dateTime(row.posted_at)
                    : t('Current security deposit balance')
            }}</text>
        </view>
        <ReportPagination
            :page="details.page"
            :has-more="details.hasMore"
            @change="(page) => $emit('page', page)"
        />
    </view>
</template>
<style scoped>
.flow-details,
.flow-item,
.branch {
    display: flex;
    flex-direction: column;
    gap: 8px;
    overflow-wrap: anywhere;
}
.flow-details {
    gap: 16px;
    font-size: 14px;
    line-height: 22px;
}
.flow-item {
    padding: 18px;
    background: white;
    border: 1px solid #e1e7dd;
    border-radius: 16px;
}
.branch {
    background: #f4f7f3;
    border-radius: 8px;
    padding: 10px;
}
.muted {
    color: #71837c;
    font-size: 12px;
    line-height: 20px;
}
.strong {
    font-weight: 600;
}
</style>
