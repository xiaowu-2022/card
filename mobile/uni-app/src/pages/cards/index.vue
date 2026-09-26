<script setup lang="ts">
import { ref } from 'vue';
import PageShell from '../../components/PageShell.vue';
import LoadState from '../../components/LoadState.vue';
import { request } from '../../lib/api';
import { useScreen } from '../../lib/screen';
import { t, dateTime } from '../../lib/i18n';
import { exactAmount } from '../../generated/exact-amount';
type Card = { id: string; productName: string; last4: string; balance: string | null; currency: string; state: string; syncedAt: string | null };
const cards = ref<Card[]>([]);
const { loading, failed, refresh } = useScreen(async () => { cards.value = (await request<{ cards: Card[] }>('/cards')).cards; });
</script>
<template>
  <PageShell :title="t('Cards')" active="cards">
    <LoadState :loading="loading" :failed="failed" @retry="refresh">
      <view v-if="!cards.length" class="empty">{{ t('No cards yet') }}</view>
      <view v-for="card in cards" :key="card.id" class="row"><text class="title">{{ card.productName }}</text><text class="body">•••• {{ card.last4 }}</text><text class="title">{{ card.balance == null ? '—' : exactAmount(card.balance) }} {{ card.currency }}</text><text class="muted">{{ t(card.state) }}</text><text v-if="card.syncedAt" class="body muted">{{ dateTime(card.syncedAt) }}</text></view>
    </LoadState>
  </PageShell>
</template>
