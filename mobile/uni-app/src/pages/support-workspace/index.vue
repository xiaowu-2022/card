<script setup lang="ts">
import TextInput from '../../components/TextInput.vue';
import { ref, onBeforeUnmount } from 'vue';
import { onShow, onHide, onUnload, onReachBottom } from '@dcloudio/uni-app';
import PageShell from '../../components/PageShell.vue';
import Modal from '../../components/Modal.vue';
import ViewportLayer from '../../components/ViewportLayer.vue';
import { request } from '../../lib/api';
import { requireUser } from '../../lib/session';
import { go } from '../../lib/navigation';
import { t, dateTime, locale } from '../../lib/i18n';
import { supportDenied } from '../../lib/support-workspace';
type Data = {
    unreadMessageCount: number;
    profile: { support_name: string | null; revision: number };
    inbox: {
        data: {
            id: string;
            accountId: string;
            name: string | null;
            online: boolean;
            mode: string;
            updatedAt: string;
            lastMessageAt: string | null;
            unreadCount: number;
            lastMessage: { text: string; deleted: boolean; image: boolean } | null;
        }[];
        current_page: number;
        last_page: number;
    };
};
const data = ref<Data | null>(null),
    search = ref(''),
    page = ref(1),
    activeSearch = ref(''),
    loadingMore = ref(false),
    busy = ref(false),
    error = ref(false),
    nickname = ref(''),
    editing = ref(false),
    settingsOpen = ref(false),
    settingsView = ref<'menu' | 'name'>('menu'),
    saveError = ref(false);
let visible = false,
    timer: ReturnType<typeof setInterval> | undefined;
