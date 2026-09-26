<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { onHide, onShow, onUnload } from '@dcloudio/uni-app';
import PageShell from '../../components/PageShell.vue';
import LoadState from '../../components/LoadState.vue';
import SupportImage from '../../components/SupportImage.vue';
import { ApiError, request } from '../../lib/api';
import { refreshUnread } from '../../lib/session';
import { useScreen } from '../../lib/screen';
import { t, dateTime } from '../../lib/i18n';
type Chat = { messages: { id: string; sequence: number; fromSupport: boolean; text: string | null; createdAt: string; imageUrl: string | null }[]; olderCursor: number | null };
const chat = ref<Chat>({ messages: [], olderCursor: null }); const before = ref(0); const draft = ref(''); const busy = ref(false); const actionFailed = ref(false);
// Keep the immutable request intent after a lost response; no duplicate support messages.
let intent: { request_id: string; support_message: string } | null = null;
function requestId() { return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = Math.floor(Math.random() * 16); return (c === 'x' ? r : (r & 3) | 8).toString(16); }); }
async function load() {
    chat.value = await request<Chat>(`/support?before=${before.value}`);
}
const { loading, failed, refresh } = useScreen(load);
let visible = false;
const readFailed = ref(false);
async function acknowledge() {
    await nextTick();
    if (!visible || failed.value || loading.value) return;
    const through = Math.max(0, ...chat.value.messages.map((m) => m.sequence));
    if (!through) return;
    try { await request('/support/read', 'POST', { through }); readFailed.value = false; await refreshUnread(); }
    catch { readFailed.value = true; }
}
watch(loading, (value) => { if (!value) void acknowledge(); });
let timer: ReturnType<typeof setInterval> | undefined;
onShow(() => { visible = true; clearInterval(timer); timer = setInterval(() => { if (!busy.value && !before.value) void refresh(); }, 30000); });
onHide(() => { visible = false; clearInterval(timer); }); onUnload(() => { visible = false; clearInterval(timer); });
async function send() {
    if (busy.value || !draft.value.trim()) return;
    busy.value = true; actionFailed.value = false;
    intent ??= { request_id: requestId(), support_message: draft.value };
    try { await request('/support/messages', 'POST', intent); draft.value = ''; intent = null; before.value = 0; await refresh(); }
    catch (error) { actionFailed.value = true; if (error instanceof ApiError && error.status >= 400 && error.status < 500) intent = null; } finally { busy.value = false; }
}
function older() { before.value = chat.value.olderCursor ?? 0; void refresh(); }
</script>
<template>
  <PageShell :title="t('Online support')" back="/pages/account/index">
    <LoadState :loading="loading" :failed="failed" @retry="refresh">
      <button v-if="chat.olderCursor" class="secondary" @click="older">{{ t('Previous') }}</button>
      <view v-for="message in chat.messages" :key="message.id" class="row" :style="{ textAlign: message.fromSupport ? 'left' : 'right' }"><text class="muted">{{ dateTime(message.createdAt) }}</text><text class="body">{{ message.text }}</text><SupportImage v-if="message.imageUrl" :path="message.imageUrl" /></view>
    </LoadState>
    <button v-if="readFailed" class="secondary" @click="acknowledge">{{ t('Retry') }}</button>
    <view class="stack" style="margin-top:24px"><textarea v-model="draft" class="field" :disabled="busy || !!intent" :maxlength="2000" :placeholder="t('Message')" /><text v-if="actionFailed" class="error">{{ t('Message not confirmed. Your draft is kept; please retry.') }}</text><button class="primary" :disabled="busy || !draft.trim()" @click="send">{{ busy ? t('Sending…') : t('Send message') }}</button></view>
  </PageShell>
</template>
