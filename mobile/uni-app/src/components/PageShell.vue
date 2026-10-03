<script setup lang="ts">
import PreviewImage from './PreviewImage.vue';
import AppUpdateGate from './AppUpdateGate.vue';
import { computed } from 'vue';
import { t } from '../lib/i18n';
import { unread, session } from '../lib/session';
import { photoUrl } from '../lib/api';
import { go, home } from '../lib/navigation';
import UiIcon from './UiIcon.vue';
import LanguagePicker from './LanguagePicker.vue';
const props = withDefaults(
    defineProps<{
        title?: string;
        active?: string;
        back?: string;
        guest?: boolean;
        article?: boolean;
        overview?: boolean;
        white?: boolean;
        chat?: boolean;
        hideMessages?: boolean;
        stickyHeader?: boolean;
    }>(),
    { title: '' },
);
const brand = computed(() => session.value?.tenant.name ?? '');
const logo = computed(() => photoUrl(session.value?.tenant.logoUrl ?? null));
const isHome = computed(() => !!props.active && !props.back);
function tabActive(name: string) {
    const pages = getCurrentPages();
    const page = pages[pages.length - 1] as any;
    const options = page?.options ?? page?.$page?.options ?? {};
    const route = page?.route ?? '';
    const path =
        route === 'pages/screen/index'
            ? decodeURIComponent(options.path || '/')
            : (
                  {
                      'pages/assets/index': '/dashboard',
                      'pages/cards/index': '/cards',
                      'pages/account/index': '/account',
                      'pages/messages/index': '/messages',
                      'pages/support/index': '/support',
                  } as Record<string, string>
              )[route] || '';
    return name === 'assets'
        ? ['/dashboard', '/wallet', '/security-deposit'].some((prefix) => path.startsWith(prefix))
        : name === 'cards'
          ? path.startsWith('/cards')
          : path.startsWith('/account') || path.startsWith('/kyc');
}
const badge = (n: number) => (n > 99 ? '99+' : String(n));
function open(path: string) {
    if (path.startsWith('/pages/')) uni.navigateTo({ url: path });
    else go(path);
}
</script>
<template>
    <AppUpdateGate />
    <view class="user-root" :style="{ '--user-primary': session?.tenant.primaryColor || '#39ad8d' }"
        ><view
            class="shell"
            :class="{
                'account-shell': active === 'account' && isHome,
                'overview-shell': overview,
                'white-shell': white,
                'chat-shell': chat,
            }"
            ><view v-if="isHome" class="brand-header"
                ><view class="brand" @click="home()"
                    ><PreviewImage
                        :sources="session?.tenant.logoSources"
                        v-if="logo"
                        class="shell-brand-logo"
                        :src="logo"
                        mode="aspectFit"
                        :aria-label="brand"
                    /><template v-else
                        ><UiIcon
                            name="aperture"
                            :color="session?.tenant.primaryColor"
                            class="brand-symbol"
                            :size="36"
                        /><text>{{ brand }}</text></template
                    ></view
                ><view v-if="active !== 'account'" class="header-actions"
                    ><LanguagePicker icon-only /><button
                        class="icon"
                        :aria-label="t('Customer support')"
                        @click="go('/support')"
                    >
                        <UiIcon name="support" /><text v-if="unread.support" class="badge">{{
                            badge(unread.support)
                        }}</text>
                    </button></view
                ></view
            ><view
                class="shell-main"
                :class="{ 'overview-main': overview, 'article-main': article }"
                ><view v-if="!isHome" class="header" :class="{ 'sticky-header': stickyHeader }"
                    ><button v-if="back" class="icon" :aria-label="t('Back')" @click="open(back)">
                        <UiIcon name="arrow-left" :size="20" /></button
                    ><view v-else /><text class="header-title">{{ title }}</text
                    ><slot name="header-right"
                        ><button
                            v-if="!guest && session?.user && !hideMessages"
                            class="icon"
                            :aria-label="t('Messages')"
                            @click="go('/messages')"
                        >
                            <UiIcon name="bell" :size="20" /><text
                                v-if="unread.messages"
                                class="badge"
                                >{{ badge(unread.messages) }}</text
                            ></button
                        ><view v-else /></slot></view
                ><slot /></view
            ><view v-if="!guest && session?.user && !article" class="tabs"
                ><button
                    v-for="item in [
                        { name: 'assets', label: 'Assets', path: '/dashboard' },
                        { name: 'cards', label: 'Cards', path: '/cards' },
                        {
                            name: 'account',
                            label: 'Me',
                            path: session.restricted ? '/account/restricted' : '/account',
                        },
                    ]"
                    :key="item.name"
                    class="tab"
                    :class="{ active: tabActive(item.name) }"
                    @click="home(item.path)"
                >
                    <view class="tab-icon"
                        ><UiIcon
                            :name="item.name"
                            :color="tabActive(item.name) ? session?.tenant.primaryColor : undefined"
                        /><text
                            v-if="item.name === 'account' && unread.messages + unread.support"
                            class="badge"
                            >{{ badge(unread.messages + unread.support) }}</text
                        ></view
                    ><text>{{ t(item.label) }}</text>
                </button></view
            ></view
        ></view
    >
