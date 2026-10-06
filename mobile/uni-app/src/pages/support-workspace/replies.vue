<script setup lang="ts">
import TextInput from '../../components/TextInput.vue';
import { ref, onBeforeUnmount } from 'vue';
import { onShow, onBackPress } from '@dcloudio/uni-app';
import PageShell from '../../components/PageShell.vue';
import ViewportLayer from '../../components/ViewportLayer.vue';
import { request } from '../../lib/api';
import { requireUser } from '../../lib/session';
import { requestId } from '../../lib/client';
import { t } from '../../lib/i18n';
import { supportDenied, type QuickReply, type ReplyPage } from '../../lib/support-workspace';
const rows = ref<ReplyPage>({ data: [], current_page: 1, last_page: 1 }),
    page = ref(1),
    search = ref(''),
    busy = ref(false),
    error = ref(false),
    draft = ref<QuickReply | null>(null);
let saved = '';
async function load(reset = false) {
    if (busy.value) return;
    if (reset) page.value = 1;
    busy.value = true;
    error.value = false;
    try {
        if (!(await requireUser())) return;
        rows.value = await request<ReplyPage>(
            '/support-workspace/replies?personal=1&page=' +
                page.value +
                '&search=' +
                encodeURIComponent(search.value),
        );
    } catch (e) {
        error.value = true;
        supportDenied(e);
    } finally {
        busy.value = false;
    }
}
function edit(row?: QuickReply) {
    draft.value = row
        ? { ...row }
        : { id: requestId(), title: '', body: '', revision: 0, scope: 'personal' };
    saved = JSON.stringify(draft.value);
}
async function close() {
    if (busy.value) return;
    if (draft.value && JSON.stringify(draft.value) !== saved) {
        const result = await uni.showModal({
            title: t('Discard unsaved changes?'),
            confirmText: t('Confirm'),
            cancelText: t('Cancel'),
        });
        if (!result.confirm) return;
    }
    draft.value = null;
}
async function save(archived = false) {
    if (busy.value || !draft.value) return;
    if (archived) {
        const result = await uni.showModal({
            title: t('Delete this quick reply?'),
            confirmText: t('Confirm'),
            cancelText: t('Cancel'),
        });
        if (!result.confirm) return;
    }
    busy.value = true;
    error.value = false;
    try {
        await request('/support-workspace/replies', 'POST', { ...draft.value, archived });
        draft.value = null;
    } catch (e) {
        error.value = true;
        supportDenied(e);
    } finally {
        busy.value = false;
    }
    if (!error.value) await load();
}
onShow(() => void load());
onBackPress(() => {
    if (draft.value) {
        void close();
        return true;
    }
    return false;
});
// #ifdef H5
function unload(e: BeforeUnloadEvent) {
    if (draft.value && JSON.stringify(draft.value) !== saved) {
        e.preventDefault();
        e.returnValue = '';
    }
}
window.addEventListener('beforeunload', unload);
onBeforeUnmount(() => window.removeEventListener('beforeunload', unload));
// #endif
function updateBody(event: unknown) {
    const value = event as { detail?: { value?: string }; target?: { value?: string } };
    draft.value!.body = value.detail?.value ?? value.target?.value ?? '';
}
</script>
<template>
    <PageShell :title="t('My quick replies')" back="/support-workspace" active="account"
        ><view class="reply-page"
            ><view class="controls"
                ><TextInput
                    class="support-field"
                    v-model="search"
                    :maxlength="120"
                    :placeholder="t('Search quick replies')"
                /><button class="secondary" :disabled="busy" @click="load(true)">
                    {{ t('Search') }}
                </button></view
            ><button class="primary" @click="edit()">{{ t('Add quick reply') }}</button
            ><text v-if="error">{{
                t('Unable to complete this request. Refresh and try again.')
            }}</text
            ><button v-for="row in rows.data" :key="row.id" class="entry" @click="edit(row)">
                <text>{{ row.title }}</text
                ><text>{{ row.body }}</text></button
            ><text v-if="!busy && !rows.data.length">{{ t('No quick replies yet.') }}</text
            ><view class="controls"
                ><button
                    class="secondary"
                    :disabled="busy || page <= 1"
                    @click="
                        page--;
                        load();
                    "
                >
                    {{ t('Previous') }}</button
                ><text>{{ page }} / {{ rows.last_page }}</text
                ><button
                    class="secondary"
                    :disabled="busy || page >= rows.last_page"
                    @click="
                        page++;
                        load();
                    "
                >
                    {{ t('Next') }}
                </button></view
            ></view
        >
        <ViewportLayer v-if="draft"
            ><view class="editor-overlay"
                ><view class="editor" role="dialog" :aria-label="t('Quick reply')"
                    ><text>{{ t('Quick reply') }}</text
                    ><TextInput
                        class="support-field"
                        v-model="draft.title"
                        :maxlength="100"
                        :disabled="busy"
                        :placeholder="t('Title')"
                    /><textarea
                        :value="draft.body"
                        @input="updateBody"
                        :maxlength="2000"
                        :disabled="busy"
                        :placeholder="t('Reply text')"
                    /><text v-if="error">{{
                        t('Unable to complete this request. Refresh and try again.')
                    }}</text
                    ><view class="controls"
                        ><button class="secondary" :disabled="busy" @click="close">
                            {{ t('Cancel') }}</button
                        ><button
                            class="primary"
                            :disabled="busy || !draft.title.trim() || !draft.body.trim()"
                            @click="save()"
                        >
                            {{ t('Save') }}
                        </button></view
                    ><button
                        v-if="draft.revision"
                        class="secondary"
                        :disabled="busy"
                        @click="save(true)"
                    >
                        {{ t('Delete') }}
                    </button></view
                ></view
            ></ViewportLayer
        >
    </PageShell>
</template>
<style scoped>
.reply-page {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding-top: 16px;
}
.controls {
    display: flex;
    gap: 10px;
    align-items: center;
    justify-content: space-between;
}
.controls input {
    flex: 1;
    min-width: 0;
}
.reply-page button,
.editor button {
    margin: 0;
    font-size: 14px;
    line-height: 24px;
    padding: 10px 14px;
    border-radius: 12px;
}
.entry {
    text-align: left;
    display: flex;
    flex-direction: column;
    gap: 8px;
    background: white;
    border: 1px solid #e2e7e4;
    white-space: pre-wrap;
}
.editor-overlay {
    position: fixed;
    inset: 0;
    z-index: 150;
    background: #0006;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    box-sizing: border-box;
}
.editor {
    background: #fafaf7;
    border-radius: 20px;
    width: 100%;
    max-width: 520px;
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    box-sizing: border-box;
}
.editor textarea {
    width: 100%;
    height: 180px;
    box-sizing: border-box;
}
.editor input,
.editor textarea,
.controls input {
    border: 1px solid #dce3dd;
    padding: 12px;
    border-radius: 10px;
    background: white;
    font-size: 14px;
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
