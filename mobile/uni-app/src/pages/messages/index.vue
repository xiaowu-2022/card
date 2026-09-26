<script setup lang="ts">
import { ref } from 'vue';
import PageShell from '../../components/PageShell.vue';
import LoadState from '../../components/LoadState.vue';
import { request } from '../../lib/api';
import { refreshUnread } from '../../lib/session';
import { useScreen } from '../../lib/screen';
import { t, dateTime } from '../../lib/i18n';
import { messageCopy, type Message } from '../../lib/inbox';
const filters = [{ key: 'all', title: 'All' }, { key: 'unread', title: 'Unread' }, { key: 'business', title: 'Business notifications' }, { key: 'platform', title: 'Platform notifications' }];
const filter = ref('all'); const page = ref(1); const items = ref<Message[]>([]); const hasMore = ref(false); const busy = ref(false); const actionFailed = ref(false);
async function load() { const data = await request<{ items: Message[]; hasMore: boolean }>(`/messages?filter=${filter.value}&page=${page.value}`); items.value = data.items; hasMore.value = data.hasMore; await refreshUnread(); }
const { loading, failed, refresh } = useScreen(load);
function select(key: string) { filter.value = key; page.value = 1; void refresh(); }
function paginate(delta: number) { page.value += delta; void refresh(); }
function openMessage(id: string) { uni.navigateTo({ url: '/pages/messages/detail?id=' + id }); }
async function allRead() { busy.value = true; actionFailed.value = false; try { await request('/messages/read-all', 'POST'); await refreshUnread(); await refresh(); } catch { actionFailed.value = true; } finally { busy.value = false; } }
</script>
<template>
  <PageShell :title="t('Messages')" back="/pages/account/index">
    <view class="toolbar"><button v-for="item in filters" :key="item.key" class="filter" :class="{ selected: filter === item.key }" :disabled="loading" @click="select(item.key)">{{ t(item.title) }}</button></view>
    <view class="flex" style="justify-content:flex-end"><button class="filter" style="flex:none" :disabled="busy" @click="allRead">{{ t('Mark all as read') }}</button></view>
    <text v-if="actionFailed" class="error">{{ t('Could not update messages. Please try again.') }}</text>
    <LoadState :loading="loading" :failed="failed" @retry="refresh">
      <view v-if="!items.length" class="empty">{{ t('No messages yet') }}</view>
      <view v-for="message in items" :key="message.id" class="row" role="button" @click="openMessage(message.id)">
        <view class="flex"><text class="message-title">{{ messageCopy(message).title }}</text><view v-if="!message.readAt" class="dot" /></view>
        <text class="body muted" style="margin:10px 0">{{ messageCopy(message).body.slice(0, 160) }}</text><text class="muted">{{ dateTime(message.time) }}</text>
      </view>
      <view class="pagination"><button class="filter" :disabled="page === 1" @click="paginate(-1)">{{ t('Previous') }}</button><button class="filter" :disabled="!hasMore" @click="paginate(1)">{{ t('Next') }}</button></view>
    </LoadState>
  </PageShell>
</template>
