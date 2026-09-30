<script setup lang="ts">
import { ref } from 'vue';
import { onLoad, onShow } from '@dcloudio/uni-app';
import PageShell from '../../components/PageShell.vue';
import LoadState from '../../components/LoadState.vue';
import { request } from '../../lib/api';
import { refreshUnread } from '../../lib/session';
import { useScreen } from '../../lib/screen';
import { t, dateTime } from '../../lib/i18n';
import { messageCopy, type Message } from '../../lib/inbox';
const filters = [
    { key: 'all', title: 'All' },
    { key: 'unread', title: 'Unread' },
    { key: 'business', title: 'Business notifications' },
    { key: 'platform', title: 'Platform notifications' },
];
const filter = ref('all');
const page = ref(1);
const items = ref<Message[]>([]);
const hasMore = ref(false);
let generation = 0;
let markOnEntry = true;
onShow(() => {
    markOnEntry = true;
});
onLoad((query) => {
    if (filters.some((f) => f.key === query?.filter)) filter.value = query!.filter;
    const number = Number(query?.page);
    if (Number.isInteger(number) && number > 0) page.value = number;
});
async function load() {
    const run = ++generation;
    if (markOnEntry) {
        await request('/messages/read-all', 'POST', {});
        if (run !== generation) return;
        markOnEntry = false;
        await refreshUnread();
        if (run !== generation) return;
    }
    const data = await request<{ items: Message[]; hasMore: boolean }>(
        `/messages?filter=${filter.value}&page=${page.value}`,
    );
    if (run !== generation) return;
    items.value = data.items;
    hasMore.value = data.hasMore;
    await refreshUnread();
}
const { loading, failed, refresh } = useScreen(load);
function select(key: string) {
    filter.value = key;
    page.value = 1;
    void refresh();
}
function paginate(delta: number) {
    page.value += delta;
    void refresh();
}
function openMessage(id: string) {
    uni.navigateTo({ url: '/pages/messages/detail?id=' + id });
}
</script>
<template>
    <PageShell :title="t('Messages')" back="/account" active="account" hide-messages>
        <view class="toolbar"
            ><button
                v-for="item in filters"
                :key="item.key"
                class="filter"
                :class="{ selected: filter === item.key }"
                :disabled="loading"
                @click="select(item.key)"
            >
                {{ t(item.title) }}
            </button></view
        >
        <LoadState :loading="loading" :failed="failed" @retry="refresh">
            <view v-if="!items.length" class="empty">{{ t('No messages yet') }}</view>
            <view
                v-for="message in items"
                :key="message.id"
                class="row"
                role="button"
                @click="openMessage(message.id)"
            >
                <view class="flex"
                    ><text class="message-title" :class="{ unread: !message.readAt }">{{
                        messageCopy(message).title
                    }}</text
                    ><view v-if="!message.readAt" class="dot"
                /></view>
                <text class="message-preview muted">{{ messageCopy(message).body }}</text
                ><text class="message-time muted">{{ dateTime(message.time) }}</text>
            </view>
            <view class="pagination"
                ><button v-if="page > 1" class="filter" @click="paginate(-1)">
                    {{ t('Previous') }}</button
                ><button v-if="hasMore" class="filter" @click="paginate(1)">
                    {{ t('Next') }}
                </button></view
            >
        </LoadState>
    </PageShell>
</template>

<style scoped>
.toolbar {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 4px;
    margin: 20px 0 8px;
}
.filter {
    min-height: 44px;
    padding: 8px;
    font-size: 14px;
    line-height: 20px;
    background: none;
    color: #68736e;
    border-radius: 999px;
    margin: 0;
}
.empty {
    padding: 64px 0;
    margin-top: 16px;
}
.filter.selected {
    background: #e7f5ee;
    color: var(--user-primary, #39ad8d);
}
.flex {
    display: flex;
    align-items: flex-start;
    gap: 8px;
}
.row {
    padding: 20px 0;
    border-bottom: 1px solid #e2e7e4;
}
.message-title {
    font-size: 16px;
    font-weight: 500;
    line-height: 24px;
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
}
.message-title.unread {
    font-weight: 600;
}
.dot {
    margin-top: 8px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--user-primary, #39ad8d);
    flex-shrink: 0;
}
.message-preview {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    overflow-wrap: anywhere;
    font-size: 14px;
    line-height: 24px;
    margin-top: 8px;
}
.message-time {
    display: block;
    font-size: 12px;
    line-height: 20px;
    margin-top: 8px;
}
.empty {
    padding: 64px 0;
    text-align: center;
    font-size: 16px;
    color: #68736e;
}
.pagination {
    display: flex;
    justify-content: space-between;
    margin-top: 20px;
}
.pagination > .filter:last-child {
    margin-left: auto;
}
</style>
