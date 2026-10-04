<script setup lang="ts">
import { computed } from 'vue';
import ReportPagination from './ReportPagination.vue';
import { t, dateTime } from '../lib/i18n';
import { fullMoney } from '../lib/promotion-report';
import type { StockReport } from '../lib/stock';
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
        <text class="muted">{{
            t(
                'All currencies use the same current USDT rates. The valuation changes with market prices.',
            )
        }}</text>
        <text v-if="report.cashFlow?.rateObservedAt" class="muted"
            >{{ t('Exchange rate time') }}: {{ dateTime(report.cashFlow.rateObservedAt) }}</text
        >
        <text>{{ t('Total records') }}: {{ details.total }}</text>
        <text v-if="!details.items.length">{{ t('No completed transactions in this team.') }}</text>
        <view v-for="row in details.items" :key="row.source + row.id" class="flow-item">
            <text class="strong">{{ t('Transaction member') }}: {{ row.account_id }}</text>
            <text>{{ row.email }}</text>
            <view class="branch">
                <text>{{ t('Direct branch member') }}: {{ row.direct_account_id }}</text>
                <text class="muted">{{ row.direct_email }}</text>
                <text v-if="row.account_id === row.direct_account_id" class="muted">{{
                    t('Direct member themself')
                }}</text>
            </view>
            <text
                >{{
                    t(
                        details.direction === 'inflow'
                            ? 'Deposit amount'
                            : 'Gross withdrawal amount',
                    )
                }}: {{ row.amount }} {{ row.asset_code }}</text
            >
            <text
                >{{ t('Current USDT estimate') }}:
                {{
                    row.amountUsdt == null ? t('Incomplete valuation') : fullMoney(row.amountUsdt)
                }}</text
            >
            <text class="muted">{{ t('Posted at') }}: {{ dateTime(row.posted_at) }}</text>
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
