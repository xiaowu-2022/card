<script setup lang="ts">
import TextInput from '../../components/TextInput.vue';
import { ref, onBeforeUnmount } from 'vue';
import { onShow, onHide, onUnload } from '@dcloudio/uni-app';
import PageShell from '../../components/PageShell.vue';
import { request } from '../../lib/api';
import { requireUser } from '../../lib/session';
import { go } from '../../lib/navigation';
import { t, dateTime } from '../../lib/i18n';
import { supportDenied } from '../../lib/support-workspace';
type Data = {
    profile: { support_name: string | null; revision: number };
    inbox: {
        data: {
            id: string;
            accountId: string;
            name: string | null;
            mode: string;
            updatedAt: string;
        }[];
        current_page: number;
        last_page: number;
    };
};
const data = ref<Data | null>(null),
    search = ref(''),
    status = ref(0),
    page = ref(1),
    busy = ref(false),
    error = ref(false),
    nickname = ref(''),
    editing = ref(false);
const statuses = ['awaiting', 'ALL', 'WAITING', 'HUMAN', 'BOT'],
    labels = [
        'Awaiting reply',
        'All conversations',
        'Waiting for human support',
        'Human support',
        'Bot support',
    ];
let visible = false,
    timer: ReturnType<typeof setInterval> | undefined;
async function load(reset = false) {
    if (busy.value) return;
    if (reset) page.value = 1;
    busy.value = true;
    error.value = false;
    try {
        if (!(await requireUser())) return;
        data.value = await request<Data>(
            '/support-workspace?status=' +
                statuses[status.value] +
                '&search=' +
                encodeURIComponent(search.value) +
                '&page=' +
                page.value,
        );
        if (!editing.value) nickname.value = data.value.profile.support_name ?? '';
    } catch (e) {
        error.value = true;
        if (supportDenied(e)) {
            stop();
            data.value = null;
        }
    } finally {
        busy.value = false;
    }
}
async function save() {
    if (busy.value || !data.value) return;
    busy.value = true;
    error.value = false;
    try {
        await request('/support-workspace/profile', 'POST', {
            support_name: nickname.value,
            revision: data.value.profile.revision,
        });
        editing.value = false;
    } catch (e) {
        error.value = true;
        if (supportDenied(e)) stop();
    } finally {
        busy.value = false;
    }
    if (!error.value) await load();
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
        ><view class="workspace">
            <view class="panel"
                ><text>{{ t('Support nickname') }}</text
                ><view class="controls"
                    ><TextInput
                        class="support-field"
                        v-model="nickname"
                        :maxlength="30"
                        :placeholder="t('Customer support')"
                        @input="editing = true"
                    /><button class="secondary" :disabled="busy || !editing" @click="save">
                        {{ t('Save') }}
                    </button></view
                ><button class="text-button" @click="go('/support-workspace/replies')">
                    {{ t('My quick replies') }} ›
                </button></view
            >
            <view class="panel"
                ><view class="controls"
                    ><TextInput
                        class="support-field"
                        v-model="search"
                        :maxlength="120"
                        :placeholder="t('Search name, account ID or email')"
                    /><button class="secondary" :disabled="busy" @click="load(true)">
                        {{ t('Search') }}
                    </button></view
                ><picker
                    :range="labels.map((x) => t(x))"
                    :value="status"
                    @change="
                        status = Number($event.detail.value);
                        load(true);
                    "
                    ><view class="filter">{{ t(labels[status]) }} ▾</view></picker
                ></view
            >
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
                    <view
                        ><text>{{ row.name || row.accountId }}</text
                        ><text
                            >{{ row.accountId }} ·
                            {{
                                t(
                                    row.mode === 'BOT'
                                        ? 'Bot support'
                                        : row.mode === 'WAITING'
                                          ? 'Waiting for human support'
                                          : 'Human support',
                                )
                            }}</text
                        ></view
                    ><text>{{ dateTime(row.updatedAt) }}</text></button
                ><text v-if="data && !data.inbox.data.length">{{
                    t('No customer conversations yet.')
                }}</text></view
            >
            <view class="controls" v-if="data"
                ><button
                    class="secondary"
                    :disabled="busy || page <= 1"
                    @click="
                        page--;
                        load();
                    "
                >
                    {{ t('Previous') }}</button
                ><text>{{ page }} / {{ data.inbox.last_page }}</text
                ><button
                    class="secondary"
                    :disabled="busy || page >= data.inbox.last_page"
                    @click="
                        page++;
                        load();
                    "
                >
                    {{ t('Next') }}
                </button></view
            >
        </view></PageShell
    >
</template>
<style scoped>
.workspace {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding-top: 16px;
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
.text-button {
    background: transparent;
    text-align: left;
    color: #37604a;
}
.filter {
    padding: 10px;
    background: #f3f6f2;
    border-radius: 10px;
}
.conversation {
    background: white;
    text-align: left;
    border-bottom: 1px solid #e2e7e4;
    display: flex;
    flex-direction: column;
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
