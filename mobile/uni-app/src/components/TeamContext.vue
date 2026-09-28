<script setup lang="ts">
import { computed } from 'vue';
import { t } from '../lib/i18n';
import { go } from '../lib/navigation';
import { teamReturnHref, reportMoney, type IncomeTotals } from '../lib/promotion-report';
import type { TeamSubject } from '../lib/report-types';
const props = defineProps<{
    subject: TeamSubject;
    breadcrumbs?: TeamSubject[];
    totals?: IncomeTotals;
}>();
const viewing = computed(() => t('Viewing {{account}}', { account: props.subject.accountId }));
</script>
<template>
    <view class="context"
        ><view class="breadcrumbs"
            ><button @click="go(teamReturnHref())">{{ t('My team') }}</button
            ><view v-for="node in breadcrumbs ?? []" :key="node.id ?? 'root'"
                ><text>/</text
                ><text v-if="node.id === subject.id">{{ node.displayName || node.accountId }}</text
                ><button v-else @click="go(teamReturnHref(node.id))">
                    {{ node.displayName || node.accountId }}
                </button></view
            ></view
        ><view class="summary"
            ><view
                ><text class="muted">{{ viewing }}</text
                ><text v-if="subject.displayName" class="strong">{{
                    subject.displayName
                }}</text></view
            ><view v-if="totals"
                ><text class="muted">{{ t('Member cumulative commission') }}</text
                ><text class="strong">{{ reportMoney(totals.total) }}</text></view
            ></view
        ></view
    >
</template>
<style scoped>
.context {
    padding: 16px;
    border-radius: 14px;
    background: #f2f6f1;
    border: 1px solid #e1e7dd;
}
.breadcrumbs,
.breadcrumbs > view {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 12px;
    line-height: 20px;
}
.breadcrumbs button {
    background: none;
    font-size: 12px;
    line-height: 20px;
    padding: 0;
    margin: 0;
    text-decoration: underline;
}
.summary {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 12px;
    margin-top: 12px;
    font-size: 12px;
    line-height: 20px;
}
.strong {
    display: block;
    font-weight: 600;
    font-size: 14px;
    margin-top: 4px;
    overflow-wrap: anywhere;
}
</style>
