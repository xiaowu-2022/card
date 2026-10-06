<script setup lang="ts">
import PageShell from '../components/PageShell.vue';
import ReportPagination from '../components/ReportPagination.vue';
import { t } from '../lib/i18n';
import { go } from '../lib/navigation';
import type { PartnerChildren } from '../lib/stock';
defineProps<{ page: { partners: PartnerChildren } }>();
</script>
<template>
    <PageShell
        :title="t('Partner data')"
        back="/promotion/stock"
        history-back
        active="account"
        white
    >
        <view class="partner-list">
            <text class="heading">{{ page.partners.subject.name }}</text>
            <text v-if="!page.partners.items.length">{{ t('No subordinate partners') }}</text>
            <view v-for="row in page.partners.items" :key="row.id" class="partner-row">
                <text class="name">{{ row.name }}</text>
                <text>{{ t('Team members') }}: {{ row.teamCount }}</text>
                <view class="actions">
                    <button @click="go('/promotion/stock/partners/' + row.id + '/report')">
                        {{ t('Details') }}
                    </button>
                    <button @click="go('/promotion/stock/partners/' + row.id)">
                        {{ t('Subordinate partners') }}
                    </button>
                </view>
            </view>
            <ReportPagination
                :page="page.partners.page"
                :has-more="page.partners.hasMore"
                @change="(pageNumber) => go(page.partners.listPath + '?page=' + pageNumber, true)"
            />
        </view>
    </PageShell>
</template>
<style scoped>
.partner-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 16px;
}
.heading,
.name {
    font-weight: 600;
}
.partner-row {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.actions {
    display: flex;
    gap: 12px;
}
.actions button {
    flex: 1;
    font-size: 14px;
    margin: 0;
}
</style>
