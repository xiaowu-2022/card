<script setup lang="ts">
import { computed, nextTick, ref, watch, onBeforeUnmount } from 'vue';
import { onHide, onShow, onUnload, onLoad } from '@dcloudio/uni-app';
import SupportReplyPicker from '../../components/SupportReplyPicker.vue';
import { supportDenied } from '../../lib/support-workspace';
import PageShell from '../../components/PageShell.vue';
import { go } from '../../lib/navigation';
import Modal from '../../components/Modal.vue';
import SupportImage from '../../components/SupportImage.vue';
import UiIcon from '../../components/UiIcon.vue';
import { ApiError, request, upload } from '../../lib/api';
import { requestId } from '../../lib/client';
import { refreshUnread, requireUser } from '../../lib/session';
import { t, dateTime } from '../../lib/i18n';
type Chat = {
    customerOnline?: boolean;
    customerName?: string;
    customerEmail?: string | null;
    humanSupport?: { available: boolean; timezone: string; nextOpenAt: string | null };
    mode?: 'BOT' | 'WAITING' | 'HUMAN';
    revision?: number;
    botEnabled?: boolean;
    messages: {
        id: string;
        sequence: number;
        fromSupport: boolean;
        senderKind?: 'BOT' | 'ADMIN' | 'USER' | 'SUPPORT_AGENT';
        supportName?: string | null;
        text: string | null;
        createdAt: string;
        imageUrl: string | null;
        imageSources?: string[];
        readByUser?: boolean | null;
        canManage?: boolean;
        deleted?: boolean;
        edited?: boolean;
        messageRevision?: number;
    }[];
    olderCursor: number | null;
};
type Message = Chat['messages'][number];
const selectedMessage = ref<Message | null>(null),
    changeMode = ref<'menu' | 'edit' | 'delete'>('menu'),
    changedText = ref(''),
    changing = ref(false),
    changeFailed = ref(false);
let changeIntent: { request_id: string; revision: number; operation: string; text: string } | null =
    null;
function messageMenu(message: Message) {
    if (!agent.value || !message.canManage || message.deleted || changing.value) return;
    selectedMessage.value = { ...message };
    changedText.value = message.text ?? '';
    changeMode.value = 'menu';
    changeFailed.value = false;
    changeIntent = null;
}
function closeMessageMenu() {
    if (changing.value) return;
    selectedMessage.value = null;
    changeIntent = null;
}
async function changeMessage() {
    const message = selectedMessage.value;
    if (!message || changing.value || changeMode.value === 'menu') return;
    const operation = changeMode.value === 'delete' ? 'DELETE' : 'EDIT';
    if (operation === 'EDIT' && !changedText.value.trim()) return;
    changing.value = true;
    changeFailed.value = false;
    changeIntent ??= {
        request_id: requestId(),
        revision: message.messageRevision ?? 0,
        operation,
        text: operation === 'EDIT' ? changedText.value.trim() : '',
    };
    try {
        await request(base.value + '/messages/' + message.id + '/change', 'POST', changeIntent);
        selectedMessage.value = null;
        changeIntent = null;
        await load();
    } catch (e) {
        changeFailed.value = true;
        if (supportDenied(e)) {
            stop();
            selectedMessage.value = null;
        }
    } finally {
        changing.value = false;
    }
}
const composerId = 'support-composer-' + requestId();
const conversation = ref('');
const agent = computed(() => !!conversation.value);
const base = computed(() =>
    agent.value ? '/support-workspace/conversations/' + conversation.value : '/support',
);
onLoad((options) => {
    if (options?.conversation) conversation.value = String(options.conversation);
});
const picker = ref(false),
    composerFocus = ref(false),
    cursor = ref(-1);
let selectionStart = -1,
    selectionEnd = -1;