async function load(reset = false, append = false) {
    if (busy.value || settingsOpen.value) return;
    if (append && (!data.value || page.value >= data.value.inbox.last_page)) return;
    const query = reset ? search.value.trim() : activeSearch.value;
    const targetPage = reset ? 1 : append ? page.value + 1 : page.value;
    busy.value = true;
    loadingMore.value = append;
    error.value = false;
    try {
        if (!(await requireUser())) return;
        // Refresh the loaded range atomically, so polling never discards older rows.
        let rows: Data['inbox']['data'] = append ? [...(data.value?.inbox.data ?? [])] : [];
        let result: Data | undefined;
        let loadedPage = 1;
        for (let next = append ? targetPage : 1; next <= targetPage; next++) {
            result = await request<Data>(
                '/support-workspace?search=' + encodeURIComponent(query) + '&page=' + next,
            );
            rows.push(...result.inbox.data);
            loadedPage = next;
            if (next >= result.inbox.last_page) break;
        }
        if (!result) return;
        const seen = new Set<string>();
        result.inbox.data = rows.filter((row) => {
            if (seen.has(row.id)) return false;
            seen.add(row.id);
            return true;
        });
        data.value = result;
        page.value = loadedPage;
        activeSearch.value = query;
        if (!editing.value) nickname.value = result.profile.support_name ?? '';
    } catch (e) {
        error.value = true;
        if (supportDenied(e)) {
            stop();
            data.value = null;
        }
    } finally {
        busy.value = false;
        loadingMore.value = false;
    }
}
onReachBottom(() => {
    if (visible) void load(false, true);
});
function messageTime(value: string) {
    const date = new Date(value),
        now = new Date();
    const today = date.toDateString() === now.toDateString();
    return new Intl.DateTimeFormat(locale.value, {
        ...(today ? {} : { month: '2-digit' as const, day: '2-digit' as const }),
        ...(date.getFullYear() === now.getFullYear() ? {} : { year: 'numeric' as const }),
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(date);
}
function openSettings() {
    if (busy.value || !data.value) return;
    settingsView.value = 'menu';
    settingsOpen.value = true;
}
function editNickname() {
    if (!data.value) return;
    nickname.value = data.value.profile.support_name ?? '';
    editing.value = false;
    saveError.value = false;
    settingsView.value = 'name';
}
function openReplies() {
    settingsOpen.value = false;
    go('/support-workspace/replies');
}
async function save() {
    if (busy.value || !data.value) return;
    busy.value = true;
    saveError.value = false;
    try {
        await request('/support-workspace/profile', 'POST', {
            support_name: nickname.value,
            revision: data.value.profile.revision,
        });
        editing.value = false;
        settingsOpen.value = false;
    } catch (e) {
        saveError.value = true;
        if (supportDenied(e)) stop();
    } finally {
        busy.value = false;
    }
    if (!saveError.value) await load();
}
function stop() {
    visible = false;
    clearInterval(timer);
}
onShow(() => {
    visible = true;
    void load();
    clearInterval(timer);
    timer = setInterval(() => {
        if (!visible) return;
        // #ifdef H5
        if (document.visibilityState !== 'visible') return;
        // #endif
        void load();
    }, 5000);
});
onHide(stop);
onUnload(stop);
onBeforeUnmount(stop);
</script>
<template>
    <PageShell :title="t('Support workspace')" back="/account" active="account"
        ><template #header-title>
            <view class="workspace-title">
                <text>{{ t('Support workspace') }}</text>
                <text
                    v-if="data && data.unreadMessageCount > 0"
                    class="unread-badge"
                    :aria-label="t('Unread messages') + ': ' + data.unreadMessageCount"
                    >{{ data.unreadMessageCount }}</text
                >
            </view>
        </template>
        <template #header-right>
            <!-- #ifdef H5 -->
            <component
                :is="'button'"
                class="settings-button"
                :disabled="busy || !data"
                @click="openSettings"
            >
                {{ t('Settings') }}
            </component>
            <!-- #endif -->
            <!-- #ifndef H5 -->
            <button class="settings-button" :disabled="busy || !data" @click="openSettings">
                {{ t('Settings') }}
            </button>
            <!-- #endif -->
        </template>
        <ViewportLayer>
            <view class="workspace-toolbar">
                <view class="controls">
                    <TextInput
                        class="support-field"
                        v-model="search"
                        :maxlength="120"
                        :placeholder="t('Search name, account ID or email')"
                        @confirm="load(true)"
                    />
                    <button class="secondary search-button" :disabled="busy" @click="load(true)">
                        {{ t('Search') }}
                    </button>
                </view>
            </view>
        </ViewportLayer>
        <view class="workspace">
            <text v-if="error" class="error">{{
                t('Unable to complete this request. Refresh and try again.')
            }}</text>
            <view class="panel"
                ><button
                    v-for="row in data?.inbox.data ?? []"
                    :key="row.id"
                    class="conversation"
                    @click="go('/support-workspace/chat?conversation=' + row.id)"
                >
                    <view class="conversation-info"
                        ><text
                            >{{ row.name || row.accountId }} ·
                            {{ t(row.online ? 'Online' : 'Offline') }}</text
                        ><text class="conversation-preview">{{
                            row.lastMessage?.deleted
                                ? t('Message deleted')
                                : [
                                      row.lastMessage?.image ? t('[Image]') : '',
                                      row.lastMessage?.text,
                                  ]
                                      .filter(Boolean)
                                      .join(' ')
                        }}</text></view
                    ><view class="conversation-meta"
                        ><text
                            class="conversation-time"
                            v-if="row.lastMessageAt"
                            :title="dateTime(row.lastMessageAt)"
                            >{{ messageTime(row.lastMessageAt) }}</text
                        ><text
                            v-if="row.unreadCount"
                            class="unread-badge"
                            :aria-label="t('Unread messages') + ': ' + row.unreadCount"
                            >{{ row.unreadCount > 99 ? '99+' : row.unreadCount }}</text
                        ></view
                    ></button
                ><text v-if="data && !data.inbox.data.length">{{
                    t('No customer conversations yet.')
                }}</text></view
            >
            <view class="list-footer" aria-live="polite">
                <text v-if="busy">{{
                    t(loadingMore ? 'Loading more conversations…' : 'Loading…')
                }}</text>
                <button
                    v-else-if="error"
                    class="secondary"
                    @click="load(false, !!data && page < data.inbox.last_page)"
                >
                    {{ t('Retry loading conversations') }}
                </button>
                <text v-else-if="data?.inbox.data.length && page >= data.inbox.last_page">{{
                    t('All conversations loaded')
                }}</text>
            </view>
        </view>
        <Modal
            :open="settingsOpen"
            :title="t(settingsView === 'menu' ? 'Settings' : 'Change support name')"
            :busy="busy"
            @close="settingsOpen = false"
        >
            <view v-if="settingsView === 'menu'" class="settings-menu">
                <button class="secondary" @click="editNickname">
                    {{ t('Change support name') }} ›
                </button>
                <button class="secondary" @click="openReplies">
                    {{ t('Manage quick replies') }} ›
                </button>
            </view>
            <view v-else class="settings-form">
                <text>{{ t('Support nickname') }}</text>
                <TextInput
                    class="support-field"
                    v-model="nickname"
                    :aria-label="t('Support nickname')"
                    :maxlength="30"
                    :placeholder="t('Customer support')"
                    :disabled="busy"
                    @input="editing = true"
                />
                <text v-if="saveError" class="error">{{
                    t('Unable to complete this request. Refresh and try again.')
                }}</text>
                <view class="controls">
                    <button class="secondary" :disabled="busy" @click="settingsOpen = false">
                        {{ t('Cancel') }}
                    </button>
                    <button class="primary" :disabled="busy || !editing" @click="save">
                        {{ t('Save') }}
                    </button>
                </view>
            </view>
        </Modal>
    </PageShell>
</template>
<style scoped>
.settings-button {
    margin: 0;
    padding: 0;
    background: transparent;
    border: 0;
    cursor: pointer;
    color: #37604a;
    font-size: 14px;
    line-height: 36px;
}
.settings-button::after {
    border: none;
}
.settings-menu {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.settings-menu button {
    margin: 0;
    padding: 12px 16px;
    text-align: left;
    font-size: 15px;
    line-height: 24px;
    border-radius: 12px;
}
.settings-form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.settings-form .controls > button {
    flex: 1;
    margin: 0;
}
.list-footer {
    text-align: center;
    color: #84918b;
    font-size: 12px;
    padding: 8px 0 16px;
}
.workspace {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding-top: 68px;
}
.panel {
    background: #fff;
    border: 1px solid #e2e7e4;
    border-radius: 16px;
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.controls {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: space-between;
}
.controls input {
    flex: 1;
    min-width: 0;
    border: 1px solid #dce3dd;
    border-radius: 10px;
    padding: 12px;
    font-size: 14px;
}
.workspace button {
    margin: 0;
    line-height: 24px;
    font-size: 14px;
    padding: 10px 14px;
    border-radius: 12px;
}
.workspace-toolbar {
    position: fixed;
    top: calc(clamp(64px, 12vw, 90px) + env(safe-area-inset-top, 0px));
    left: 0;
    right: 0;
    max-width: 750px;
    margin: 0 auto;
    box-sizing: border-box;
    padding: 12px min(4.267vw, 32px);
    background: #f7f6f0;
    z-index: 49;
    box-shadow: 0 3px 8px #171c190a;
}
.search-button {
    margin: 0;
    padding: 0 14px;
    height: 44px;
    line-height: 44px;
    font-size: 14px;
    flex-shrink: 0;
}
.workspace-title {
    min-width: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 20px;
    font-weight: 600;
}
.workspace-title > text:first-child {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.unread-badge {
    flex-shrink: 0;
    min-width: 18px;
    padding: 0 5px;
    box-sizing: border-box;
    border-radius: 10px;
    line-height: 18px;
    font-size: 11px;
    color: #fff;
    background: #dc5353;
    text-align: center;
}
.conversation-preview {
    display: block;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    font-size: 13px;
    color: #84918b;
}
.conversation-info {
    flex: 1;
    min-width: 0;
}
.conversation .conversation-meta {
    align-items: flex-end;
    gap: 8px;
    flex-shrink: 0;
}
.conversation-time {
    font-size: 11px;
    color: #84918b;
}
.conversation {
    background: white;
    text-align: left;
    border-bottom: 1px solid #e2e7e4;
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: 8px;
}
.conversation view {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.conversation > text {
    font-size: 12px;
    color: #737e77;
}
.error {
    color: #9d3f20;
    font-size: 13px;
}
.web-text-input {
    height: 44px;
    box-sizing: border-box;
}
.support-field {
    height: 44px;
    box-sizing: border-box;
    min-width: 0;
    width: 100%;
    border: 1px solid #dce3dd;
    border-radius: 10px;
    padding: 10px 12px;
    background: white;
    font-size: 14px;
}
.controls .support-field,
.reply-heading .support-field {
    flex: 1;
    width: 0;
}
</style>
