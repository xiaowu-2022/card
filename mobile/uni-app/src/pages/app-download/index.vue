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
    { name: 'app', href: '#/pages/app-download/ipa', icon: 'download' },
    { name: 'Lightweight app', href: '/webclip/', icon: 'plus' },
    { name: 'Portable app', href: '/pwa/', icon: 'globe' },
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
            <image class="install-art" :src="staticAsset('images/marketing/spec-pay-gold-world.png')" mode="aspectFit" />
            <view class="install-copy"><h2>{{ t('Choose your installation method') }}</h2><p>{{ t('Open in Safari on your iPhone or iPad.') }}</p></view>
            <view class="install-methods">
                <a v-for="method in methods" :key="method.name" :href="method.href" @click="method.name === 'app' && openIPA($event)">
                    <UiIcon :name="method.icon" :size="24" color="#f0dcaa" />
                    <strong>{{ t(method.name) }}</strong>
                </a>
            </view>
            <text class="install-footer">{{ t('Global payments, in your hands.') }}</text>
        </view>
    </view>
</template>
<style scoped>
.apple-install{min-height:100vh;min-height:100svh;background:#211c14;color:#252118;font-family:Inter,system-ui,sans-serif}
.install-header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:14px max(20px,calc((100vw - 1100px)/2));background:#d6bd79}
.install-header>image{height:36px;max-width:62%}
.install-brand{display:flex;min-width:0;align-items:center;gap:9px;font-size:20px;font-weight:750}
.install-brand>text{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.install-platform{font-size:12px;white-space:nowrap}
.install-main{max-width:1100px;margin:auto;text-align:center;padding:16px 20px 20px;color:#f4f1e7;background:radial-gradient(ellipse at 83% 37%,#936b3040 0%,transparent 58%),radial-gradient(ellipse at 98% 3%,#cfaa6120 0%,transparent 46%),#100f0d}
.install-art{display:block;width:100%;max-width:670px;height:min(32vh,280px);height:min(32svh,280px);margin:0 auto 16px}
.install-copy h2{font-size:20px;line-height:1.3;margin:0 0 8px}
.install-copy p{font-size:12px;line-height:1.5;color:#b9ab8e;margin:0 0 18px}
.install-methods{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;max-width:670px;margin:auto}
.install-methods a{box-sizing:border-box;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9px;min-height:112px;padding:12px 6px;border:1px solid #d6bd79;border-radius:17px;background:#211c14;color:#f0dcaa;text-decoration:none;box-shadow:0 3px 0 #a8894c}
.install-methods strong{font-size:clamp(16px,4.8vw,18px);white-space:nowrap}
.install-methods a:focus-visible{outline:3px solid #087e63;outline-offset:4px}
.install-footer{display:block;font-size:11px;color:#b9ab8e;margin:20px 0 0;padding-bottom:env(safe-area-inset-bottom)}
@media(min-width:768px){.install-main{padding-top:24px}.install-methods a{min-height:120px}.install-methods strong{font-size:22px}}
</style>
