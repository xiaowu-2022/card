<script setup lang="ts">
import { computed } from 'vue';
import type { FundsMovement } from '../lib/assets';
import PageShell from '../components/PageShell.vue';
import SelectField from '../components/SelectField.vue';
import ActivityList from '../components/ActivityList.vue';
import { t } from '../lib/i18n';
import { go } from '../lib/navigation';
import { exactAmount } from '../generated/exact-amount';
const props = defineProps<{
    page: {
        selectedAsset: string;
        balances: { asset: string; available: string }[];
        rows: {
            data: (FundsMovement & { asset: string })[];
            next_page_url: string | null;
            prev_page_url: string | null;
        };
    };
}>();
const title = computed(() =>
    props.page.selectedAsset === 'ALL'
        ? t('Funds activity')
        : t('{{asset}} funds account', { asset: props.page.selectedAsset }),
);
</script>
<template>
    <PageShell
        :title="title"
        :back="page.selectedAsset === 'ALL' ? '/account' : '/dashboard'"
        active="assets"
        ><SelectField
            :model-value="page.selectedAsset"
            :label="t('Select currency')"
            :options="[
                { value: 'ALL', label: t('All currencies') },
                ...page.balances.map((b) => ({ value: b.asset, label: b.asset })),
            ]"
            @update:model-value="(value) => go('/funds?asset=' + value, true)"
        /><view class="funds-summary"
            ><text class="summary-label">{{ t('Available funds') }}</text
            ><view class="balances" :class="{ all: page.selectedAsset === 'ALL' }"
                ><view
                    v-for="balance in page.balances.filter(
                        (b) => page.selectedAsset === 'ALL' || b.asset === page.selectedAsset,
                    )"
                    :key="balance.asset"
                    ><text class="small">{{ balance.asset }}</text
                    ><text class="amount">{{ exactAmount(balance.available) }}</text></view
                ></view
            ></view
        ><text class="section-title">{{ t('Funds movements') }}</text
        ><view class="history-panel"><ActivityList :items="page.rows.data" /></view
        ><view class="pagination"
            ><button
                v-if="page.rows.prev_page_url"
                class="text-button"
                @click="go(page.rows.prev_page_url, true)"
            >
                {{ t('Previous') }}</button
            ><view v-else /><button
                v-if="page.rows.next_page_url"
                class="text-button"
                @click="go(page.rows.next_page_url, true)"
            >
                {{ t('Next') }}
            </button></view
        ></PageShell
    >
</template>
<style scoped>
.funds-summary {
    padding: 20px;
    border-radius: 16px;
    background: linear-gradient(135deg, #d1fae5, #f7fee7);
    margin-bottom: 20px;
    color: #0f172a;
}
.summary-label {
    display: block;
    margin-bottom: 12px;
    font-size: 14px;
    font-weight: 500;
}
.balances.all {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.small {
    display: block;
    font-size: 12px;
    color: #475569;
}
.amount {
    display: block;
    margin-top: 4px;
    font-size: 20px;
    font-weight: 600;
    line-height: 28px;
    overflow-wrap: anywhere;
}
.section-title {
    display: block;
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 12px;
}
.history-panel {
    background: white;
    border: 1px solid #0000000d;
    border-radius: 16px;
    padding: 0 16px;
    box-shadow: 0 1px 2px #0000000d;
}
.pagination {
    display: flex;
    justify-content: space-between;
    margin-top: 20px;
}
.text-button {
    min-height: 44px;
    padding: 12px 0;
    background: none;
    font-size: 14px;
    line-height: 20px;
}
</style>