</template>
<style scoped>
.user-root {
    min-height: 100vh;
    background: #f7f6f0;
    color: #171c19;
    --user-primary: #39ad8d;
    --user-border: #e2e7e4;
    --user-text-secondary: #68736e;
}
.shell {
    container-type: inline-size;
    max-width: 750px;
    min-height: 100vh;
    margin: auto;
    background: #f7f6f0;
    padding: env(safe-area-inset-top) 0 0;
}
.account-shell,
.white-shell {
    background: #fff;
}
.white-shell .header-title {
    text-align: center;
    font-size: 22px;
}
.overview-shell {
    background-image:
        radial-gradient(ellipse 66% 63% at 38% 44%, #9bd8c5, transparent),
        radial-gradient(ellipse 68% 60% at 69% 10%, #d7f0cd, transparent),
        linear-gradient(180deg, #e6f4ee 0%, #e5f2e9 72%, #f7f6f0 100%);
    background-size: 100% min(126.667cqw, 950px);
    background-repeat: no-repeat;
}
.shell-main {
    padding: min(3.2cqw, 24px) min(4.267cqw, 32px)
        calc(clamp(96px, 18cqw, 135px) + env(safe-area-inset-bottom));
}
.overview-main {
    padding-top: 0;
}
.brand-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    height: clamp(64px, 11.867cqw, 89px);
    padding: 0 min(5.333cqw, 40px);
}
.brand {
    display: flex;
    align-items: center;
    gap: min(2.133cqw, 16px);
    min-width: 0;
    font-size: clamp(19px, 4.533cqw, 34px);
    font-weight: 600;
}
.brand > text {
    min-width: 0;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}
.shell-brand-logo {
    display: block;
    max-width: 48cqw;
    width: 180px;
    height: clamp(40px, 8cqw, 60px);
}
.header-actions {
    display: flex;
    align-items: center;
}
.header-actions .icon,
.header-actions :deep(.iconOnly) {
    width: clamp(44px, 9.6cqw, 72px);
    height: clamp(44px, 9.6cqw, 72px);
    padding: 0;
}
.header-actions :deep(uni-image) {
    width: clamp(24px, 5.867cqw, 44px) !important;
    height: clamp(24px, 5.867cqw, 44px) !important;
}
.header {
    display: grid;
    grid-template-columns: 44px minmax(0, 1fr) 44px;
    gap: 8px;
    align-items: center;
    min-height: 44px;
    margin-bottom: 20px;
}
.header-title {
    text-align: center;
    font-size: clamp(24px, 4.8cqw, 36px);
    line-height: 1.3;
    font-weight: 600;
    overflow-wrap: anywhere;
}
.sticky-header {
    position: sticky;
    top: env(safe-area-inset-top);
    z-index: 50;
    background: #f7f6f0;
    margin-top: calc(-1 * min(3.2cqw, 24px));
    margin-left: calc(-1 * min(4.267cqw, 32px));
    margin-right: calc(-1 * min(4.267cqw, 32px));
    padding: min(3.2cqw, 24px) min(4.267cqw, 32px);
}
.white-shell .sticky-header {
    background: #fff;
}
.icon {
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    width: 44px;
    height: 44px;
    position: relative;
    padding: 0;
    margin: 0;
    flex-shrink: 0;
}
.tabs {
    position: fixed;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    max-width: 750px;
    width: 100%;
    height: calc(clamp(64px, 14.133cqw, 106px) + env(safe-area-inset-bottom));
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    padding: 0 0 env(safe-area-inset-bottom);
    border: 0;
    z-index: 40;
    background: #fff;
}
.tab {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: min(0.8cqw, 6px);
    background: transparent;
    color: #68736e;
    min-height: 44px;
    padding: 0;
    font-size: clamp(14px, 3.2cqw, 24px);
    line-height: 1.3;
    font-weight: 400;
}
.tab-icon {
    position: relative;
    width: clamp(27px, 5.867cqw, 44px);
    height: clamp(27px, 5.867cqw, 44px);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0;
    z-index: 0;
}
.tab-icon :deep(image) {
    width: 100% !important;
    height: 100% !important;
}
.tab.active {
    color: var(--user-primary);
}
.tab.active .tab-icon:before {
    content: '';
    position: absolute;
    inset: -8px;
    background: #f2f4f2;
    border-radius: 50%;
    z-index: -1;
}
.badge {
    position: absolute;
    right: -3px;
    top: -3px;
    background: #e44343;
    color: white;
    font-size: 10px;
    font-weight: 600;
    line-height: 18px;
    min-width: 18px;
    height: 18px;
    padding: 0 4px;
    border-radius: 12px;
    z-index: 2;
}
.article-main {
    padding: min(3.2cqw, 24px) min(5.333cqw, 40px) 48px;
}
.chat-shell {
    height: 100dvh;
    min-height: 0;
    display: flex;
    flex-direction: column;
}
.chat-shell > .shell-main {
    display: flex;
    flex: 1;
    min-height: 0;
    flex-direction: column;
    padding-bottom: calc(clamp(64px, 14.133cqw, 106px) + env(safe-area-inset-bottom) + 12px);
}
.chat-shell .header {
    flex-shrink: 0;
    margin-bottom: 0;
}
.brand-symbol {
    width: clamp(36px, 8cqw, 52px) !important;
    height: clamp(36px, 8cqw, 52px) !important;
}
</style>
