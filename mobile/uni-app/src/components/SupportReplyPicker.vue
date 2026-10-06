<script setup lang="ts">
import TextInput from './TextInput.vue';
import { ref } from 'vue';
import ViewportLayer from './ViewportLayer.vue';
import { request } from '../lib/api';
import { supportDenied, type ReplyPage } from '../lib/support-workspace';
import { t } from '../lib/i18n';
const emit = defineEmits<{ select: [body: string]; close: [] }>();
const search = ref(''),
    page = ref(1),
    busy = ref(false),
    failed = ref(false),
    rows = ref<ReplyPage>({ data: [], current_page: 1, last_page: 1 });
async function load(reset = false) {
    if (busy.value) return;
    if (reset) page.value = 1;
    busy.value = true;
    failed.value = false;
    try {
        rows.value = await request<ReplyPage>(
            '/support-workspace/replies?search=' +
                encodeURIComponent(search.value) +
                '&page=' +
                page.value,
        );
    } catch (e) {
        failed.value = true;
        if (supportDenied(e)) emit('close');
    } finally {
        busy.value = false;
    }
}
void load();
</script>
<template>
    <ViewportLayer
        ><view class="reply-overlay"
            ><view class="reply-dialog" role="dialog" :aria-label="t('Quick replies')">
                <view class="reply-heading"
                    ><text>{{ t('Quick replies') }}</text
                    ><button class="secondary" @click="emit('close')">
                        {{ t('Close') }}
                    </button></view
                >
                <view class="reply-heading"
                    ><TextInput
                        class="support-field"
                        v-model="search"
                        :maxlength="120"
                        :placeholder="t('Search quick replies')"
                    /><button class="secondary" :disabled="busy" @click="load(true)">
                        {{ t('Search') }}
                    </button></view
                >
                <text v-if="failed">{{ t('Unable to load. Please try again.') }}</text>
                <scroll-view scroll-y class="reply-list"
                    ><button
                        v-for="row in rows.data"
                        :key="row.scope + row.id"
                        class="reply-item"
                        @click="emit('select', row.body)"
                    >
                        <text
                            >{{ row.title }} ·
                            {{
                                t(row.scope === 'shared' ? 'Company reply' : 'My quick replies')
                            }}</text
                        ><text>{{ row.body }}</text></button
                    ><text v-if="!busy && !rows.data.length">{{
                        t('No quick replies yet.')
                    }}</text></scroll-view
                >
                <view class="reply-heading"
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
                >
            </view></view
        ></ViewportLayer
    >
</template>
<style scoped>
.reply-overlay {
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
.reply-dialog {
    background: #fafaf7;
    border-radius: 20px;
    padding: 18px;
    width: 100%;
    max-width: 520px;
    max-height: 80dvh;
    display: flex;
    flex-direction: column;
    gap: 14px;
    box-sizing: border-box;
}
.reply-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.reply-heading input {
    flex: 1;
    min-width: 0;
    border: 1px solid #dce3dd;
    padding: 10px;
    border-radius: 10px;
}
.reply-list {
    height: 40dvh;
}
.reply-item {
    display: flex;
    flex-direction: column;
    text-align: left;
    background: white;
    border: 1px solid #e2e7e4;
    border-radius: 12px;
    padding: 12px;
    margin: 8px 0;
    line-height: 1.5;
    font-size: 14px;
    gap: 8px;
    white-space: pre-wrap;
}
.reply-heading button {
    font-size: 14px;
    padding: 8px 12px;
    line-height: 24px;
    margin: 0;
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
