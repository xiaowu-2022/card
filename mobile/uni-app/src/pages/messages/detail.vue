<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { onLoad, onShow, onHide } from '@dcloudio/uni-app';
import PageShell from '../../components/PageShell.vue';
import LoadState from '../../components/LoadState.vue';
import { request } from '../../lib/api';
import { refreshUnread } from '../../lib/session';
import { useScreen } from '../../lib/screen';
import { t, dateTime } from '../../lib/i18n';
import { messageCopy, type Message } from '../../lib/inbox';
const id = ref(''); const message = ref<Message>(); const readFailed = ref(false);
let visible = false;
onShow(() => { visible = true; }); onHide(() => { visible = false; });
onLoad((query) => { id.value = query?.id ?? ''; });
async function read() { try { await request(`/messages/${id.value}/read`, 'POST'); readFailed.value = false; await refreshUnread(); } catch { readFailed.value = true; } }
const { loading, failed, refresh } = useScreen(async () => { message.value = await request<Message>(`/messages/${id.value}`); });
watch(loading, async (value) => { if (!value && !failed.value && message.value && !message.value.readAt) { await nextTick(); if (visible) await read(); } });
</script>
<template>
  <PageShell :title="t('Messages')" back="/pages/messages/index">
    <LoadState :loading="loading" :failed="failed" @retry="refresh">
      <view v-if="message"><text class="muted">{{ dateTime(message.time) }}</text><text class="title">{{ messageCopy(message).title }}</text><text class="body">{{ messageCopy(message).body }}</text></view>
      <view v-if="readFailed" class="error"><text>{{ t('Could not update messages. Please try again.') }}</text><button class="secondary" @click="read">{{ t('Retry') }}</button></view>
    </LoadState>
  </PageShell>
</template>
