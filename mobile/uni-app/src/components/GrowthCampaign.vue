<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { onHide, onShow } from '@dcloudio/uni-app';
import { t } from '../lib/i18n';
import { go } from '../lib/navigation';
import UiIcon from './UiIcon.vue';
const active = ref(0),
    paused = ref(false),
    visible = ref(true),
    reduced = ref(false);
const slides = [
    {
        image: 'invitation-growth',
        title: 'Invite and earn',
        subtitle: 'Explore referral rewards',
        href: '/promotion',
    },
    {
        image: 'cards',
        title: 'Card services',
        subtitle: 'Manage your cards in one place',
        href: '/cards',
    },
    {
        image: 'wealth',
        title: 'Earn on savings',
        subtitle: 'Fixed terms, monthly interest',
        href: '/wealth',
    },
];
onMounted(() => {
    // #ifdef H5
    if (typeof window !== 'undefined')
        reduced.value = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // #endif
});
onHide(() => (visible.value = false));
onShow(() => (visible.value = true));
function choose(index: number) {
    active.value = index;
    paused.value = true;
}
</script>
<template>
    <view class="campaign" :class="{ dark: active === 0 }"
        ><swiper
            class="campaign-swiper"
            :current="active"
            :autoplay="!paused && visible && !reduced"
            :interval="6000"
            circular
            @change="active = $event.detail.current"
            ><swiper-item v-for="(slide, index) in slides" :key="slide.image"
                ><view class="campaign-slide" @click="go(slide.href)"
                    ><image
                        :src="'/static/images/marketing/growth/' + slide.image + '-banner.jpg'"
                        class="campaign-image"
                        mode="aspectFill"
                        :aria-label="t(slide.title)" /><view
                        v-if="index !== 0"
                        class="campaign-copy"
                        ><text class="campaign-title">{{ t(slide.title) }}</text
                        ><text>{{ t(slide.subtitle) }}</text
                        ><UiIcon
                            name="arrow-up-right"
                            :size="17" /></view></view></swiper-item></swiper
        ><view class="campaign-controls"
            ><button
                v-for="(slide, index) in slides"
                :key="slide.image"
                class="campaign-dot"
                :aria-label="t(slide.title)"
                @click="choose(index)"
            >
                <view :class="{ selected: active === index }" /></button></view
        ><button
            v-if="!reduced"
            class="campaign-play"
            :aria-label="t(paused ? 'Play banners' : 'Pause banners')"
            @click="paused = !paused"
        >
            {{ paused ? '▶' : 'Ⅱ' }}
        </button></view
    >
</template>
<style scoped>
.campaign {
    position: relative;
    aspect-ratio: 3/1;
    overflow: hidden;
    border-radius: 16px;
}
.campaign-swiper {
    width: 100%;
    height: 100%;
    position: absolute;
    inset: 0;
}
.campaign-slide {
    height: 100%;
    position: relative;
}
.campaign-image {
    width: 100%;
    height: 100%;
}
.campaign-copy {
    position: absolute;
    top: 18%;
    left: 7%;
    max-width: 48%;
    display: flex;
    flex-direction: column;
    gap: 6px;
    color: #183c31;
    font-size: 12px;
    line-height: 1.4;
}
.campaign-title {
    font-size: clamp(18px, 3.5cqw, 26px);
    font-weight: 600;
}
.campaign-controls {
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
}
.campaign-dot {
    padding: 0;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: none;
}
.campaign-dot > view {
    width: 5px;
    height: 5px;
    background: #173b3544;
    border-radius: 10px;
}
.campaign-dot > view.selected {
    width: 16px;
    background: #173b35;
}
.dark .campaign-dot > view {
    background: #fff6;
}
.dark .campaign-dot > view.selected {
    background: #fff;
}
.campaign-play {
    position: absolute;
    right: 3px;
    bottom: 0;
    width: 28px;
    height: 28px;
    background: none;
    color: #173b35;
    padding: 0;
    line-height: 28px;
    font-size: 12px;
}
.dark .campaign-play {
    color: white;
}
</style>