function rememberSelection(e?: unknown) {
    const detail = (e as { detail?: { cursor?: number } } | undefined)?.detail;
    if (typeof detail?.cursor === 'number') selectionStart = selectionEnd = detail.cursor;
    // #ifdef H5
    const area = document
        .getElementById(composerId)
        ?.querySelector('textarea') as HTMLTextAreaElement | null;
    if (area) {
        selectionStart = area.selectionStart;
        selectionEnd = area.selectionEnd;
    }
    // #endif
}
async function insertReply(body: string) {
    const start =
        selectionStart < 0 ? draft.value.length : Math.min(selectionStart, draft.value.length);
    const end = selectionEnd < start ? start : Math.min(selectionEnd, draft.value.length);
    const result = draft.value.slice(0, start) + body + draft.value.slice(end);
    if (result.length > 2000) {
        uni.showToast({ title: t('Reply would exceed the message limit.'), icon: 'none' });
        return;
    }
    draft.value = result;
    picker.value = false;
    composerFocus.value = false;
    await nextTick();
    cursor.value = start + body.length;
    selectionStart = selectionEnd = cursor.value;
    composerFocus.value = true;
}
const chat = ref<Chat>({ messages: [], olderCursor: null }),
    before = ref(0),
    draft = ref(''),
    image = ref(''),
    busy = ref(false),
    actionFailed = ref(false),
    imageFailed = ref(false),
    loading = ref(true),
    failed = ref(false),
    disconnected = ref(false),
    readFailed = ref(false),
    scrollTo = ref('');
const handoffFailed = ref(false);
const polling = ref(false);
let handoffIntent: string | null = null;
async function handoff() {
    if (busy.value || loading.value || polling.value) return;
    busy.value = true;
    handoffFailed.value = false;
    handoffIntent ??= requestId();
    try {
        await request('/support/handoff', 'POST', { request_id: handoffIntent });
        handoffIntent = null;
        before.value = 0;
        await load(true);
    } catch (error) {
        if (error instanceof ApiError && error.payload?.error?.code === 'SUPPORT_OFFLINE') {
            chat.value.humanSupport = {
                ...chat.value.humanSupport,
                available: false,
                timezone: chat.value.humanSupport?.timezone ?? '',
                nextOpenAt: null,
            };
            await load(true);
        } else handoffFailed.value = true;
    } finally {
        busy.value = false;
    }
}
let intent: { request_id: string; support_message: string; image: string } | null = null;
const locked = ref(false);
let visible = false,
    followLatest = true,
    acknowledged = 0,
    acknowledgedRevisions = '',
    generation = 0,
    timer: ReturnType<typeof setInterval> | undefined;
