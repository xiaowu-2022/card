<script setup lang="ts">
import PageShell from '../components/PageShell.vue';
import ReportPagination from '../components/ReportPagination.vue';
import { t, dateTime } from '../lib/i18n';
import { go } from '../lib/navigation';
import { promotionLevel } from '../lib/promotion';
import { exactAmount } from '../generated/exact-amount';
const props = defineProps<{
    page: {
        details: {
            kind: string;
            rank: number;
            page: number;
            hasMore: boolean;
            items: {
                id: string;
                direct: boolean;
                rate: string;
                amount: string;
                sourceAmount: string;
                occurredAt: string;
                accountId: string;
            }[];
        };
    };
}>();
function paginate(page: number) {
    go(
        '/promotion/rewards?kind=' +
            props.page.details.kind +
            '&rank=' +
            props.page.details.rank +
            '&page=' +
            page,
        true,
    );
}
</script>
<template>
    <PageShell
        :title="
            t(
                page.details.kind === 'ANNUAL'
                    ? 'Annual fee commission details'
                    : 'Activation commission details',
            )
        "
        back="/promotion/invitations"
        active="account"
        ><text class="rank">{{ promotionLevel(page.details.rank) }}</text
        ><view v-for="row in page.details.items" :key="row.id" class="reward-row"
            ><view class="between"
                ><text>{{ row.accountId }} · {{ t(row.direct ? 'Direct' : 'Indirect') }}</text
                ><text class="strong">{{ exactAmount(row.amount) }} USDT</text></view
            ><text
                >{{ t('Source amount') }}: {{ exactAmount(row.sourceAmount) }} USDT ·
                {{ t(page.details.kind === 'ANNUAL' ? 'Reward rate' : 'Reward difference') }}:
                {{ exactAmount(row.rate) }}
                {{ page.details.kind === 'ANNUAL' ? '%' : 'USDT' }}</text
            ><text class="date muted">{{ dateTime(row.occurredAt) }}</text></view
        ><text v-if="!page.details.items.length">{{ t('No activity yet') }}</text
        ><ReportPagination
            :page="page.details.page"
            :has-more="page.details.hasMore"
            @change="paginate"
    /></PageShell>
</template>
<style scoped>
.rank {
    display: block;
    margin-bottom: 16px;
}
.reward-row {
    display: flex;
    flex-direction: column;
    gap: 8px;
    border-bottom: 1px solid #e1e7dd;
    padding: 16px 0;
    font-size: 14px;
    line-height: 22px;
}
.between {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.strong {
    font-weight: 600;
}
.date {
    font-size: 12px;
}
</style>
