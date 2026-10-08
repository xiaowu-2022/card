<script setup lang="ts">
import { openAppDownload } from '../lib/open-app-download';
import { staticAsset } from '../lib/origin';
import { computed, ref } from 'vue';
import { session } from '../lib/session';
import { photoUrl } from '../lib/api';
import { t } from '../lib/i18n';
import { go } from '../lib/navigation';
import UiIcon from '../components/UiIcon.vue';
import LanguagePicker from '../components/LanguagePicker.vue';
import MarketingCard from '../components/MarketingCard.vue';
function downloadApp() {
    menuOpen.value = false;
    openAppDownload();
}
const services = [
    {
        icon: 'wallet',
        title: 'Wallet',
        copy: 'View your available balance and follow each top-up and withdrawal.',
        href: '/wallet',
    },
    {
        icon: 'credit-card',
        title: 'Card management',
        copy: 'Manage your cards from one place, with controls available for each card.',
        href: '/cards',
    },
    {
        icon: 'list',
        title: 'Transaction history',
        copy: 'Review card activity and keep track of individual transactions.',
        href: '/cards',
    },
    {
        icon: 'fingerprint',
        title: 'Identity verification',
        copy: 'Submit your identity materials through your account before applying.',
        href: '/kyc',
    },
] as const;
const faqs = [
    {
        question: 'What do I need to register?',
        answer: 'Use an invitation code and verify your email address.',
    },
    {
        question: 'How do I apply for a Mastercard U Card?',
        answer: 'Complete identity verification and the requirements shown in your account. Review the available product, fees and cardholder information before confirming.',
    },
    {
        question: 'Where can I see fees and limits?',
        answer: 'Fees, limits and product availability are shown in your account. Please review the current information before confirming an operation.',
    },
    {
        question: 'When will a top-up arrive?',
        answer: 'Timing depends on network confirmations and payment verification. Track the actual status in your account; no fixed arrival time is guaranteed.',
    },
    {
        question: 'Can I manage cards from my phone?',
        answer: 'Use the web account on your phone or computer to view cards and the available management controls.',
    },
] as const;
const showcases = [
    services[1],
    services[2],
    {
        title: 'Cardholder information',
        copy: 'Review and update cardholder information through the available card controls.',
    },
] as const;

const brand = computed(() => session.value?.tenant.name ?? 'Aperture Cards'),
    logo = computed(() => photoUrl(session.value?.tenant.logoUrl ?? null));
const active = ref(0),
    menuOpen = ref(false),
    expanded = ref<number[]>([]),
    currentShowcase = computed(() => showcases[active.value]);