function foreground() {
    // #ifdef H5
    if (document.visibilityState !== 'visible') return false;
    // #endif
    return visible;
}
async function load(silent = false) {
    if (polling.value) return;
    polling.value = true;
    const run = ++generation;
    if (!silent) loading.value = true;
    try {
        if (!(await requireUser())) return;
        const data = await request<Chat>(base.value + '?before=' + before.value);
        if (run !== generation) return;
        chat.value = data;
        failed.value = false;
        disconnected.value = false;
        await nextTick();
        if (followLatest && before.value === 0) {
            scrollTo.value = '';
            await nextTick();
            scrollTo.value = 'support-last';
        }
        await acknowledge();
    } catch (error) {
        if (agent.value && supportDenied(error)) {
            stop();
            chat.value.messages = [];
            picker.value = false;
            return;
        }
        if (silent) disconnected.value = true;
        else failed.value = true;
    } finally {
        polling.value = false;
        loading.value = false;
    }
}
async function acknowledge() {
    await nextTick();
    if (!foreground() || failed.value || (agent.value && !followLatest)) return;
    const through = Math.max(0, ...chat.value.messages.map((m) => m.sequence));
    const revisions = chat.value.messages
        .filter((m) => (m.messageRevision ?? 0) > 0)
        .map((m) => ({ id: m.id, revision: m.messageRevision! }));
    const revisionKey = JSON.stringify(revisions);
    if (!through || (through <= acknowledged && revisionKey === acknowledgedRevisions)) return;
    try {
        await request(
            agent.value ? base.value + '/read' : '/support/read',
            'POST',
            agent.value ? { through } : { through, revisions },
        );
        acknowledgedRevisions = revisionKey;
        acknowledged = through;
        readFailed.value = false;
        await refreshUnread();
    } catch {
        readFailed.value = true;
    }
}
function tick() {
    if (!foreground() || busy.value || changing.value) return;
    void load(true);
}
onShow(() => {
    visible = true;
    void load();
    clearInterval(timer);
    timer = setInterval(tick, 5000);
});
function stop() {
    visible = false;
    clearInterval(timer);
}
onHide(stop);
onUnload(stop);
onBeforeUnmount(() => {
    stop();
    // #ifdef H5
    document.removeEventListener('visibilitychange', focus);
    window.removeEventListener('focus', focus);
    // #endif
});
function focus() {
    if (foreground()) {
        void acknowledge();
        tick();
    }
}
// #ifdef H5
document.addEventListener('visibilitychange', focus);
window.addEventListener('focus', focus);
// #endif
async function send() {
    if (busy.value || (!draft.value.trim() && !image.value)) return;
    busy.value = true;
    actionFailed.value = false;
    imageFailed.value = false;
    intent ??= { request_id: requestId(), support_message: draft.value, image: image.value };
    locked.value = true;
    try {
        const data = { request_id: intent.request_id, support_message: intent.support_message };
        if (intent.image)
            await upload(base.value + '/messages', data, [
                { name: 'support_image', path: intent.image },
            ]);
        else await request(base.value + '/messages', 'POST', data);
        draft.value = '';
        image.value = '';
        intent = null;
        locked.value = false;
        before.value = 0;
        followLatest = true;
        await load(true);
    } catch (error) {
        if (agent.value && supportDenied(error)) {
            stop();
            return;
        }
        actionFailed.value = true;
        if (error instanceof ApiError && error.status === 422) {
            imageFailed.value = !!error.payload?.errors?.support_image;
            intent = null;
            locked.value = false;
        }
    } finally {
        busy.value = false;
    }
}
async function chooseImage() {
    if (busy.value || locked.value) return;
    imageFailed.value = false;
    try {
        const result = await new Promise<UniApp.ChooseImageSuccessCallbackResult>(
            (resolve, reject) =>
                uni.chooseImage({
                    count: 1,
                    sizeType: ['original'],
                    sourceType: ['album', 'camera'],
                    success: resolve,
                    fail: reject,
                }),
        );
        const file = Array.isArray(result.tempFiles) ? result.tempFiles[0] : null;
        if (!file || file.size > 5 * 1024 * 1024) {
            imageFailed.value = true;
            return;
        }
        const info = await new Promise<UniApp.GetImageInfoSuccessData>((resolve, reject) =>
            uni.getImageInfo({ src: result.tempFilePaths[0], success: resolve, fail: reject }),
        );
        let supported = ['jpg', 'jpeg', 'png', 'webp'].includes((info.type ?? '').toLowerCase());
        // H5 reports dimensions without a type. Validate the selected local blob,
        // rather than rejecting every browser upload or trusting its file extension.
        // #ifdef H5
        const path = result.tempFilePaths[0];
        if (!path?.startsWith('blob:')) throw new Error('Invalid selected image');
        const blob = await (await fetch(path)).blob();
        const bytes = new Uint8Array(await blob.slice(0, 12).arrayBuffer());
        const png = [137, 80, 78, 71, 13, 10, 26, 10].every((byte, i) => bytes[i] === byte);
        const jpeg = bytes[0] === 255 && bytes[1] === 216 && bytes[2] === 255;
        const webp =
            String.fromCharCode(...bytes.slice(0, 4)) === 'RIFF' &&
            String.fromCharCode(...bytes.slice(8, 12)) === 'WEBP';
        supported = blob.size <= 5 * 1024 * 1024 && (png || jpeg || webp);
        // #endif
        if (info.width * info.height > 20000000 || !supported) {
            imageFailed.value = true;
            return;
        }
        image.value = result.tempFilePaths[0];
    } catch (error) {
        // Cancellation is silent; unreadable or malformed selected images are not.
        const message =
            typeof error === 'object' && error !== null && 'errMsg' in error
                ? String(error.errMsg)
                : '';
        if (!/cancel/i.test(message)) imageFailed.value = true;
    }
}
function navigate(cursor: number) {
    if (polling.value) return;
    before.value = cursor;
    followLatest = cursor === 0;
    void load();
}
function scrolled(e: { detail: { scrollHeight: number; scrollTop: number } }) {
    uni.createSelectorQuery()
        .select('.support-messages')
        .boundingClientRect((rect) => {
            if (rect && !Array.isArray(rect))
                followLatest = e.detail.scrollHeight - e.detail.scrollTop - (rect.height ?? 0) < 80;
        })
        .exec();
}
function updateDraft(event: unknown) {
    const value = event as { detail?: { value?: string }; target?: { value?: string } };
    draft.value = value.detail?.value ?? value.target?.value ?? '';
}
</script>
<template>
    <PageShell
        :title="agent ? chat.customerName || t('Customer') : t('Customer support')"
        :back="agent ? '/support-workspace' : '/account'"
        active="account"
        hide-messages
        chat
        ><template v-if="agent" #header-title>
            <view class="customer-heading">
                <view class="customer-title">
                    <text class="customer-name">{{ chat.customerName || t('Customer') }}</text>
                    <text
                        v-if="chat.customerOnline !== undefined"
                        class="customer-status"
                        :class="{ online: chat.customerOnline }"
                    >
                        <text class="presence-dot" />{{
                            t(chat.customerOnline ? 'Online' : 'Offline')
                        }}
                    </text>
                </view>
                <text v-if="chat.customerEmail" class="customer-email">{{
                    chat.customerEmail
                }}</text>
            </view>
        </template>
        <view class="support-thread"
            ><view v-if="!agent && chat.mode" class="support-mode"
                ><text>{{
                    t(
                        chat.mode === 'BOT'
                            ? 'Bot support'
                            : chat.mode === 'WAITING'
                              ? 'Waiting for human support'
                              : 'Human support',
                    )
                }}</text
                ><button
                    v-if="!agent && chat.mode === 'BOT'"
                    class="secondary"
                    :disabled="busy || loading || polling || chat.humanSupport?.available === false"
                    @click="handoff"
                >
                    {{ t('Talk to a person') }}
                </button></view
            ><view
                v-if="!agent && chat.humanSupport?.available === false"
                class="support-offline"
                role="status"
                ><text>{{ t('Customer support is currently offline.') }}</text
                ><text v-if="chat.humanSupport.nextOpenAt"
                    >{{ t('Next service time') }}: {{ dateTime(chat.humanSupport.nextOpenAt) }} ({{
                        chat.humanSupport.timezone
                    }})</text
                ><text v-else>{{ t('No upcoming service hours are scheduled.') }}</text></view
            ><text v-if="handoffFailed" class="support-error">{{
                t('Unable to transfer. Please try again.')
            }}</text
            ><text v-if="disconnected" class="support-error">{{
                t('Connection interrupted. Reconnecting… Your draft is saved on this page.')
            }}</text
            ><view class="support-history-actions"
                ><button
                    v-if="chat.olderCursor"
                    class="text-button"
                    :disabled="loading"
                    @click="navigate(chat.olderCursor)"
                >
                    {{ t('Earlier messages') }}</button
                ><button
                    v-if="before > 0"
                    class="secondary"
                    :disabled="loading"
                    @click="navigate(0)"
                >
                    {{ t('Latest messages') }}
                </button></view
            ><scroll-view
                class="support-messages"
                scroll-y
                :scroll-into-view="scrollTo"
                @scroll="scrolled"
                ><view v-if="loading" class="loading muted">{{ t('Loading…') }}</view
                ><view v-else-if="failed" class="loading"
                    ><text>{{ t('Unable to load. Please try again.') }}</text
                    ><button class="secondary" @click="load()">{{ t('Retry') }}</button></view
                ><view v-else-if="!chat.messages.length" class="support-empty"
                    ><UiIcon name="support" :size="36" /><text class="empty-title">{{
                        t('How can we help?')
                    }}</text
                    ><text>{{
                        t('Send a message or image. Your company support team can reply here.')
                    }}</text></view
                ><view
                    v-for="message in chat.messages"
                    :key="message.id"
                    class="support-message"
                    :class="{
                        'has-customer-avatar':
                            agent && !message.fromSupport && message.senderKind !== 'BOT',
                        'support-message-own':
                            message.senderKind !== 'BOT' &&
                            (agent ? message.fromSupport : !message.fromSupport),
                    }"
                    ><button
                        v-if="agent && !message.fromSupport && message.senderKind !== 'BOT'"
                        class="customer-avatar"
                        :aria-label="t('Customer profile')"
                        @click="go('/support-workspace/customer?conversation=' + conversation)"
                    >
                        {{ Array.from(chat.customerName || t('Customer'))[0] }}</button
                    ><text class="support-sender">{{
                        message.senderKind === 'BOT'
                            ? t('Support assistant')
                            : message.fromSupport
                              ? message.supportName || t('Customer support')
                              : agent
                                ? chat.customerName || t('Customer')
                                : t('You')
                    }}</text
                    ><view
                        class="support-bubble"
                        :role="agent && message.canManage ? 'button' : undefined"
                        :tabindex="agent && message.canManage ? 0 : undefined"
                        @keydown.enter.prevent="messageMenu(message)"
                        @longpress="messageMenu(message)"
                        @contextmenu.prevent="messageMenu(message)"
                        ><text v-if="message.deleted" class="deleted-message">{{
                            t('Message deleted')
                        }}</text>
                        <SupportImage
                            v-if="!message.deleted && message.imageUrl"
                            :path="message.imageUrl"
                            :sources="message.imageSources"
                        /><text v-if="!message.deleted && message.text" class="support-text">{{
                            message.text
                        }}</text></view
                    ><text class="support-time"
                        >{{ dateTime(message.createdAt)
                        }}<text v-if="message.edited && !message.deleted"> · {{ t('Edited') }}</text
                        ><text v-if="agent && message.readByUser != null">
                            · {{ t(message.readByUser ? 'Read' : 'Unread') }}</text
                        ></text
                    ></view
                ><view id="support-last" style="height: 1px" /></scroll-view
            ><button v-if="readFailed" class="text-button" @click="acknowledge">
                {{ t('Retry') }}
            </button>
            <button
                v-if="agent"
                class="secondary"
                :disabled="busy || locked"
                @touchstart="rememberSelection()"
                @mousedown="rememberSelection()"
                @click="picker = true"
            >
                {{ t('Quick replies') }}
            </button>
            <Modal
                :open="!!selectedMessage"
                :title="
                    t(
                        changeMode === 'edit'
                            ? 'Edit message'
                            : changeMode === 'delete'
                              ? 'Delete message'
                              : 'Message actions',
                    )
                "
                :busy="changing"
                @close="closeMessageMenu"
            >
                <view class="message-actions">
                    <template v-if="changeMode === 'menu'">
                        <button
                            class="secondary"
                            role="button"
                            tabindex="0"
                            @keydown.enter.prevent="changeMode = 'edit'"
                            @click="changeMode = 'edit'"
                        >
                            {{ t('Edit message') }}
                        </button>
                        <button
                            class="secondary"
                            role="button"
                            tabindex="0"
                            @keydown.enter.prevent="changeMode = 'delete'"
                            @click="changeMode = 'delete'"
                        >
                            {{ t('Delete message') }}
                        </button>
                    </template>
                    <template v-else>
                        <textarea
                            v-if="changeMode === 'edit'"
                            v-model="changedText"
                            :maxlength="2000"
                            :disabled="changing || !!changeIntent"
                            :aria-label="t('Edit message')"
                            class="edit-message-field"
                        />
                        <text v-else>{{ t('Delete this message for everyone?') }}</text>
                        <text v-if="changeFailed" class="support-error">{{
                            t('Message change failed. Retry or reopen the latest message.')
                        }}</text>
                        <button
                            class="secondary"
                            role="button"
                            tabindex="0"
                            :disabled="changing"
                            @keydown.enter.prevent="closeMessageMenu"
                            @click="closeMessageMenu"
                        >
                            {{ t('Cancel') }}
                        </button>
                        <button
                            class="primary"
                            :disabled="changing || (changeMode === 'edit' && !changedText.trim())"
                            role="button"
                            tabindex="0"
                            @keydown.enter.prevent="changeMessage"
                            @click="changeMessage"
                        >
                            {{ t(changeMode === 'delete' ? 'Delete message' : 'Save') }}
                        </button>
                    </template>
                </view>
            </Modal>
            <SupportReplyPicker v-if="picker" @select="insertReply" @close="picker = false" />
            <form class="support-composer" @submit="send">
                <view v-if="image" class="support-preview"
                    ><image :src="image" mode="aspectFit" /><button
                        class="secondary"
                        :disabled="busy || locked"
                        :aria-label="t('Remove image')"
                        @click="image = ''"
                    >
                        ×
                    </button></view
                >
                <view class="support-composer-actions">
                    <button
                        class="secondary support-attach"
                        :disabled="busy || locked"
                        :aria-label="t('Attach image')"
                        @click="chooseImage"
                    >
                        <UiIcon name="image-plus" :size="20" />
                    </button>
                    <textarea
                        :id="composerId"
                        :value="draft"
                        @input="updateDraft"
                        :focus="composerFocus"
                        :cursor="cursor"
                        @blur="rememberSelection"
                        :disabled="busy || locked"
                        :maxlength="2000"
                        :placeholder="t('Write your message…')"
                        :adjust-position="true"
                    />
                    <button
                        class="primary support-send"
                        form-type="submit"
                        :disabled="busy || (!draft.trim() && !image)"
                    >
                        {{ t(busy ? 'Sending…' : 'Send message') }}
                    </button>
                </view>
                <text v-if="actionFailed || imageFailed" class="support-error">{{
                    t(
                        imageFailed
                            ? 'Use a JPG, PNG or WebP image up to 5 MB and 20 megapixels.'
                            : 'Message not confirmed. Your draft is kept; please retry.',
                    )
                }}</text>
            </form></view
        ></PageShell
    >
