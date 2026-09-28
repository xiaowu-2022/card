<script setup lang="ts">
import { nextTick, ref, watch, onBeforeUnmount } from 'vue';
import { onLoad, onShow, onHide } from '@dcloudio/uni-app';
import PageShell from '../../components/PageShell.vue';
import LoadState from '../../components/LoadState.vue';
import { go } from '../../lib/navigation';
import { request } from '../../lib/api';
import { refreshUnread } from '../../lib/session';
import { useScreen } from '../../lib/screen';
import { t, dateTime } from '../../lib/i18n';
import { messageCopy, type Message } from '../../lib/inbox';
const id = ref('');
const message = ref<Message>();
const readFailed = ref(false);
let visible = false;
onShow(() => {
    visible = true;
});
onHide(() => {
    visible = false;
});
onLoad((query) => {
    id.value = query?.id ?? '';
});
async function read() {
    if (!visible) return;
    // #ifdef H5
    if (document.hidden) return;
    // #endif
    try {
        await request(`/messages/${id.value}/read`, 'POST');
        readFailed.value = false;
        if (message.value) message.value.readAt = new Date().toISOString();
        await refreshUnread();
    } catch {
        readFailed.value = true;
    }
}
const { loading, failed, refresh } = useScreen(async () => {
    message.value = await request<Message>(`/messages/${id.value}`);
});
watch(loading, async (value) => {
    if (!value && !failed.value && message.value && !message.value.readAt) {
        await nextTick();
        if (visible) await read();
    }
});
// #ifdef H5
function foreground() {
    if (!document.hidden && visible && message.value && !message.value.readAt) void read();
}
document.addEventListener('visibilitychange', foreground);
onBeforeUnmount(() => document.removeEventListener('visibilitychange', foreground));
// #endif
</script>
<template>
    <PageShell :title="t('Messages')" back="/messages" active="account" hide-messages>
        <LoadState :loading="loading" :failed="failed" @retry="refresh">
            <view v-if="message" class="message-detail"
                ><text class="muted">{{ dateTime(message.time) }}</text
                ><text class="title">{{ messageCopy(message).title }}</text
                ><text class="body">{{ messageCopy(message).body }}</text
                ><button v-if="message.href" class="record-link" @click="go(message.href)">
                    {{ t('View related record') }}
                </button></view
            >
            <view v-if="readFailed" class="error"
                ><text>{{ t('Could not update messages. Please try again.') }}</text
                ><button class="secondary" @click="read">{{ t('Retry') }}</button></view
            >
        </LoadState>
    </PageShell>
</template>

<style scoped>
.message-detail {
    padding: 16px 0;
}
.message-detail > .muted {
    font-size: 14px;
    line-height: 22px;
}
.title {
    display: block;
    font-size: 20px;
    font-weight: 600;
    line-height: 28px;
    margin: 20px 0;
    overflow-wrap: anywhere;
}
.body {
    display: block;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-size: 16px;
    line-height: 32px;
}
.record-link {
    display: block;
    text-align: left;
    margin: 32px 0 0;
    padding: 12px 0;
    background: none;
    font-size: 14px;
    line-height: 22px;
    color: var(--user-primary, #39ad8d);
    text-decoration: underline;
}
</style>