const navigation = [
    { title: 'Home', id: 'home' },
    { title: 'Products', id: 'products' },
    { title: 'Card management', id: 'manage' },
    { title: 'FAQ', id: 'faq' },
];
function scroll(id: string) {
    menuOpen.value = false;
    uni.pageScrollTo({ selector: '#' + id, duration: 250 });
}
function toggle(index: number) {
    expanded.value = expanded.value.includes(index)
        ? expanded.value.filter((x) => x !== index)
        : [...expanded.value, index];
}
</script>
<template>
    <view id="home" class="marketing-home">
        <view class="marketing-notice">{{
            t('Protect your account. Never share your password or verification code.')
        }}</view>
        <view class="marketing-header"
            ><view class="marketing-brand" @click="scroll('home')"
                ><image v-if="logo" :src="logo" mode="aspectFit" :aria-label="brand" /><template
                    v-else
                    ><UiIcon name="aperture" :size="44" /><text>{{ brand }}</text></template
                ></view
            >
            <view class="marketing-desktop-nav"
                ><view
                    v-for="item in navigation"
                    :key="item.id"
                    class="marketing-link"
                    @click="scroll(item.id)"
                    >{{ t(item.title) }}</view
                ></view
            >
            <view class="marketing-header-actions"
                ><button class="marketing-login" @click="go('/login')">
                    {{ t('Log in') }}<UiIcon name="arrow-up-right" :size="15" /></button
                ><button class="marketing-pill" @click="go('/register')">
                    {{ t('Register') }}<UiIcon name="arrow-right" :size="15" /></button
                ><LanguagePicker icon-only /><button
                    class="marketing-menu"
                    :aria-label="t('Open navigation')"
                    @click="menuOpen = true"
                >
                    <UiIcon name="menu" /></button></view
        ></view>
        <view v-if="menuOpen" class="marketing-menu-shade" @click="menuOpen = false"
            ><view class="marketing-menu-panel" @click.stop
                ><button class="menu-close" :aria-label="t('Close menu')" @click="menuOpen = false">
                    <UiIcon name="x" /></button
                ><text class="h3">{{ brand }}</text
                ><button v-for="item in navigation" :key="item.id" @click="scroll(item.id)">
                    {{ t(item.title) }}</button
                ><button @click="go('/login')">{{ t('Log in') }}</button>
                <!-- #ifdef H5 -->
                <a href="#" @click.prevent="downloadApp">
                    {{ t('Download app') }}
                </a>
                <!-- #endif -->
            </view></view
        >
        <view class="marketing-hero"
            ><view class="marketing-hero-inner"
                ><view class="marketing-hero-copy"
                    ><text class="h1" :class="{ 'marketing-long-brand': brand.length > 12 }">{{
                        brand
                    }}</text
                    ><text class="p">{{ t('Global payments, in your hands.') }}</text></view
                ><view class="marketing-hero-art"
                    ><view class="marketing-hero-card"
                        ><image
                            class="marketing-hero-card-artwork"
                            :src="staticAsset('images/marketing/spec-pay-gold-world.png')"
                            mode="widthFix" /></view></view
                ><view class="marketing-hero-action"
                    ><button class="marketing-cta" @click="go('/login')">
                        {{ t('Explore your account') }}<UiIcon name="arrow-up-right" :size="18" />
                    </button>
                    <!-- #ifdef H5 -->
                    <a class="marketing-download" href="#" @click.prevent="downloadApp">
                        <UiIcon name="download" :size="18" />{{ t('Download app') }}
                    </a>
                    <!-- #endif -->
                </view></view
            ><view class="marketing-hero-footer"
                ><button
                    class="marketing-scroll"
                    :aria-label="t('Explore products')"
                    @click="scroll('products')"
                >
                    <UiIcon name="arrow-down" :size="20" /></button
                ><text class="marketing-art-note">{{ t('Card design illustration') }}</text></view
            ></view
        >
        <view class="growth-campaign growth-campaign-public"
            ><image
                class="growth-campaign-art"
                :src="staticAsset('images/marketing/growth/poster-1114.jpg')"
                mode="widthFix"
                :aria-label="t('Growth strategy announcement')" /><button
                class="growth-campaign-action"
                @click="scroll('products')"
            >
                {{ t('Explore products') }}<UiIcon name="arrow-up-right" :size="18" /></button
        ></view>
        <view class="marketing-service-strip"
            ><text class="p">{{ t('Your account. Your cards. One place.') }}</text
            ><view
                ><text v-for="s in services" :key="s.title"
                    ><UiIcon :name="s.icon" :size="30" />{{ t(s.title) }}</text
                ></view
            ></view
        >
        <view id="products" class="marketing-section marketing-muted"
            ><view class="marketing-section-heading"
                ><text class="h2"
                    >{{ t('A simpler way to')
                    }}<text class="heading-second">{{ t('manage your cards.') }}</text></text
                ><text class="p">{{
                    t(
                        'Explore the tools available in your account. Product access depends on eligibility and service availability.',
                    )
                }}</text></view
            ><view class="marketing-products marketing-container"
                ><view class="marketing-product-feature" @click="go('/cards')"
                    ><view class="marketing-feature-art"
                        ><MarketingCard :brand="brand" tone="dark" /></view
                    ><view
                        ><UiIcon name="credit-card" :size="38" /><text class="h3">{{
                            t('Mastercard U Card')
                        }}</text
                        ><text class="p">{{
                            t('Review available card products and apply from your account.')
                        }}</text
                        ><UiIcon name="arrow-up-right" /></view></view
                ><view
                    v-for="s in services"
                    :key="s.title"
                    class="marketing-product"
                    @click="go(s.href)"
                    ><UiIcon :name="s.icon" /><text class="h3">{{ t(s.title) }}</text
                    ><text class="p">{{ t(s.copy) }}</text
                    ><UiIcon name="arrow-up-right" class="marketing-product-arrow" /></view></view
        ></view>
        <view id="manage" class="marketing-section"
            ><view class="marketing-section-heading"
                ><text class="h2"
                    >{{ t('Your card.')
                    }}<text class="heading-second">{{ t('A clearer view.') }}</text></text
                ><text class="p">{{
                    t('Keep card details, activity and available controls together.')
                }}</text></view
            ><view class="marketing-showcase marketing-container"
                ><view class="marketing-showcase-copy"
                    ><text class="marketing-step-number">0{{ active + 1 }} / 03</text
                    ><text class="h3">{{ t(currentShowcase.title) }}</text
                    ><text class="p">{{ t(currentShowcase.copy) }}</text
                    ><view class="marketing-link" @click="go('/cards')"
                        >{{ t('View your cards') }}<UiIcon name="arrow-up-right" /></view
                    ><view class="marketing-showcase-tabs"
                        ><button
                            v-for="(s, index) in showcases"
                            :key="s.title"
                            :aria-label="t(s.title)"
                            :aria-pressed="active === index"
                            @click="active = index"
                        >
                            0{{ index + 1 }}
                        </button></view
                    ></view
                ><view class="marketing-showcase-art"
                    ><MarketingCard v-if="active === 0" :brand="brand" /><view
                        v-else
                        class="marketing-ui-art"
                        ><UiIcon :name="active === 1 ? 'list' : 'fingerprint'" /><view
                            v-for="i in 4"
                            :key="i"
                            class="marketing-ui-line"
                            :class="{ short: i === 4 }" /></view></view></view
        ></view>
        <view id="faq" class="marketing-section marketing-muted"
            ><view class="marketing-section-heading"
                ><text class="h2"
                    >{{ t('Frequently asked')
                    }}<text class="heading-second">{{ t('questions.') }}</text></text
                ></view
            ><view class="marketing-faq"
                ><view
                    v-for="(faq, index) in faqs"
                    :key="faq.question"
                    class="faq-item"
                    :class="{ open: expanded.includes(index) }"
                    ><button
                        class="faq-summary"
                        :aria-expanded="expanded.includes(index)"
                        @click="toggle(index)"
                    >
                        {{ t(faq.question) }}<UiIcon name="plus" /></button
                    ><text v-if="expanded.includes(index)" class="p">{{
                        t(faq.answer)
                    }}</text></view
                ></view
            ></view
        >
        <view class="marketing-footer"
            ><view class="marketing-container marketing-footer-grid"
                ><view
                    ><view class="marketing-brand" @click="scroll('home')"
                        ><image v-if="logo" :src="logo" mode="aspectFit" /><template v-else
                            ><UiIcon name="aperture" :size="44" /><text>{{ brand }}</text></template
                        ></view
                    ><text class="p">{{ t('Global payments, in your hands.') }}</text></view
                ><view
                    ><text class="h3">{{ t('Products') }}</text
                    ><text @click="go('/cards')">{{ t('Mastercard U Card') }}</text
                    ><text @click="go('/wallet')">{{ t('Wallet') }}</text>
                    <!-- #ifdef H5 -->
                    <a href="#" @click.prevent="downloadApp">{{ t('Download app') }}</a>
                    <!-- #endif --> </view
                ><view
                    ><text class="h3">{{ t('Help') }}</text
                    ><text @click="scroll('faq')">{{ t('FAQ') }}</text></view
                ><view
                    ><text class="h3">{{ t('Your account') }}</text
                    ><text @click="go('/login')">{{ t('Log in') }}</text
                    ><text @click="go('/register')">{{ t('Register') }}</text></view
                ><view
                    ><text class="h3">{{ t('About us') }}</text
                    ><text @click="go('/about/terms')">{{ t('Terms of service') }}</text
                    ><text @click="go('/about/privacy')">{{ t('Privacy policy') }}</text></view
                ></view
            ><view class="marketing-container marketing-footer-bottom"
                ><text class="p">© {{ new Date().getFullYear() }} {{ brand }}</text
                ><text class="p">{{
                    t(
                        'Service availability and processing results are shown in your account. An application is not a guarantee of approval.',
                    )
                }}</text></view
            ></view
        >
    </view>
</template>
<style src="../styles/marketing.css"></style>
