<script setup lang="ts">
import { ref } from 'vue';
import PageShell from '../../components/PageShell.vue';
import LoadState from '../../components/LoadState.vue';
import { request } from '../../lib/api';
import { useScreen } from '../../lib/screen';
import { t } from '../../lib/i18n';
import { exactAmount } from '../../generated/exact-amount';
type Overview = { estimate: string | null; assets: { asset: string; available: string; held: string; deposit: string }[] };
const data = ref<Overview>();
const { loading, failed, refresh } = useScreen(async () => { data.value = await request<Overview>('/assets'); });
</script>
<template>
  <PageShell :title="t('Assets')" active="assets">
    <LoadState :loading="loading" :failed="failed" @retry="refresh">
      <view class="row"><text class="muted">{{ t('Total asset valuation') }}</text><text class="title">{{ data?.estimate == null ? t('Valuation unavailable') : exactAmount(data.estimate) + ' USDT' }}</text></view>
      <view v-for="asset in data?.assets" :key="asset.asset" class="row flex"><text>{{ asset.asset }}</text><view style="text-align:right"><text class="body">{{ exactAmount(asset.available) }}</text><text class="muted">{{ t('Available balance') }}</text></view></view>
    </LoadState>
  </PageShell>
</template>
