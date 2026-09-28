<script setup lang="ts">
import { computed, ref } from 'vue';
import { session } from '../lib/session';
import { request } from '../lib/api';
import { locale, changeLocale, t } from '../lib/i18n';
import UiIcon from './UiIcon.vue';
defineProps<{ iconOnly?: boolean }>();
const labels: Record<string, string> = {
    'zh-CN': '简体中文',
    en: 'English',
    ms: 'Bahasa Melayu',
    es: 'Español',
};
const locales = computed(() => session.value?.locales ?? ['en']);
const items = computed(() => locales.value.map((code) => labels[code] ?? code));
const busy = ref(false);
async function select(event: { detail: { value: string | number } }) {
    const code = locales.value[Number(event.detail.value)];
    if (!code || busy.value) return;
    busy.value = true;
    try {
        await request('/client/locale', 'POST', { locale: code });
        changeLocale(code);
        if (session.value) session.value.locale = code;
    } catch {
        uni.showToast({ title: t('Unable to load. Please try again.'), icon: 'none' });
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <picker
        :range="items"
        :value="Math.max(0, locales.indexOf(locale))"
        :disabled="busy"
        @change="select"
        ><view class="language-picker" :class="{ iconOnly }" :aria-label="t('Language')"
            ><UiIcon name="globe" :size="iconOnly ? 24 : 16" /><text v-if="!iconOnly">{{
                labels[locale] ?? locale
            }}</text></view
        ></picker
    >
</template>
<style scoped>
.language-picker {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border: 1px solid #d9d5c9;
    border-radius: 999px;
    background: #ffffff80;
    color: #35332b;
    font-size: 14px;
    min-height: 44px;
}
.iconOnly {
    width: 44px;
    height: 44px;
    border: 0;
    background: transparent;
    padding: 10px;
    justify-content: center;
}
</style>
