<script setup lang="ts">
import { computed } from 'vue';
import { session } from '../../lib/session';
import { photoUrl } from '../../lib/api';
import { staticAsset } from '../../lib/origin';
import { t } from '../../lib/i18n';
import UiIcon from '../../components/UiIcon.vue';
const brand = computed(() => session.value?.tenant.name ?? 'SPEC PAY');
const logo = computed(() => photoUrl(session.value?.tenant.logoUrl ?? null));
const methods = [
    { name: 'App download', copy: 'Install the iOS app', href: '#/pages/app-download/ipa', icon: 'download' },
    { name: 'Lightweight App', copy: 'Install a home screen shortcut', href: '/webclip/', icon: 'plus' },
    { name: 'Portable App', copy: 'Add the web app to your home screen', href: '/pwa/', icon: 'globe' },
];
function openIPA(event: Event) {
    event.preventDefault();
    uni.navigateTo({ url: '/pages/app-download/ipa' });
}
</script>
<template>
    <view class="apple-install">
        <view class="install-header">
            <image v-if="logo" :src="logo" mode="heightFix" :aria-label="brand" />
            <view v-else class="install-brand"><UiIcon name="aperture" :size="32" /><text>{{ brand }}</text></view>
            <text class="install-platform">{{ t('iOS installation') }}</text>
        </view>
        <view class="install-main">
            <text class="install-eyebrow">{{ t('Mastercard U Card') }}</text>
            <h1>{{ brand }}</h1>
            <p class="install-tagline">{{ t('Global payments, in your hands.') }}</p>
            <image class="install-art" :src="staticAsset('images/marketing/spec-pay-gold-world.png')" mode="widthFix" />
            <view class="install-copy"><h2>{{ t('Choose your installation method') }}</h2><p>{{ t('Open in Safari on your iPhone or iPad.') }}</p></view>
            <view class="install-methods">
                <a v-for="method in methods" :key="method.name" :href="method.href" @click="method.name === 'App download' && openIPA($event)">
                    <UiIcon :name="method.icon" :size="24" color="#f0dcaa" />
                    <strong>{{ t(method.name) }}</strong>
                    <span>{{ t(method.copy) }}</span>
                </a>
            </view>
            <text class="install-footer">{{ t('Global payments, in your hands.') }}</text>
        </view>
    </view>
</template>
<style scoped>
.apple-install{min-height:100vh;background:#211c14;color:#252118;font-family:Inter,system-ui,sans-serif}.install-header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px max(22px,calc((100vw - 1100px)/2));background:#d6bd79}.install-header>image{height:46px;max-width:62%}.install-brand{display:flex;align-items:center;gap:9px;font-size:23px;font-weight:750;overflow-wrap:anywhere}.install-platform{font-size:12px;white-space:nowrap}.install-main{max-width:1100px;margin:auto;text-align:center;padding:52px 20px 28px;color:#f4f1e7;background:radial-gradient(ellipse at 83% 37%,#936b3040 0%,transparent 58%),radial-gradient(ellipse at 98% 3%,#cfaa6120 0%,transparent 46%),#100f0d}.install-eyebrow{font-size:12px;letter-spacing:3px}h1{font-size:clamp(40px,8vw,76px);line-height:1.05;margin:20px 0 14px;overflow-wrap:anywhere}.install-tagline{font-size:18px;line-height:1.6;margin:0}.install-art{display:block;width:100%;max-width:670px;margin:24px auto 8px}.install-copy h2{font-size:22px;margin:20px 0 10px}.install-copy p{font-size:13px;line-height:1.7;color:#b9ab8e;margin:0 0 25px}.install-methods{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;max-width:670px;margin:auto}.install-methods a{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9px;min-height:125px;padding:15px 7px;border:1px solid #d6bd79;border-radius:17px;background:#211c14;color:#f0dcaa;text-decoration:none;box-shadow:0 3px 0 #a8894c}.install-methods strong{font-size:18px}.install-methods span{font-size:11px;line-height:1.5}.install-methods a:focus-visible{outline:3px solid #087e63;outline-offset:4px}.install-footer{display:block;font-size:11px;color:#b9ab8e;margin:35px 0 0;padding-bottom:env(safe-area-inset-bottom)}@media(min-width:768px){.install-main{padding-top:60px}.install-methods a{min-height:140px}.install-methods strong{font-size:22px}.install-methods span{font-size:13px}}
</style>
