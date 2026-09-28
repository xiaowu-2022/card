<script setup lang="ts">
import { ref } from 'vue';
import PageShell from '../components/PageShell.vue';
import LanguagePicker from '../components/LanguagePicker.vue';
import UiIcon from '../components/UiIcon.vue';
import FormErrors from '../components/FormErrors.vue';
import { logout } from '../lib/session';
import { explainError } from '../lib/client';
import { go } from '../lib/navigation';
import { t } from '../lib/i18n';
const errors = ref<Record<string, string>>({}),
    pending = ref(false);
async function signout() {
    if (pending.value) return;
    pending.value = true;
    try {
        await logout();
    } catch (e) {
        errors.value = explainError(e);
    } finally {
        pending.value = false;
    }
}
</script>
<template>
    <PageShell :title="t('Settings')" back="/account"
        ><FormErrors :errors="errors" /><view class="settings-list"
            ><view class="settings-item"
                ><UiIcon name="globe" :size="20" /><text class="grow">{{ t('Language') }}</text
                ><LanguagePicker /></view
            ><view class="settings-item" @click="go('/about')"
                ><UiIcon name="circle-alert" :size="20" /><text class="grow">{{
                    t('About us')
                }}</text
                ><UiIcon name="chevron-right" :size="16" /></view></view
        ><button class="signout" :disabled="pending" @click="signout">
            {{ t('Log out') }}
        </button></PageShell
    >
</template>
<style scoped>
.settings-list {
    display: grid;
    gap: 12px;
    margin-top: 24px;
}
.settings-item {
    display: flex;
    gap: 12px;
    align-items: center;
    min-height: 60px;
    padding: 12px 16px;
    border: 1px solid #e2e7e4;
    background: white;
    border-radius: 14px;
    font-size: 14px;
}
.grow {
    flex: 1;
}
.signout {
    margin: 24px auto 0;
    min-height: 44px;
    width: 100%;
    border: 1px solid #e2e7e4;
    border-radius: 14px;
    padding: 10px 24px;
    font-size: 14px;
    line-height: 24px;
    color: #68736e;
    background: transparent;
}
</style>
