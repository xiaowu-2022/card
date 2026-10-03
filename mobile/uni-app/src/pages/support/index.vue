<script setup lang="ts">
import { nextTick, ref, watch, onBeforeUnmount } from 'vue';
import { onHide, onShow, onUnload } from '@dcloudio/uni-app';
import PageShell from '../../components/PageShell.vue';
import SupportImage from '../../components/SupportImage.vue';
import UiIcon from '../../components/UiIcon.vue';
import { ApiError, request, upload } from '../../lib/api';
import { requestId } from '../../lib/client';
import { refreshUnread, requireUser } from '../../lib/session';
import { t, dateTime } from '../../lib/i18n';
type Chat = {
    messages: {
        id: string;
        sequence: number;
        fromSupport: boolean;
        supportName?: string | null;
        text: string | null;
        createdAt: string;
        imageUrl: string | null;
        imageSources?: string[];
    }[];
    olderCursor: number | null;
};
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
let intent: { request_id: string; support_message: string; image: string } | null = null;
const locked = ref(false);
let visible = false,
    polling = false,
    followLatest = true,
    acknowledged = 0,
    generation = 0,
    timer: ReturnType<typeof setInterval> | undefined;
function foreground() {
    // #ifdef H5
    if (document.visibilityState !== 'visible') return false;
    // #endif
    return visible;
}
async function load(silent = false) {
    if (polling) return;
    polling = true;
    const run = ++generation;
    if (!silent) loading.value = true;
    try {
        if (!(await requireUser())) return;
        const data = await request<Chat>('/support?before=' + before.value);
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
    } catch {
        if (silent) disconnected.value = true;
        else failed.value = true;
    } finally {
        polling = false;
        loading.value = false;
    }
}
async function acknowledge() {
    await nextTick();
    if (!foreground() || failed.value) return;
    const through = Math.max(0, ...chat.value.messages.map((m) => m.sequence));
    if (!through || through <= acknowledged) return;
    try {
        await request('/support/read', 'POST', { through });
        acknowledged = through;
        readFailed.value = false;
        await refreshUnread();
    } catch {
        readFailed.value = true;
    }
}
function tick() {
    if (!foreground() || busy.value || before.value) return;
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
            await upload('/support/messages', data, [
                { name: 'support_image', path: intent.image },
            ]);
        else await request('/support/messages', 'POST', data);
        draft.value = '';
        image.value = '';
        intent = null;
        locked.value = false;
        before.value = 0;
        followLatest = true;
        await load(true);
    } catch (error) {
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
        const webp = String.fromCharCode(...bytes.slice(0, 4)) === 'RIFF' &&
            String.fromCharCode(...bytes.slice(8, 12)) === 'WEBP';
        supported = blob.size <= 5 * 1024 * 1024 && (png || jpeg || webp);
        // #endif
        if (
            info.width * info.height > 20000000 ||
            !supported
        ) {
            imageFailed.value = true;
            return;
        }
        image.value = result.tempFilePaths[0];
    } catch (error) {
        // Cancellation is silent; unreadable or malformed selected images are not.
        const message = typeof error === 'object' && error !== null && 'errMsg' in error
            ? String(error.errMsg) : '';
        if (!/cancel/i.test(message)) imageFailed.value = true;
    }
}
function navigate(cursor: number) {
    if (polling) return;
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
</script>
<template>
    <PageShell :title="t('Customer support')" back="/account" active="account" chat
        ><view class="support-thread"
            ><text class="support-privacy">{{
                t(
                    'Do not send passwords, verification codes, full card numbers, CVV or identity documents.',
                )
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
                    :class="{ 'support-message-own': !message.fromSupport }"
                    ><text class="support-sender">{{
                        message.fromSupport ? (message.supportName || t('Customer support')) : t('You')
                    }}</text
                    ><view class="support-bubble"
                        ><SupportImage v-if="message.imageUrl" :path="message.imageUrl" :sources="message.imageSources" /><text
                            v-if="message.text"
                            class="support-text"
                            >{{ message.text }}</text
                        ></view
                    ><text class="support-time">{{ dateTime(message.createdAt) }}</text></view
                ><view id="support-last" style="height: 1px" /></scroll-view
            ><button v-if="readFailed" class="text-button" @click="acknowledge">
                {{ t('Retry') }}
            </button>
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
                ><textarea
                    v-model="draft"
                    :disabled="busy || locked"
                    :maxlength="2000"
                    :placeholder="t('Write your message…')"
                    :adjust-position="true"
                /><text v-if="actionFailed || imageFailed" class="support-error">{{
                    t(
                        imageFailed
                            ? 'Use a JPG, PNG or WebP image up to 5 MB and 20 megapixels.'
                            : 'Message not confirmed. Your draft is kept; please retry.',
                    )
                }}</text
                ><view class="support-composer-actions"
                    ><button
                        class="secondary"
                        :disabled="busy || locked"
                        :aria-label="t('Attach image')"
                        @click="chooseImage"
                    >
                        <UiIcon name="image-plus" :size="18" /></button
                    ><text class="support-limit">JPG, PNG, WebP · 5 MB</text
                    ><button
                        class="primary"
                        form-type="submit"
                        :disabled="busy || (!draft.trim() && !image)"
                    >
                        <UiIcon name="send" :size="16" />{{ t(busy ? 'Sending…' : 'Send message') }}
                    </button></view
                >
            </form></view
        ></PageShell
    >
</template>
<style scoped>
.support-thread {
    display: flex;
    flex: 1;
    min-height: 0;
    flex-direction: column;
    margin-top: 12px;
}
.support-privacy {
    color: #737e77;
    font-size: 12px;
    line-height: 1.6;
    margin-bottom: 10px;
    flex-shrink: 0;
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
    width: 100%;
    height: 56px;
    min-height: 44px;
    max-height: 120px;
    font-size: 16px;
    border-radius: 16px;
    background: white;
    border: 1px solid #e2e7e4;
    padding: 12px 14px;
}
.support-composer-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
}
.support-composer-actions > button:last-child {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 8px;
}
.support-limit {
    color: #77847c;
    font-size: 11px;
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
@media (max-width: 400px) {
    .support-limit {
        display: none;
    }
}
</style>