</template>
<style scoped>
.customer-heading {
    min-width: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
}
.customer-email {
    display: block;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #8b918e;
    font-size: 12px;
    line-height: 16px;
    font-weight: 400;
}
.customer-title {
    max-width: 100%;
    min-width: 0;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
}
.customer-name {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 20px;
    font-weight: 600;
}
.customer-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
    white-space: nowrap;
    color: #8b918e;
    font-size: 12px;
    font-weight: 400;
}
.customer-status.online {
    color: #279c70;
}
.presence-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}
.deleted-message {
    color: #7a867f;
    font-style: italic;
}
.message-actions {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.message-actions button {
    margin: 0;
    width: 100%;
}
.edit-message-field {
    width: 100%;
    min-height: 140px;
    padding: 12px;
    border: 1px solid #dce3dd;
    border-radius: 12px;
    box-sizing: border-box;
}

.support-offline {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px 0;
    color: #85613b;
    font-size: 12px;
    line-height: 1.5;
}
.support-mode {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 0;
    font-size: 13px;
}
.support-thread {
    display: flex;
    flex: 1;
    min-height: 0;
    flex-direction: column;
    margin-top: 12px;
}
.support-history-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}
.support-messages {
    flex: 1;
    height: 0;
    min-height: 0;
    padding: 14px 4px;
    box-sizing: border-box;
    overscroll-behavior: contain;
}
.support-empty {
    min-height: 220px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    padding: 20px;
    color: #77847c;
    gap: 10px;
    font-size: 13px;
    line-height: 21px;
}
.empty-title {
    font-size: 18px;
    font-weight: 600;
    color: #1b2922;
}
.support-message.has-customer-avatar {
    position: relative;
    padding-left: 46px;
}
.support-thread .customer-avatar {
    position: absolute;
    left: 0;
    top: 20px;
    width: 36px;
    height: 36px;
    padding: 0;
    margin: 0;
    line-height: 36px;
    border-radius: 10px;
    background: #dcefe8;
    color: #278b70;
    font-size: 18px;
}
.support-message {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    margin-bottom: 20px;
}
.support-message-own {
    align-items: flex-end;
}
.support-sender,
.support-time {
    font-size: 11px;
    color: #748078;
    margin: 4px 2px;
}
.support-bubble {
    max-width: 85%;
    padding: 12px 14px;
    border-radius: 16px 16px 16px 4px;
    background: white;
    border: 1px solid #e5e8e3;
    color: #20332a;
}
.support-message-own .support-bubble {
    border-radius: 16px 16px 4px 16px;
    background: #e0f2e9;
    border-color: #d4e9dd;
}
.support-text {
    display: block;
    font-size: 14px;
    line-height: 1.65;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.support-composer {
    border-top: 1px solid #dee5df;
    padding-top: 12px;
    flex-shrink: 0;
}
.support-composer textarea {
    box-sizing: border-box;
    display: block;
    flex: 1;
    width: 0;
    min-width: 0;
    height: 44px;
    min-height: 44px;
    max-height: 44px;
    font-size: 16px;
    line-height: 22px;
    border-radius: 12px;
    background: white;
    border: 1px solid #e2e7e4;
    padding: 10px 12px;
}
.support-composer-actions {
    display: flex;
    flex-wrap: nowrap;
    align-items: center;
    gap: 8px;
    width: 100%;
    min-width: 0;
}
.support-thread .support-composer-actions > button {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    height: 44px;
    min-height: 44px;
    margin: 0;
    font-size: 14px;
    line-height: 20px;
    white-space: nowrap;
}
.support-thread .support-composer-actions > .support-attach {
    width: 44px;
    padding: 0;
}
.support-thread .support-composer-actions > .support-send {
    padding: 0 16px;
    border-radius: 12px;
}
.support-preview {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding-bottom: 10px;
}
.support-preview > image {
    max-width: 100px;
    height: 80px;
    border-radius: 10px;
}
.support-error {
    display: block;
    font-size: 13px;
    line-height: 1.5;
    color: #9d3f20;
    margin: 8px 0;
}
.support-thread button {
    min-height: 44px;
    font-size: 14px;
    line-height: 24px;
    padding: 8px 12px;
    border-radius: 12px;
    margin: 0;
}
.text-button {
    background: none;
    color: #37604a;
}
.loading {
    padding: 24px;
    text-align: center;
}
</style>
