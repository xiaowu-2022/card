<script setup lang="ts">
import PreviewImage from './PreviewImage.vue';
import AppUpdateGate from './AppUpdateGate.vue';
import { staticAsset } from '../lib/origin';
import { computed } from 'vue';
import { session } from '../lib/session';
import { native, photoUrl } from '../lib/api';
import { go } from '../lib/navigation';
import { t } from '../lib/i18n';
import UiIcon from './UiIcon.vue';
import LanguagePicker from './LanguagePicker.vue';
withDefaults(defineProps<{ recovery?: boolean; title?: string; login?: boolean }>(), {
    recovery: false,
});
const brand = computed(() => session.value?.tenant.name ?? '');
const logo = computed(() => photoUrl(session.value?.tenant.logoUrl ?? null));
</script>
<template>
    <AppUpdateGate />
    <view
        class="auth-root"
        :class="{ recovery }"
        :style="{ '--user-primary': session?.tenant.primaryColor || '#39ad8d' }"
        ><view class="auth-shell" :class="{ designed: !recovery }"
            ><view class="auth-banner"
                ><view class="auth-topbar"
                    ><button
                        v-if="!login || !native"
                        class="auth-back"
                        :aria-label="t('Back to sign in')"
                        @click="go(login ? '/' : '/login', true)"
                    >
                        <UiIcon name="arrow-left" :size="recovery ? 20 : 28" /></button
                    ><LanguagePicker /></view
                ><view v-if="!recovery" class="auth-promotion"
                    ><PreviewImage
                        :sources="session?.tenant.logoSources"
                        v-if="logo"
                        :src="logo"
                        mode="aspectFit"
                        class="auth-logo"
                        :aria-label="brand" /><text v-else class="auth-company">{{ brand }}</text
                    ><image
                        :src="staticAsset('images/marketing/spec-pay-gold-world.png')"
                        class="auth-card"
                        mode="widthFix"
                        :aria-label="brand" /></view></view
            ><view class="auth-content"
                ><template v-if="recovery"
                    ><UiIcon
                        name="lock-keyhole"
                        :size="32"
                        :color="session?.tenant.primaryColor || '#39ad8d'"
                    /><text class="recovery-title">{{ title }}</text></template
                ><slot /></view></view
    ></view>
</template>
<style scoped>
.auth-root {
    min-height: 100vh;
    background: #f7f6f0;
    color: #25241f;
}
.auth-shell {
    max-width: 750px;
    margin: 0 auto;
    min-height: 100vh;
    padding-top: env(safe-area-inset-top);
    padding-bottom: env(safe-area-inset-bottom);
}
.auth-shell.designed {
    max-width: 520px;
}
.auth-banner {
    padding: 12px 24px 0;
    background: radial-gradient(ellipse at 70% 45%, #e9ddbc70, transparent 70%);
}
.auth-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.auth-back {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    padding: 0;
    margin: 0;
    background: transparent;
    flex-shrink: 0;
}
.auth-promotion {
    display: flex;
    align-items: center;
    flex-direction: column;
    gap: 20px;
    padding: 22px 0 28px;
}
.auth-logo {
    display: block;
    height: 56px;
    width: 220px;
    max-width: 100%;
}
.auth-company {
    font-size: 28px;
    font-weight: 750;
    line-height: 1.2;
    letter-spacing: -0.7px;
}
.auth-card {
    display: block;
    width: 300px;
    max-width: 100%;
    border-radius: 16px;
    box-shadow: 0 12px 24px -12px #49371050;
}
.auth-content {
    padding: 24px 24px 36px;
    border-top: 1px solid #e7e2d6;
}
.recovery .auth-banner {
    background: none;
    padding: 16px 16px 0;
}
.recovery .auth-content {
    max-width: 512px;
    margin: auto;
    padding: 32px 20px 48px;
    border: 0;
}
.recovery-title {
    display: block;
    font-size: 24px;
    font-weight: 600;
    margin: 20px 0 24px;
}
</style>

<style scoped>
.auth-topbar :deep(.language-picker) {
    margin-left: auto;
    font-size: 16px;
}
@media (min-width: 640px) {
    .recovery .auth-content {
        padding-inline: 32px;
    }
}
</style>
